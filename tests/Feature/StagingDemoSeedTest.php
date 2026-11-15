<?php

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Finance\Queries\AccountQuery;
use App\Finance\Queries\BudgetQuery;
use App\Finance\Queries\RecurringQuery;
use App\Finance\Queries\TransactionQuery;
use App\Finance\Viewer as FinanceViewer;
use App\Medical\Ai\ExtractionValidator;
use App\Medical\Documents\PdfRasterizer;
use App\Medical\Provenance;
use App\Medical\Queries\AiReviewQuery;
use App\Medical\Queries\DocumentQuery;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Queries\VaccinationQuery;
use App\Medical\Specialty;
use App\Medical\Viewer as MedicalViewer;
use App\Models\FinanceTransaction;
use App\Models\MedicalAiExtraction;
use App\Models\MedicalSubject;
use App\Models\Task;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishReservation;
use App\Tasks\TaskStatus;
use App\Tasks\TaskVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->originalEnv = app()->environment();
    app()['env'] = 'staging';
    Storage::fake('medical');
});

afterEach(function () {
    app()['env'] = $this->originalEnv;
});

function demoSeed(): void
{
    test()->artisan('staging:seed --force')->assertExitCode(0);
}

function demoUser(string $email): User
{
    return User::where('email', "{$email}@example.test")->firstOrFail();
}

function demoToday(): string
{
    return CarbonImmutable::now((string) config('family.timezone'))->format('Y-m-d');
}

function demoSubject(string $name): MedicalSubject
{
    return MedicalSubject::query()->where('name', 'like', "{$name}%")->firstOrFail();
}

/** Every table that the seed fills or the purge touches, except the append-only ai_calls and audit_logs. */
function demoCounts(): array
{
    $tables = ['tasks', 'task_members', 'wishes', 'wish_urls', 'wish_reservations', 'wish_contributions', 'wish_occasions', 'wishlist_managers',
        'finance_accounts', 'finance_account_members', 'finance_transactions', 'finance_recurring', 'finance_recurring_matches', 'finance_budgets',
        'finance_import_profiles', 'finance_import_batches', 'finance_import_rows', 'medical_subjects', 'medical_subject_members', 'medical_visits',
        'medical_medications', 'medical_allergies', 'medical_vaccinations', 'medical_contacts', 'medical_documents', 'medical_ai_extractions',
        'medical_ai_decisions', 'module_permissions', 'users'];

    return collect($tables)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
}

it('fills every module within the expected ranges and measures the run', function () {
    $started = microtime(true);
    demoSeed();
    $seconds = microtime(true) - $started;

    $c = demoCounts();
    expect($c['users'])->toBe(7)
        ->and($c['tasks'])->toBeBetween(60, 120)
        ->and($c['wishes'])->toBeBetween(30, 60)
        ->and($c['wish_occasions'])->toBeBetween(6, 14)
        ->and($c['finance_accounts'])->toBeBetween(3, 5)
        ->and($c['finance_transactions'])->toBeBetween(1500, 4000)
        ->and($c['finance_recurring'])->toBeBetween(4, 10)
        ->and($c['finance_budgets'])->toBeBetween(4, 12)
        ->and($c['finance_import_batches'])->toBeBetween(1, 3)
        ->and($c['medical_subjects'])->toBeBetween(4, 5)
        ->and($c['medical_visits'])->toBeBetween(40, 80)
        ->and($c['medical_documents'])->toBe(6)
        ->and($c['medical_ai_extractions'])->toBe(3)
        ->and($seconds)->toBeLessThan(60);
});

