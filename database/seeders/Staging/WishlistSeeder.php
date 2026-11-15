<?php

namespace Database\Seeders\Staging;

use App\Models\Wish;
use App\Models\WishlistManager;
use App\Models\WishOccasion;
use App\Wishlist\Occasions\NameDayCalendar;
use App\Wishlist\WishPriority;
use Illuminate\Support\Facades\DB;

/**
 * Wish lists for the six members (5 to 12 wishes each), occasions (family Christmas, one-off events, name days; birthdays
 * come from the members' birth dates), reservations, one open and one closed co-buy and a gift already given.
 *
 * Reservation privacy stays structural: reservations live in their own tables and no recipient-side code reads them.
 * The seeder only writes them (plain inserts, the arch tests keep the reservation models on the giving side), the way
 * ReservationService and CoBuyService would. No images (no network, no fetch):
 * the wishes keep image_status "none". Links use the reserved example.com domain and are never fetched.
 */
final class WishlistSeeder
{
    private StagingContext $ctx;

    /** @var array<string, int> "role:title" => wish id, used to attach reservations */
    private array $ids = [];

    /** @return array{wishes: int, reservations: int, contributions: int, occasions: int} */
    public function seed(StagingContext $ctx): array
    {
        $this->ctx = $ctx;
        $wishes = 0;
        foreach ($this->lists() as $role => $rows) {
            foreach ($rows as $i => $r) {
                $this->wish($role, $i, $r);
                $wishes++;
            }
        }

        [$reservations, $contributions] = $this->reservations();
        $occasions = $this->occasions();

        // The younger child's list is managed by a parent (recipient side: that parent never sees reservations on it).
        WishlistManager::query()->create([
            'owner_id' => $ctx->user('child2')->id, 'manager_id' => $ctx->user('parent2')->id, 'created_by_id' => $ctx->user('parent2')->id,
        ]);

        return ['wishes' => $wishes, 'reservations' => $reservations, 'contributions' => $contributions, 'occasions' => $occasions];
    }

