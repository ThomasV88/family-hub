<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceRecurring;
use App\Models\FinanceRecurringMatch;
use App\Models\FinanceTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FinancePrivacy;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->travelTo(CarbonImmutable::parse('2027-03-10 09:00', config('family.timezone')));
});

function fhRcInertia($test, User $user, string $url): TestResponse
{
    return $test->actingAs($user)->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => FinancePrivacy::inertiaVersion()]);
}

function fhRcSetup(): array
{
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $account = FinanceAccount::factory()->withMembers($user)->create(['name' => 'Main']);

    return [$user, $account];
}

function fhRcBody(FinanceAccount $account, array $override = []): array
{
    return array_merge([
        'name' => 'Rent',
        'amount' => '450,00',
        'account_id' => $account->id,
        'starts_on' => '2027-03-05',
        'recurrence' => ['kind' => 'monthly_day'],
        'window_days' => 5,
        'tolerance' => '',
    ], $override);
}

function fhRcItem(FinanceAccount $account, array $attrs = []): FinanceRecurring
{
    return FinanceRecurring::factory()->onAccount($account)->startingOn('2027-03-05')->create($attrs + ['name' => 'Rent']);
}

it('serves the recurring page with the props contract, strings for money and a calendar day per day', function () {
    [$user, $account] = fhRcSetup();
    $item = fhRcItem($account, ['expected_amount_minor' => 45000, 'text_hint' => 'landlord', 'tolerance_minor' => 150]);

    $props = fhRcInertia($this, $user, '/finance/recurring?month=2027-03')->assertOk()->json('props');

    expect($props['recurring']['items'])->toHaveCount(1)
        ->and($props['recurring']['items'][0])->toBe([
            'id' => $item->id, 'name' => 'Rent', 'expectedMinor' => '45000', 'currency' => 'EUR', 'accountId' => $account->id,
            'categoryId' => null, 'rule' => 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=5', 'pattern' => ['kind' => 'monthly_day', 'interval' => 1, 'monthDay' => 5],
            'startsOn' => '2027-03-05', 'endsOn' => null, 'textHint' => 'landlord', 'toleranceMinor' => '150', 'windowDays' => 5,
        ])
        ->and($props['recurring']['calendar'])->toHaveCount(31)
        ->and($props['recurring']['calendar'][4]['date'])->toBe('2027-03-05')
        ->and($props['recurring']['calendar'][4]['occurrences'][0]['status'])->toBe('overdue')
        ->and($props['recurring']['calendar'][4]['occurrences'][0]['expectedMinor'])->toBe('45000')
        ->and($props['recurring']['calendar'][0]['occurrences'])->toBe([])
        ->and(array_column($props['recurring']['overdue'], 'key'))->toBe([$item->id.'.2027-03-05'])
        ->and(array_column($props['recurring']['next30'], 'dueDate'))->toBe(['2027-04-05'])
        ->and($props['recurring']['stillToPay'])->toHaveCount(1)
        ->and($props['recurring']['stillToPay'][0])->toMatchArray(['currency' => 'EUR', 'sumMinor' => '45000', 'count' => 1, 'overdueCount' => 1])
        ->and($props['panelBill'])->toBeNull()
        ->and($props['panelRecurring'])->toBeNull()
        ->and($props['dayPanel'])->toBeNull()
        ->and($props['candidates'])->toBeNull();
});