it('seeds tasks over three years with every status, owner and recurrence the UI needs', function () {
    demoSeed();
    $today = demoToday();

    $done = Task::where('status', TaskStatus::Done->value);
    expect($done->count())->toBeGreaterThan(30)
        ->and(Task::where('status', TaskStatus::Done->value)->whereNull('completed_at')->count())->toBe(0)
        ->and(Task::where('status', TaskStatus::InProgress->value)->count())->toBeGreaterThanOrEqual(4)
        ->and(Task::whereNull('completed_at')->where('due_date', '<', $today)->count())->toBeGreaterThanOrEqual(5)
        ->and(Task::whereNull('completed_at')->where('due_date', $today)->count())->toBeGreaterThanOrEqual(3)
        ->and(Task::whereNull('completed_at')->whereBetween('due_date', [$today, CarbonImmutable::parse($today)->addDays(7)->format('Y-m-d')])->count())->toBeGreaterThanOrEqual(5)
        ->and(Task::where('completed_at', '<', now()->subYears(2))->count())->toBeGreaterThan(0)
        ->and(Task::whereNotNull('remind_at')->count())->toBeGreaterThanOrEqual(5)
        ->and(Task::whereNotNull('assignee_id')->distinct()->count('assignee_id'))->toBeGreaterThanOrEqual(5)
        ->and(Task::whereRaw('length(title) > 150')->count())->toBeGreaterThanOrEqual(2)
        ->and(Task::whereRaw('length(notes) > 300')->count())->toBeGreaterThanOrEqual(2)
        ->and(Task::where('visibility', TaskVisibility::Selected->value)->count())->toBeGreaterThanOrEqual(3);

    // six series, every one with exactly one open occurrence and several closed ones; daily, weekly, monthly, every 2nd week
    $series = Task::whereNotNull('series_id')->get()->groupBy('series_id');
    expect($series)->toHaveCount(6);
    foreach ($series as $rows) {
        expect($rows->whereNull('completed_at')->count())->toBe(1)
            ->and($rows->whereNotNull('completed_at')->count())->toBeGreaterThanOrEqual(3);
    }
    expect(Task::whereNotNull('series_id')->pluck('recurrence_rule')->unique()->values()->all())->toContain(
        'FREQ=DAILY;INTERVAL=1', 'FREQ=WEEKLY;INTERVAL=1;BYDAY=TU,FR', 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=1', 'FREQ=WEEKLY;INTERVAL=2', 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=-1',
    );
    // an overdue recurring occurrence exists
    expect(Task::whereNotNull('series_id')->whereNull('completed_at')->where('due_date', '<', $today)->count())->toBe(1);

    // personal tasks are private to their creator
    $personal = Task::where('visibility', TaskVisibility::Personal->value)->first();
    $other = User::where('id', '<>', $personal->creator_id)->first();
    expect(Task::visibleTo($other)->whereKey($personal->id)->exists())->toBeFalse()
        ->and(Task::visibleTo(User::find($personal->creator_id))->whereKey($personal->id)->exists())->toBeTrue();
});