    /**
     * Per role: [title, price in major units|null, priority key, notes|null, url slug|null, size notes|null, created days ago,
     * received days ago|null].
     *
     * @return array<string, list<array{0: string, 1: ?int, 2: string, 3: ?string, 4: ?string, 5: ?string, 6: int, 7: ?int}>>
     */
    private function lists(): array
    {
        return [
            'parent1' => [
                ['Bežecké hodinky s GPS', 249, 'top', 'Najradšej s meraním pulzu na zápästí.', 'bezecke-hodinky-gps', null, 120, null],
                ['Kvalitný nôž na chlieb', 39, 'love', null, 'nuz-na-chlieb', null, 400, null],
                ['Kniha: Dejiny Slovenska', 29, 'nice', 'Podľa možnosti vo vázanom vydaní.', null, null, 700, null],
                ['Softshellová bunda', 119, 'love', 'Farba tmavomodrá alebo sivá.', 'softshell-bunda', 'M / L', 60, null],
                ['Poukaz na masáž', 60, 'nice', null, null, null, 30, null],
                ['Vodotesný batoh na bicykel', 85, 'love', null, 'batoh-na-bicykel', null, 900, 330],
                ['Stolová hra Catan', 45, 'nice', 'Pre celú rodinu na víkendy.', 'catan', null, 1000, 640],
                ['Termoska 1 l z nerezu', 25, 'nice', null, null, null, 10, null],
            ],
            'child1' => [
                ['LEGO Technic Bugatti', 429, 'top', 'Veľký set, môže byť aj spoločný dar.', 'lego-technic-bugatti', null, 90, null],
                ['Bicykel horský 27,5"', 599, 'top', 'Rám veľkosť M, farba čierna. Môže byť spoločný dar.', 'horsky-bicykel', 'rám M', 150, null],
                ['Bluetooth slúchadlá', 79, 'love', null, 'bluetooth-sluchadla', null, 45, null],
                ['Kniha: Harry Potter a Kameň mudrcov', 18, 'nice', 'Ilustrované vydanie.', null, null, 800, 520],
                ['Športová taška', 35, 'love', null, null, null, 200, null],
                ['Skateboard', 89, 'love', 'Nie príliš ťažký, na mesto.', 'skateboard', null, 300, null],
                ['Herná myš', 49, 'nice', null, 'herna-mys', null, 20, null],
                ['Poukaz do kina na dvoch', 20, 'nice', null, null, null, 5, null],
                ['Mikina s kapucňou', 55, 'love', 'Veľkosť 170, sivá.', 'mikina', '170', 70, null],
            ],
            'grandparent' => [
                ['Ciepły sweter wełniany', 220, 'top', 'Rozmiar L, kolor granatowy lub zielony.', 'sweter-welniany', 'L', 100, null],
                ['Robot kuchenny', 899, 'love', 'Z funkcją gotowania na parze.', 'robot-kuchenny', null, 500, 12],
                ['Książka kucharska z przepisami regionalnymi', 79, 'nice', null, null, null, 350, null],
                ['Bilet do teatru dla dwojga', 160, 'love', 'Na komedię, najlepiej w niedzielę.', null, null, 40, null],
                ['Ciepłe kapcie', 59, 'nice', 'Numer 39.', null, '39', 15, null],
                ['Lampka do czytania', 99, 'nice', null, 'lampka-do-czytania', null, 650, 480],
            ],
            'guest' => [
                ['Kubek termiczny', 69, 'nice', null, null, null, 80, null],
                ['Zestaw herbat z całego świata', 85, 'love', null, 'zestaw-herbat', null, 60, null],
                ['Powerbank 20000 mAh', 149, 'love', null, 'powerbank', null, 25, null],
                ['Kalendarz ścienny z widokami gór', 49, 'nice', null, null, null, 12, null],
                ['Bilet do zoo', 78, 'nice', 'Dla dwóch osób.', null, null, 3, null],
            ],
            'parent2' => [
                ['Noise-cancelling headphones', 279, 'top', 'Over-ear, any colour except white.', 'noise-cancelling-headphones', null, 110, null],
                ['Cast iron casserole dish', 95, 'love', 'Oval, around 29 cm.', 'cast-iron-casserole', null, 380, null],
                ['Weekend city break voucher', 300, 'love', 'Anywhere in Europe by train.', null, null, 55, null],
                ['Yoga mat (extra thick)', 45, 'nice', null, 'yoga-mat', null, 420, 300],
                ['Hardback: The Thursday Murder Club', 16, 'nice', null, null, null, 700, null],
                ['Gardening gloves and tool set', 40, 'nice', null, 'gardening-set', null, 20, null],
                ['Smart bathroom scale', 59, 'nice', null, null, null, 150, null],
                ['Fountain pen, medium nib', 72, 'love', 'Blue ink cartridges please.', null, null, 8, null],
            ],
            'child2' => [
                ['LEGO Harry Potter Hogwarts Castle', 139, 'top', 'The small castle set is fine too.', 'lego-hogwarts', null, 70, null],
                ['Football boots size 4', 55, 'love', 'Moulded studs, for grass.', 'football-boots', '4', 130, null],
                ['Wireless gaming headset', 69, 'love', null, 'gaming-headset', null, 35, null],
                ['Bike helmet (blue)', 35, 'love', 'Adjustable, size 52 to 56.', 'bike-helmet', '52-56', 200, null],
                ['Sketchbook and drawing pencils', 28, 'nice', null, null, null, 15, null],
                ['Nintendo game: Mario Kart', 49, 'love', null, 'mario-kart', null, 45, null],
                ['Pair of swimming goggles', 12, 'nice', null, null, null, 600, 420],
                ['Rucksack for school trips', 40, 'nice', null, null, null, 10, null],
                ['Chess set (wooden)', 30, 'nice', 'To play with grandma.', 'wooden-chess', null, 5, null],
                ['Space encyclopedia', 22, 'nice', null, null, null, 90, null],
                ['Skipping rope', 8, 'nice', null, null, null, 300, null],
                ['Trampoline for the garden', 249, 'top', 'A big wish, maybe a family present.', 'trampoline', null, 160, null],
            ],
        ];
    }

    /** @param  array{0: string, 1: ?int, 2: string, 3: ?string, 4: ?string, 5: ?string, 6: int, 7: ?int}  $r */
    private function wish(string $role, int $i, array $r): void
    {
        $owner = $this->ctx->user($role);
        $currency = ['parent1' => 'EUR', 'child1' => 'EUR', 'grandparent' => 'PLN', 'guest' => 'PLN', 'parent2' => 'GBP', 'child2' => 'GBP'][$role];

        $wish = new Wish;
        $wish->forceFill([
            'owner_id' => $owner->id,
            'created_by_id' => $owner->id,
            'title' => $r[0],
            'notes' => $r[3],
            'price_minor' => $r[1] === null ? null : $r[1] * 100,
            'price_currency' => $currency,
            'size_notes' => $r[5],
            'priority' => WishPriority::fromKey($r[2]),
            'received_at' => $r[7] === null ? null : $this->ctx->at(-$r[7], 15),
            'created_at' => $this->ctx->at(-$r[6], 10),
            'updated_at' => $this->ctx->at(-$r[6], 10),
        ])->save();
        $this->ids["{$role}:{$r[0]}"] = $wish->id;

        if ($r[4] !== null) {
            DB::table('wish_urls')->insert([
                'wish_id' => $wish->id, 'url' => 'https://example.com/demo/'.$r[4], 'label' => $i % 3 === 0 ? 'Obchod (demo)' : null, 'position' => 0,
            ]);
        }
    }