it('fills the bill, recurring and day panels', function () {
    [$user, $account] = fhRcSetup();
    $item = fhRcItem($account, ['expected_amount_minor' => 45000]);
    $tx = FinanceTransaction::factory()->onAccount($account)->expense(46000)->create(['booked_on' => '2027-03-06', 'description' => 'Landlord']);
    FinanceRecurringMatch::factory()->between($item, '2027-03-05', $tx)->manual()->create();

    $bill = fhRcInertia($this, $user, '/finance/recurring?bill='.$item->id.'.2027-03-05')->json('props.panelBill');
    $rec = fhRcInertia($this, $user, '/finance/recurring?rec='.$item->id)->json('props.panelRecurring');
    $new = fhRcInertia($this, $user, '/finance/recurring?rec=new')->json('props.panelRecurring');
    $day = fhRcInertia($this, $user, '/finance/recurring?day=2027-03-05')->json('props.dayPanel');
    $empty = fhRcInertia($this, $user, '/finance/recurring?day=2027-03-06')->json('props.dayPanel');

    expect($bill['status'])->toBe('paid_differently')
        ->and($bill['match'])->toBe([
            'transactionId' => $tx->id, 'description' => 'Landlord', 'bookedOn' => '2027-03-06', 'amountMinor' => '-46000',
            'accountName' => 'Main', 'differenceMinor' => '1000', 'matchedBy' => 'manual',
        ])
        ->and($rec['id'])->toBe($item->id)
        ->and($new)->toBe(['mode' => 'new'])
        ->and($day['date'])->toBe('2027-03-05')
        ->and($day['occurrences'])->toHaveCount(1)
        ->and($empty)->toBe(['date' => '2027-03-06', 'occurrences' => []]);
});

it('answers hidden, unknown and malformed panel values with one identical 403 page, and new only for editors', function () {
    [$user, $account] = fhRcSetup();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $hidden = fhRcItem(FinanceAccount::factory()->withMembers($other)->create());
    $own = fhRcItem($account);
    $viewOnly = User::factory()->withGrant(Module::Finance, AccessLevel::View)->create();

    $bodies = [];
    foreach (['rec='.$hidden->id, 'rec='.Str::uuid7(), 'rec=zzz', 'bill='.$hidden->id.'.2027-03-05', 'bill='.Str::uuid7().'.2027-03-05',
        'bill='.$own->id.'.2027-03-06', 'bill=zzz', 'day=2027-02-30', 'day=zzz', 'account='.Str::uuid7()] as $q) {
        $response = $this->actingAs($user)->get('/finance/recurring?month=2027-03&'.$q);
        $response->assertForbidden();
        $body = preg_replace('/(name="csrf-token"\s+content=")[^"]*/', '$1', $response->getContent());
        $bodies[] = preg_replace('/&quot;url&quot;:&quot;.*?&quot;/', '', preg_replace('/"url":"[^"]*"/', '', $body));
    }
    expect(array_unique($bodies))->toHaveCount(1);

    fhRcInertia($this, $viewOnly, '/finance/recurring?rec=new')->assertForbidden();
    fhRcInertia($this, $viewOnly, '/finance/recurring')->assertOk();
});

it('creates a recurring expense with exact minor units and the effective date at the first due date', function () {
    [$user, $account] = fhRcSetup();
    $category = FinanceCategory::query()->where('kind', 'expense')->firstOrFail();

    $this->actingAs($user)->postJson('/finance/recurring', fhRcBody($account, ['category_id' => $category->id, 'text_hint' => 'Landlord', 'ends_on' => '2028-03-05']))
        ->assertStatus(303)->assertSessionHas('toast', ['key' => 'finance.toast.rec_saved']);

    $row = FinanceRecurring::query()->firstOrFail();
    expect($row->expected_amount_minor)->toBe(45000)
        ->and($row->tolerance_minor)->toBe(0)
        ->and($row->window_days)->toBe(5)
        ->and($row->recurrence_rule)->toBe('FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=5')
        ->and($row->starts_on->format('Y-m-d'))->toBe('2027-03-05')
        ->and($row->schedule_effective_from->format('Y-m-d'))->toBe('2027-03-05')
        ->and($row->ends_on->format('Y-m-d'))->toBe('2028-03-05')
        ->and($row->category_id)->toBe($category->id)
        ->and($row->text_hint)->toBe('Landlord')
        ->and($row->created_by)->toBe($user->id);
});