it('seeds five to twelve wishes per member, occasions, and keeps reservations away from the owner', function () {
    demoSeed();

    foreach (['sk_sk.1', 'sk_sk.2', 'pl_pl.3', 'pl_pl.4', 'en_gb.5', 'en_gb.6'] as $email) {
        expect(Wish::where('owner_id', demoUser($email)->id)->count())->toBeBetween(5, 12);
    }
    // the Guest preset has no Wishlist grant; the seed gives View so the Guest's list is readable (and cannot be reserved from)
    expect(app(AccessGate::class)->level(demoUser('pl_pl.4'), Module::Wishlist))->toBe(AccessLevel::View);
    expect(Wish::where('owner_id', demoUser('admin')->id)->count())->toBe(0)
        ->and(DB::table('wish_urls')->count())->toBeGreaterThanOrEqual(10)
        ->and(DB::table('wish_urls')->where('url', 'not like', 'https://example.com/%')->count())->toBe(0)
        ->and(Wish::where('image_status', '<>', 'none')->count())->toBe(0)
        ->and(Wish::whereNotNull('price_minor')->count())->toBeGreaterThan(30)
        ->and(DB::table('wish_occasions')->where('kind', 'nameday')->count())->toBeGreaterThanOrEqual(3)
        ->and(DB::table('wish_occasions')->where('kind', 'custom')->whereNull('owner_id')->count())->toBeGreaterThanOrEqual(4)
        ->and(User::whereNotNull('birth_date')->count())->toBe(6);

    // one wish already given (received, reservation kept) and open and closed co-buys with contributions
    $given = Wish::whereNotNull('received_at')->whereIn('id', WishReservation::query()->select('wish_id'))->first();
    expect($given)->not->toBeNull();
    expect(DB::table('wish_reservations')->where('cobuy_state', 'open')->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('wish_reservations')->where('cobuy_state', 'closed')->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('wish_contributions')->count())->toBeGreaterThan(DB::table('wish_reservations')->count())
        // nobody reserves a wish on their own list
        ->and(DB::table('wish_reservations as r')->join('wishes as w', 'w.id', '=', 'r.wish_id')->whereColumn('r.user_id', 'w.owner_id')->count())->toBe(0);

    // The owner sees the list but no trace of who reserves what; another member sees the reservation.
    $bugatti = Wish::where('title', 'LEGO Technic Bugatti')->firstOrFail();
    $owner = User::findOrFail($bugatti->owner_id);
    $buyer = User::findOrFail(WishReservation::where('wish_id', $bugatti->id)->value('user_id'));
    expect($buyer->id)->not->toBe($owner->id);

    $props = fn (User $viewer, int $ownerId) => test()->actingAs($viewer)->get('/wishlist?member='.$ownerId)->assertOk()->viewData('page')['props'];
    // the shared props also carry the interface translations (they mention "reserved"), so look at the list and the occasions only
    $ownerProps = $props($owner, $owner->id);
    $ownerView = json_encode([$ownerProps['list'], $ownerProps['occasions'] ?? null], JSON_UNESCAPED_UNICODE);
    expect($ownerView)->toContain('LEGO Technic Bugatti')
        ->and($ownerView)->not->toContain('organizer')
        ->and($ownerView)->not->toContain('cobuy')
        ->and($ownerView)->not->toContain('reserv')
        ->and($ownerView)->not->toContain('contribut');

    $giverView = $props($buyer, $owner->id);
    $mine = collect($giverView['list']['wishes'])->firstWhere('title', 'LEGO Technic Bugatti');
    expect($mine['reservation']['organizer']['name'])->toBe($buyer->name)
        ->and($mine['reservation']['is_mine'])->toBeTrue();
});