    /**
     * [owner role, wish title, organizer role, co-buy state, contributions role => minor|null (organizer first)].
     *
     * @return array{0: int, 1: int} reservations, contributions
     */
    private function reservations(): array
    {
        $plan = [
            ['parent1', 'Bežecké hodinky s GPS', 'parent2', 'open', ['parent2' => 15000, 'child1' => 2000, 'grandparent' => 3000]],
            ['parent1', 'Softshellová bunda', 'child1', 'none', ['child1' => null]],
            ['child1', 'LEGO Technic Bugatti', 'parent1', 'none', ['parent1' => null]],
            ['child1', 'Bicykel horský 27,5"', 'parent1', 'open', ['parent1' => 30000, 'grandparent' => 15000, 'parent2' => 8000]],
            ['child1', 'Bluetooth slúchadlá', 'grandparent', 'none', ['grandparent' => null]],
            ['grandparent', 'Ciepły sweter wełniany', 'parent2', 'none', ['parent2' => null]],
            ['grandparent', 'Robot kuchenny', 'parent1', 'closed', ['parent1' => 50000, 'parent2' => 25000, 'child1' => 14900]],
            ['guest', 'Powerbank 20000 mAh', 'child2', 'none', ['child2' => null]],
            ['parent2', 'Noise-cancelling headphones', 'parent1', 'none', ['parent1' => null]],
            ['parent2', 'Fountain pen, medium nib', 'child2', 'none', ['child2' => null]],
            ['child2', 'LEGO Harry Potter Hogwarts Castle', 'parent1', 'none', ['parent1' => null]],
            ['child2', 'Trampoline for the garden', 'parent1', 'closed', ['parent1' => 12000, 'grandparent' => 8000, 'child1' => 4900]],
            ['child2', 'Football boots size 4', 'grandparent', 'none', ['grandparent' => null]],
        ];

        $reservations = 0;
        $contributions = 0;
        foreach ($plan as $n => [$owner, $title, $organizer, $state, $shares]) {
            $at = $this->ctx->at(-(3 + $n * 2), 18, $n * 4);
            $reservationId = DB::table('wish_reservations')->insertGetId([
                'wish_id' => $this->ids["{$owner}:{$title}"], 'user_id' => $this->ctx->user($organizer)->id,
                'cobuy_state' => $state, 'created_at' => $at, 'updated_at' => $at,
            ]);
            $reservations++;

            foreach ($shares as $role => $minor) {
                DB::table('wish_contributions')->insert([
                    'reservation_id' => $reservationId, 'user_id' => $this->ctx->user($role)->id,
                    'amount_minor' => $minor, 'created_at' => $at, 'updated_at' => $at,
                ]);
                $contributions++;
            }
        }

        return [$reservations, $contributions];
    }

    /** @return int occasions created (name days, family events); birthdays come from users.birth_date */
    private function occasions(): int
    {
        $n = 0;
        $family = $this->ctx->user('parent1')->id;
        $custom = [
            ['Vianoce', 12, 24, null],
            ['Silvester', 12, 31, null],
            ['Výročie svadby rodičov', (int) $this->ctx->today->addDays(48)->format('n'), (int) $this->ctx->today->addDays(48)->format('j'), null],
            ['Rodinný zraz u starých rodičov', (int) $this->ctx->today->addDays(70)->format('n'), (int) $this->ctx->today->addDays(70)->format('j'), (int) $this->ctx->today->addDays(70)->format('Y')],
            ['Promócia (minulý rok)', (int) $this->ctx->today->subDays(40)->format('n'), (int) $this->ctx->today->subDays(40)->format('j'), (int) $this->ctx->today->subDays(40)->format('Y')],
        ];
        foreach ($custom as [$title, $month, $day, $year]) {
            $o = new WishOccasion;
            $o->forceFill(['owner_id' => null, 'kind' => WishOccasion::CUSTOM, 'title' => $title, 'month' => $month, 'day' => $day,
                'year' => $year, 'created_by_id' => $family])->save();
            $n++;
        }

        // One personal custom occasion of a member (their own graduation, say).
        $o = new WishOccasion;
        $o->forceFill(['owner_id' => $this->ctx->user('child1')->id, 'kind' => WishOccasion::CUSTOM, 'title' => 'Koniec školského roka',
            'month' => 6, 'day' => 30, 'created_by_id' => $this->ctx->user('child1')->id])->save();
        $n++;

        // Name days from the vendored calendar (real dates for real calendar names).
        foreach ([['parent1', 'sk', 'Peter'], ['child1', 'sk', 'Dominika'], ['grandparent', 'pl', 'Kazimierz'], ['guest', 'pl', 'Katarzyna']] as [$role, $calendar, $name]) {
            $hit = collect(NameDayCalendar::search($calendar, $name, 20))->firstWhere('name', $name);
            if ($hit === null) {
                continue;
            }
            $owner = $this->ctx->user($role);
            $o = new WishOccasion;
            $o->forceFill(['owner_id' => $owner->id, 'kind' => WishOccasion::NAMEDAY, 'title' => null, 'calendar' => $calendar,
                'name' => $name, 'month' => $hit['month'], 'day' => $hit['day'], 'created_by_id' => $owner->id])->save();
            $n++;
        }

        return $n;
    }
}