it('reads tolerance zero strings as zero, anything else as money, and ignores a posted mode', function () {
    [$user, $account] = fhRcSetup();
    foreach (['', '0', '0,00', '0.00'] as $zero) {
        $this->actingAs($user)->postJson('/finance/recurring', fhRcBody($account, ['tolerance' => $zero, 'recurrence_mode' => 'completion']))->assertStatus(303);
    }
    $this->actingAs($user)->postJson('/finance/recurring', fhRcBody($account, ['tolerance' => '2,50']))->assertStatus(303);

    expect(FinanceRecurring::query()->orderBy('tolerance_minor')->pluck('tolerance_minor')->all())->toBe([0, 0, 0, 0, 250]);
    $this->actingAs($user)->postJson('/finance/recurring', fhRcBody($account, ['tolerance' => 'abc']))->assertJsonValidationErrors(['tolerance']);
});

it('validates the fields and answers a hidden account like a nonexistent one', function () {
    [$user, $account] = fhRcSetup();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $hidden = FinanceAccount::factory()->withMembers($other)->create();
    $income = FinanceCategory::query()->where('default_key', 'salary')->firstOrFail();
    $post = fn (array $override) => $this->actingAs($user)->postJson('/finance/recurring', fhRcBody($account, $override));

    $hiddenBody = $this->actingAs($user)->postJson('/finance/recurring', fhRcBody($account, ['account_id' => $hidden->id]));
    $randomBody = $this->actingAs($user)->postJson('/finance/recurring', fhRcBody($account, ['account_id' => (string) Str::uuid7()]));
    $hiddenBody->assertStatus(422)->assertJsonValidationErrors(['account_id' => 'finance.error.account']);
    expect($hiddenBody->getContent())->toBe($randomBody->getContent());

    $post(['category_id' => $income->id])->assertJsonValidationErrors(['category_id' => 'finance.error.category']);
    $post(['ends_on' => '2027-03-04'])->assertJsonValidationErrors(['ends_on' => 'finance.rec.err_ends']);
    $post(['name' => ''])->assertJsonValidationErrors(['name' => 'finance.rec.err_name']);
    $post(['window_days' => 4])->assertStatus(422)->assertJsonValidationErrors(['window_days']);
    $post(['amount' => '0'])->assertJsonValidationErrors(['amount' => 'finance.error.amount']);
    $post(['starts_on' => '2027-02-30'])->assertJsonValidationErrors(['starts_on' => 'finance.error.date']);
    $post(['recurrence' => ['kind' => 'yearly']])->assertStatus(422)->assertJsonValidationErrors(['recurrence']);
    $post(['recurrence' => ['kind' => 'weekly']])->assertStatus(422)->assertJsonValidationErrors(['recurrence.weekdays']);
    $post(['recurrence' => ['kind' => 'custom', 'unit' => 'month', 'interval' => '100']])->assertStatus(422);
    $post(['recurrence' => ['kind' => 'monthly_day', 'rrule' => 'FREQ=DAILY']])->assertStatus(422);
    $post(['recurrence' => null])->assertStatus(422);
    expect(FinanceRecurring::query()->count())->toBe(0);
});

it('restarts the schedule today when the amount, rule or first date change, not for other edits', function () {
    [$user, $account] = fhRcSetup();
    $item = fhRcItem($account, ['expected_amount_minor' => 45000]);
    $patch = fn (array $data) => $this->actingAs($user)->patchJson('/finance/recurring/'.$item->id, $data)->assertStatus(303);
    $effective = fn () => $item->fresh()->schedule_effective_from->format('Y-m-d');

    $patch(['name' => 'Flat rent', 'text_hint' => 'x', 'window_days' => 7, 'tolerance' => '1', 'ends_on' => '2028-01-01']);
    expect($effective())->toBe('2027-03-05')
        ->and($item->fresh()->name)->toBe('Flat rent')
        ->and($item->fresh()->tolerance_minor)->toBe(100);

    $patch(['amount' => '450,00']);
    expect($effective())->toBe('2027-03-05');

    $patch(['amount' => '460,00']);
    expect($effective())->toBe('2027-03-10')->and($item->fresh()->expected_amount_minor)->toBe(46000);

    $this->travelTo(CarbonImmutable::parse('2027-03-12 09:00', config('family.timezone')));
    $patch(['recurrence' => ['kind' => 'weekly', 'weekdays' => ['MO', 'TU']]]);
    expect($effective())->toBe('2027-03-12')->and($item->fresh()->recurrence_rule)->toBe('FREQ=WEEKLY;INTERVAL=1;BYDAY=MO,TU');

    $this->travelTo(CarbonImmutable::parse('2027-03-14 09:00', config('family.timezone')));
    $patch(['starts_on' => '2027-03-01']);
    expect($effective())->toBe('2027-03-14');
});