it('keeps finance private per account and exact in minor units', function () {
    demoSeed();
    $parent = demoUser('sk_sk.1');
    $child = demoUser('sk_sk.2');
    $spouse = demoUser('en_gb.5');

    $accounts = app(AccountQuery::class);
    $names = fn (User $u) => $accounts->visible(FinanceViewer::for($u))->pluck('name')->sort()->values()->all();
    expect($names($parent))->toHaveCount(5)
        ->and($names($spouse))->toHaveCount(4)
        ->and($names($child))->toHaveCount(2)
        ->and($names($child))->not->toContain('Súkromný účet rodiča', 'Úspory', 'Kreditná karta');
    // admin, Guest and the second Child have no finance access at all
    expect(fn () => FinanceViewer::for(demoUser('admin')))->toThrow(HttpException::class);

    // exact integers, signs follow the check constraints, transfers come in balanced pairs
    expect(DB::table('finance_transactions')->where('currency', '<>', 'EUR')->count())->toBe(0)
        ->and(DB::table('finance_transactions')->where('kind', 'transfer')->selectRaw('transfer_id, sum(amount_minor) as s')->groupBy('transfer_id')->havingRaw('sum(amount_minor) <> 0')->get())->toHaveCount(0)
        ->and(DB::table('finance_transactions')->where('kind', 'transfer')->selectRaw('transfer_id')->groupBy('transfer_id')->havingRaw('count(*) <> 2')->get())->toHaveCount(0)
        ->and(DB::table('finance_transactions')->where('booked_on', '>', demoToday())->count())->toBe(0)
        ->and(DB::table('finance_transactions')->where('booked_on', '<', CarbonImmutable::now()->subYears(3)->format('Y-m-d'))->count())->toBeGreaterThan(100);

    // a transfer into a hidden account: the child sees only the joint leg, and it counts as a plain expense, never as a transfer
    $joint = DB::table('finance_accounts')->where('name', 'Spoločný účet (bežný)')->value('id');
    $leg = FinanceTransaction::query()->where('account_id', $joint)->where('description', 'Vreckové')->where('amount_minor', '<', 0)->firstOrFail();
    $tx = app(TransactionQuery::class);
    expect($tx->visibleCounterpart(FinanceViewer::for($parent), $leg))->not->toBeNull()
        ->and($tx->visibleCounterpart(FinanceViewer::for($child), $leg))->toBeNull();
    // the parent sees the same leg as a transfer with its counterpart account
    $parentRow = collect($tx->page(FinanceViewer::for($parent), $joint, substr($leg->booked_on->format('Y-m-d'), 0, 7), ['q' => '', 'type' => 'all', 'category' => 'all', 'source' => 'all'], 1)['rows'])->firstWhere('id', $leg->id);
    expect($parentRow->counterpart_account_id)->not->toBeNull();
    $month = substr((string) $leg->booked_on->format('Y-m-d'), 0, 7);
    $rows = $tx->page(FinanceViewer::for($child), $joint, $month, ['q' => '', 'type' => 'all', 'category' => 'all', 'source' => 'all'], 1)['rows'];
    $row = collect($rows)->firstWhere('id', $leg->id);
    expect($row)->not->toBeNull()
        ->and($row->counterpart_account_id)->toBeNull()
        ->and(json_encode($row))->not->toContain(DB::table('finance_accounts')->where('name', 'Súkromný účet rodiča')->value('id'))
        ->and(json_encode($row))->not->toContain('Súkromný')
        ->and(json_encode($rows))->not->toContain('Úspory');
    expect(collect($rows)->pluck('id'))->not->toContain(FinanceTransaction::where('transfer_id', $leg->transfer_id)->where('id', '<>', $leg->id)->value('id'));

    // recurring items: paid, paid-differently and one overdue bill; budgets for this and last month, one overspent
    $viewer = FinanceViewer::for($parent);
    $recurring = app(RecurringQuery::class);
    $statuses = collect($recurring->occurrences($viewer, CarbonImmutable::parse(demoToday())->subDays(40)->format('Y-m-d'), CarbonImmutable::parse(demoToday())->addDays(40)->format('Y-m-d')))->pluck('status');
    $allTime = collect($recurring->occurrences($viewer, CarbonImmutable::parse(demoToday())->subYears(4)->format('Y-m-d'), demoToday()))->pluck('status');
    expect(DB::table('finance_recurring_matches')->count())->toBeGreaterThan(100)
        ->and($statuses)->toContain('overdue', 'upcoming', 'paid')
        ->and($allTime)->toContain('paid_differently');
    $budgets = app(BudgetQuery::class);
    $thisMonth = substr(demoToday(), 0, 7);
    $lastMonth = CarbonImmutable::parse(demoToday())->startOfMonth()->subMonthNoOverflow()->format('Y-m');
    foreach ([$thisMonth, $lastMonth] as $m) {
        expect(collect($budgets->forMonth($viewer, $m, null)['EUR'] ?? []))->not->toBeEmpty();
    }
    expect(collect($budgets->forMonth($viewer, $thisMonth, null)['EUR'])->where('state', 'over')->count())->toBeGreaterThanOrEqual(1);
});

it('keeps health private per person and seeds a full record for each', function () {
    demoSeed();
    $parent = demoUser('sk_sk.1');
    $child = demoUser('sk_sk.2');
    $grandparent = demoUser('pl_pl.3');

    $visible = fn (User $u) => app(SubjectQuery::class)->visible(MedicalViewer::for($u))->pluck('name')->all();
    expect($visible($parent))->toHaveCount(5)
        ->and($visible(demoUser('en_gb.5')))->toHaveCount(5)
        ->and($visible($child))->toHaveCount(2)
        ->and($visible($child))->not->toContain(demoSubject('Marek Skrytý')->name)
        ->and($visible($grandparent))->toContain(demoSubject('Peter Kováč')->name, $grandparent->name)
        ->and($visible($grandparent))->not->toContain(demoSubject('Marek Skrytý')->name);
    // the Guest, the second Child and the admin hold no Health grant, so they reach no person at all
    foreach (['pl_pl.4', 'en_gb.6', 'admin'] as $email) {
        expect(app(AccessGate::class)->level(demoUser($email), Module::Health))->toBe(AccessLevel::None);
    }

    // linked, unlinked, child, grandparent
    expect(MedicalSubject::whereNotNull('user_id')->count())->toBe(2)
        ->and(MedicalSubject::where('user_id', $parent->id)->exists())->toBeTrue()
        ->and(MedicalSubject::where('user_id', $grandparent->id)->exists())->toBeTrue()
        ->and(MedicalSubject::where('ai_consent', true)->count())->toBe(2)
        ->and(MedicalSubject::whereNull('blood_type')->count())->toBe(0)
        ->and(MedicalSubject::whereNull('birth_date')->count())->toBe(0);
    $ages = MedicalSubject::all()->map(fn ($s) => (int) $s->birth_date->age)->sort()->values()->all();
    expect($ages[0])->toBeLessThan(12)->and(end($ages))->toBeGreaterThan(70);

    foreach (MedicalSubject::all() as $s) {
        expect(DB::table('medical_visits')->where('subject_id', $s->id)->count())->toBeGreaterThanOrEqual(7)
            ->and(DB::table('medical_medications')->where('subject_id', $s->id)->count())->toBeGreaterThanOrEqual(2)
            ->and(DB::table('medical_allergies')->where('subject_id', $s->id)->count())->toBeGreaterThanOrEqual(1)
            ->and(DB::table('medical_vaccinations')->where('subject_id', $s->id)->count())->toBeGreaterThanOrEqual(2)
            ->and(DB::table('medical_contacts')->where('subject_id', $s->id)->count())->toBeGreaterThanOrEqual(1);
    }
    expect(DB::table('medical_visits')->whereNotIn('specialty_key', array_map(fn ($c) => $c->value, Specialty::cases()))->count())->toBe(0)
        ->and(DB::table('medical_visits')->distinct()->count('specialty_key'))->toBeGreaterThanOrEqual(15)
        ->and(DB::table('medical_medications')->whereNotNull('ended_on')->count())->toBeGreaterThanOrEqual(3)
        ->and(DB::table('medical_medications')->whereNull('ended_on')->count())->toBeGreaterThanOrEqual(8)
        ->and(DB::table('medical_allergies')->distinct()->pluck('severity')->sort()->values()->all())->toContain('mild', 'moderate', 'severe', 'unknown')
        ->and(DB::table('medical_visits')->where('visited_on', '<', CarbonImmutable::now()->subYears(3)->format('Y-m-d'))->count())->toBeGreaterThan(5);

    // a vaccination due in 14 days and an overdue one
    $vaccinations = app(VaccinationQuery::class);
    $viewer = MedicalViewer::for($parent);
    $a = $vaccinations->forSubject($viewer, demoSubject($parent->name), demoToday());
    $c = $vaccinations->forSubject($viewer, demoSubject('Miško'), demoToday());
    expect($a->firstWhere('vaccine', 'Tetanus (preočkovanie)')->dueInDays)->toBe(14)
        ->and($c->firstWhere('vaccine', 'MMR (2. dávka)')->status)->toBe('overdue');
});