it('keeps the account and kind immutable and answers any value with identical 422 bodies', function () {
    [$user, $account] = fhRcSetup();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $hidden = FinanceAccount::factory()->withMembers($other)->create();
    $item = fhRcItem($account);

    $bodies = [];
    foreach ([['account_id' => $account->id], ['account_id' => $hidden->id], ['account_id' => (string) Str::uuid7()], ['account_id' => null], ['kind' => 'income'], ['kind' => 'x']] as $data) {
        $response = $this->actingAs($user)->patchJson('/finance/recurring/'.$item->id, $data + ['name' => 'Moved']);
        $key = array_key_first($data);
        $response->assertStatus(422)->assertJsonValidationErrors([$key => 'finance.error.immutable']);
        $bodies[$key][] = $response->getContent();
    }

    expect(array_map(fn ($b) => count(array_unique(array_slice($b, 0, 4))), ['account_id' => $bodies['account_id']]))->toBe(['account_id' => 1])
        ->and($item->fresh()->name)->toBe('Rent')
        ->and($item->fresh()->account_id)->toBe($account->id);
});

it('answers PATCH and DELETE on a hidden item like a random id and forbids view-only users', function () {
    [$user, $account] = fhRcSetup();
    $other = User::factory()->withPreset(Preset::Parent)->create();
    $hidden = fhRcItem(FinanceAccount::factory()->withMembers($other)->create());
    $own = fhRcItem($account);
    $viewOnly = User::factory()->withGrant(Module::Finance, AccessLevel::View)->create();

    $a = $this->actingAs($user)->patchJson('/finance/recurring/'.$hidden->id, ['name' => 'x']);
    $b = $this->actingAs($user)->patchJson('/finance/recurring/'.Str::uuid7(), ['name' => 'x']);
    $a->assertNotFound();
    expect($a->json('message'))->toBe($b->json('message'))->and($b->status())->toBe(404);
    $c = $this->actingAs($user)->deleteJson('/finance/recurring/'.$hidden->id);
    $d = $this->actingAs($user)->deleteJson('/finance/recurring/'.Str::uuid7());
    $c->assertNotFound();
    expect($c->json('message'))->toBe($d->json('message'))->and($d->status())->toBe(404)
        ->and($hidden->fresh()->name)->toBe('Rent');

    $this->actingAs($viewOnly)->postJson('/finance/recurring', [])->assertForbidden();
    $this->actingAs($viewOnly)->patchJson('/finance/recurring/'.$own->id, ['name' => 'x'])->assertForbidden();
    $this->actingAs($viewOnly)->deleteJson('/finance/recurring/'.$own->id)->assertForbidden();
});

it('deletes the item and its match rows but keeps the recorded transactions', function () {
    [$user, $account] = fhRcSetup();
    $item = fhRcItem($account);
    $tx = FinanceTransaction::factory()->onAccount($account)->expense(45000)->create(['booked_on' => '2027-03-05', 'description' => 'Landlord']);
    FinanceRecurringMatch::factory()->between($item, '2027-03-05', $tx)->create();

    $this->actingAs($user)->deleteJson('/finance/recurring/'.$item->id)
        ->assertStatus(303)->assertSessionHas('toast', ['key' => 'finance.toast.rec_deleted']);

    expect(FinanceRecurring::query()->count())->toBe(0)
        ->and(FinanceRecurringMatch::query()->count())->toBe(0)
        ->and(FinanceTransaction::query()->count())->toBe(1);
});