it('stores six synthetic documents that can be read back, with a two-page PDF', function () {
    demoSeed();

    $docs = DB::table('medical_documents')->get();
    expect($docs)->toHaveCount(6)
        ->and($docs->where('kind', 'pdf'))->toHaveCount(3)
        ->and($docs->where('kind', 'image'))->toHaveCount(3);
    $disk = Storage::disk('medical');
    foreach ($docs as $doc) {
        expect($disk->exists($doc->path))->toBeTrue()
            ->and(hash('sha256', $disk->get($doc->path)))->toBe($doc->sha256)
            ->and($doc->size_bytes)->toBeLessThan(200000);
        if ($doc->kind === 'image') {
            expect($disk->exists($doc->thumb_path))->toBeTrue();
        }
    }
    foreach ($docs->where('kind', 'pdf') as $pdf) {
        expect(app(PdfRasterizer::class)->pageCount($disk->path($pdf->path)))->toBe(2);
    }
    // the files of one document are reachable through the real query class for an Edit viewer, not for the child
    $doc = $docs->first(fn ($d) => str_contains($d->title, 'Recept'));
    $hidden = MedicalSubject::findOrFail($doc->subject_id);
    expect(app(DocumentQuery::class)->find(MedicalViewer::for(demoUser('sk_sk.1')), $hidden, $doc->id))->not->toBeNull()
        ->and(app(DocumentQuery::class)->find(MedicalViewer::for(demoUser('sk_sk.2')), $hidden, $doc->id))->toBeNull();
});

it('seeds AI proposals for review without any provider call, with accepted, verified and pending entries', function () {
    demoSeed();
    $parent = demoUser('sk_sk.1');
    $viewer = MedicalViewer::for($parent);

    $ready = MedicalAiExtraction::where('status', 'ready')->get();
    expect($ready)->toHaveCount(2)
        ->and(MedicalAiExtraction::where('status', 'failed')->count())->toBe(1)
        ->and(MedicalAiExtraction::where('status', 'failed')->value('failure_code'))->toBe('invalid_output');
    foreach ($ready as $extraction) {
        $outcome = app(ExtractionValidator::class)->validate(json_encode($extraction->payload, JSON_UNESCAPED_UNICODE));
        expect($outcome->ok)->toBeTrue()
            ->and($outcome->entryCount)->toBe($extraction->entry_count)
            ->and($extraction->model)->toBe('default')
            ->and($extraction->agent)->toBe('codex');
    }

    // content-free call rows: succeeded x2, failed x1
    $calls = DB::table('ai_calls')->get();
    expect($calls)->toHaveCount(3)
        ->and($calls->where('status', 'succeeded'))->toHaveCount(2)
        ->and($calls->where('status', 'failed'))->toHaveCount(1)
        ->and($calls->pluck('agent')->unique()->all())->toBe(['codex'])
        ->and($calls->where('status', 'succeeded')->pluck('model')->unique()->all())->toBe(['default']);

    // the first document: visit accepted (AI, Unverified), allergy accepted and verified, medication rejected, vaccination pending
    $first = $ready->first(fn ($e) => DB::table('medical_ai_decisions')->where('extraction_id', $e->id)->exists());
    $decisions = DB::table('medical_ai_decisions')->where('extraction_id', $first->id)->get()->keyBy('entry_key');
    expect($decisions->keys()->sort()->values()->all())->toBe(['allergy-0', 'medication-0', 'visit-0'])
        ->and($decisions['visit-0']->decision)->toBe('accepted')
        ->and($decisions['allergy-0']->decision)->toBe('accepted')
        ->and($decisions['medication-0']->decision)->toBe('rejected');
    $visit = DB::table('medical_visits')->where('id', $decisions['visit-0']->record_id)->first();
    $allergy = DB::table('medical_allergies')->where('id', $decisions['allergy-0']->record_id)->first();
    expect(Provenance::isUnverified($visit->source, $visit->verified_at))->toBeTrue()
        ->and($allergy->source)->toBe('ai')
        ->and($allergy->verified_at)->not->toBeNull()
        ->and($allergy->severity)->toBe('moderate')
        ->and(DB::table('medical_documents')->where('id', $first->document_id)->value('visit_id'))->toBe($visit->id);

    // the review screen loads for both proposals and the other (no consent) people have none
    foreach ($ready as $extraction) {
        $subject = MedicalSubject::findOrFail($extraction->subject_id);
        $review = app(AiReviewQuery::class)->review($viewer, $subject, $extraction->document_id, null);
        expect($review)->not->toBeNull()->and($review)->not->toHaveKey('redirect');
    }
    // only people with AI consent have a proposal
    expect(MedicalAiExtraction::whereIn('subject_id', MedicalSubject::where('ai_consent', false)->select('id'))->count())->toBe(0);
});

it('writes no audit rows by hand', function () {
    demoSeed();

    expect(DB::table('audit_logs')->count())->toBe(0);
});

it('is idempotent: a second run leaves the same rows, no orphans and an audit that passes after each run', function () {
    demoSeed();
    $this->artisan('staging:audit')->assertExitCode(0);
    $first = demoCounts();
    $files = count(Storage::disk('medical')->allFiles());
    $calls = DB::table('ai_calls')->count();

    $started = microtime(true);
    demoSeed();
    $seconds = microtime(true) - $started;
    $this->artisan('staging:audit')->assertExitCode(0);

    expect(demoCounts())->toBe($first)
        ->and(count(Storage::disk('medical')->allFiles()))->toBe($files)
        ->and($seconds)->toBeLessThan(60)
        // append-only call history grows by the three demo calls per run; the audit accepts their dangling ids
        ->and(DB::table('ai_calls')->count())->toBe($calls + 3);

    // no orphan: every owner of a demo row is one of the seven current users, and nothing points to a deleted person
    $ids = User::pluck('id')->all();
    expect(DB::table('tasks')->whereNotIn('creator_id', $ids)->count())->toBe(0)
        ->and(DB::table('wishes')->whereNotIn('owner_id', $ids)->count())->toBe(0)
        ->and(DB::table('finance_transactions')->whereNotIn('created_by', $ids)->count())->toBe(0)
        ->and(DB::table('finance_accounts')->whereNotIn('created_by', $ids)->count())->toBe(0)
        ->and(DB::table('medical_subjects')->whereNotIn('created_by', $ids)->count())->toBe(0)
        ->and(DB::table('medical_ai_extractions')->whereNotIn('started_by', $ids)->count())->toBe(0)
        ->and(DB::table('medical_documents')->whereNotIn('subject_id', DB::table('medical_subjects')->select('id'))->count())->toBe(0)
        ->and(DB::table('medical_ai_decisions')->whereNotIn('extraction_id', DB::table('medical_ai_extractions')->select('id'))->count())->toBe(0)
        ->and(DB::table('finance_recurring_matches')->whereNotIn('transaction_id', DB::table('finance_transactions')->select('id'))->count())->toBe(0);
});

it('is deterministic: the same day gives the same shape', function () {
    demoSeed();
    $shape = fn () => [
        'names' => User::orderBy('email')->pluck('name')->all(),
        'amounts' => DB::table('finance_transactions')->orderBy('booked_on')->orderBy('description')->orderBy('amount_minor')->pluck('amount_minor')->all(),
        'titles' => Task::orderBy('title')->orderBy('due_date')->pluck('title')->all(),
    ];
    $first = $shape();

    demoSeed();

    expect($shape())->toBe($first);
});

it('still flags real data after the demo seed (audit rules are unchanged)', function () {
    demoSeed();
    $real = User::factory()->create(['email' => 'real@gmail.com']);
    MedicalSubject::factory()->create(['created_by' => $real->id]);

    $this->artisan('staging:audit')
        ->expectsOutputToContain('non-synthetic users')
        ->expectsOutputToContain('non-synthetic medical_subjects')
        ->assertExitCode(1);
});
