<?php

namespace Database\Seeders\Staging;

use App\Models\Task;
use App\Tasks\TaskStatus;
use App\Tasks\TaskVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * About 70 task rows over three years: finished one-off tasks, open, overdue, due today and soon, personal and
 * selected-members tasks, reminders, a few long titles and notes for layout checks, and six recurring series whose
 * past occurrences are closed (done) with exactly one open occurrence per series (the database allows only one).
 * Saved through the model so the reminder observer computes remind_at as in the real app.
 */
final class TasksSeeder
{
    private StagingContext $ctx;

    public function seed(StagingContext $ctx): int
    {
        $this->ctx = $ctx;
        $n = 0;

        foreach ($this->done() as $t) {
            $this->save($t + ['status' => 'done']);
            $n++;
        }
        foreach ($this->open() as $t) {
            $this->save($t);
            $n++;
        }
        foreach ($this->series() as $s) {
            $n += $this->saveSeries($s);
        }

        return $n;
    }

    /** @return list<array<string, mixed>> finished one-off tasks, oldest first (about three years back) */
    private function done(): array
    {
        $rows = [
            ['Objednať termín technickej kontroly auta', -1050, 'parent1', 'parent1'],
            ['Zaplatiť poplatok za krúžky', -985, 'parent2', 'parent2'],
            ['Kúpiť darček pre starú mamu', -930, 'child1', 'parent1'],
            ['Naplánować wakacje w Tatrach i zarezerwować pensjonat', -870, 'parent1', 'parent2', 'Dwa pokoje, blisko szlaku. Termin w lipcu.'],
            ['Book the MOT for the family car', -810, 'parent2', 'parent2'],
            ['Vymeniť zimné pneumatiky', -760, 'parent1', 'parent1'],
            ['Prihlásiť dieťa do škôlky na nový školský rok', -650, 'parent1', 'parent1', 'Treba priniesť potvrdenie od pediatra.'],
            ['Sort out the broadband contract renewal', -600, 'parent2', 'parent2'],
            ['Zabaliť vianočné darčeky', -530, 'parent1', 'child1'],
            ['Renew the household insurance', -490, 'parent2', 'parent2', 'Compare at least three quotes first.'],
            ['Zaniesť sa na zubnú prehliadku', -450, 'parent1', 'parent1'],
            ['Posadiť paradajky a papriky na záhrade', -370, 'grandparent', 'child1'],
            ['Pick up the school uniform order', -330, 'parent2', 'child2'],
            ['Opraviť pokazený bicykel', -290, 'child1', 'parent1'],
            ['Vybaviť povolenie na parkovanie', -215, 'parent1', 'parent1'],
            ['Book the summer camp for the kids', -180, 'parent2', 'parent2', 'Camp starts the first week of July.'],
            ['Upratať garáž a vyniesť starý nábytok', -150, 'parent1', 'child1'],
            ['Zamówić nowe drzwi do piwnicy', -120, 'grandparent', 'parent1'],
            ['Renew the passport photos', -70, 'parent2', 'parent2'],
            ['Zapłacić podatek od nieruchomości', -48, 'grandparent', 'grandparent'],
            ['Vymeniť filter v kuchynskej digestore', -33, 'parent1', 'child1'],
            ['Return the library books', -17, 'child2', 'child2'],
            ['Objednať drevo na zimu', -8, 'parent1', 'parent1'],
        ];

        $out = [];
        foreach ($rows as $i => $r) {
            $out[] = [
                'title' => $r[0], 'due' => $r[1], 'creator' => $r[2], 'assignee' => $r[3], 'notes' => $r[4] ?? null,
                'visibility' => 'family', 'created' => $r[1] - 5 - ($i % 6),
                'completed_at' => $this->ctx->at($r[1] + ($i % 3) - 1, 9 + ($i * 7) % 11, ($i * 13) % 60),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function open(): array
    {
        $long = 'Pripraviť podklady pre ročné vyúčtovanie energií, poistenia a daňové priznanie vrátane všetkých potvrdení od zamestnávateľov, bánk a poisťovní a skontrolovať, či sedia sumy';
        $longNotes = "Zoznam, čo treba pripraviť:\n- potvrdenia o príjmoch od oboch rodičov\n- ročné vyúčtovanie plynu a elektriny\n- potvrdenie o platení poistného\n- doklady k školským poplatkom\n\nPozor: termín odovzdania je pevný a oneskorenie znamená pokutu. Dokumenty zoskenovať a uložiť do spoločného priečinka, originály nechať v šanóne vo vrchnej zásuvke.";

        return [
            // overdue
            ['title' => 'Zavolať inštalatérovi kvôli kvapkajúcemu kohútiku', 'due' => -3, 'creator' => 'parent1', 'assignee' => 'parent1', 'visibility' => 'family', 'created' => -12],
            ['title' => 'Odesłać formularz do ubezpieczalni', 'due' => -9, 'creator' => 'grandparent', 'assignee' => 'parent2', 'visibility' => 'family', 'status' => 'in_progress', 'created' => -20, 'notes' => 'Brakuje jeszcze podpisu na trzeciej stronie.'],
            ['title' => 'Renew the car tax online', 'due' => -21, 'creator' => 'parent2', 'assignee' => 'parent2', 'visibility' => 'family', 'created' => -40],
            ['title' => 'Vrátiť požičanú vŕtačku susedovi', 'due' => -45, 'creator' => 'child1', 'assignee' => 'child1', 'visibility' => 'family', 'created' => -60],
            ['title' => 'Uporządkować stare dokumenty w szafie', 'due' => -120, 'creator' => 'grandparent', 'assignee' => 'grandparent', 'visibility' => 'family', 'created' => -150],
            // due today and soon
            ['title' => 'Objednať potraviny na víkend', 'due' => 0, 'time' => '09:00', 'remind' => 60, 'creator' => 'parent1', 'assignee' => 'parent1', 'visibility' => 'family', 'created' => -2],
            ['title' => 'Parent-teacher meeting at school', 'due' => 0, 'time' => '17:30', 'remind' => 1440, 'creator' => 'parent2', 'assignee' => 'parent2', 'visibility' => 'family', 'created' => -9, 'notes' => 'Room 14, second floor. Bring the report card.'],
            ['title' => 'Odebrać paczkę z punktu odbioru', 'due' => 0, 'creator' => 'grandparent', 'assignee' => 'child1', 'visibility' => 'family', 'created' => -1],
            ['title' => 'Pick up the dry cleaning', 'due' => 1, 'time' => '16:00', 'remind' => 15, 'creator' => 'parent2', 'assignee' => 'child2', 'visibility' => 'family', 'created' => -3],
            ['title' => 'Zaniesť auto na servis', 'due' => 2, 'time' => '07:45', 'remind' => 60, 'creator' => 'parent1', 'assignee' => 'parent1', 'visibility' => 'family', 'created' => -6],
            ['title' => 'Kupić prezent na imieniny', 'due' => 4, 'remind' => 1440, 'creator' => 'grandparent', 'assignee' => 'grandparent', 'visibility' => 'family', 'created' => -4],
            ['title' => 'Naplánovať rodinný víkend v horách', 'due' => 6, 'creator' => 'parent1', 'assignee' => null, 'visibility' => 'family', 'created' => -10, 'status' => 'in_progress', 'notes' => 'Hlasovanie o termíne beží v rodinnej skupine.'],
            ['title' => 'Book a table for the anniversary dinner', 'due' => 9, 'time' => '19:00', 'remind' => 1440, 'creator' => 'parent2', 'assignee' => 'parent2', 'visibility' => 'family', 'created' => -5],
            ['title' => 'Vyzdvihnúť okuliare u optika', 'due' => 14, 'creator' => 'parent1', 'assignee' => 'child1', 'visibility' => 'family', 'created' => -2],
            ['title' => 'Zamówić prezenty świąteczne', 'due' => 21, 'creator' => 'grandparent', 'assignee' => 'parent2', 'visibility' => 'family', 'created' => -7, 'remind' => 1440],
            ['title' => 'Zbaliť zimné veci do kufra pred cestou', 'due' => 45, 'creator' => 'parent1', 'assignee' => null, 'visibility' => 'family', 'created' => -1],
            ['title' => 'Plan the Christmas menu', 'due' => 30, 'creator' => 'parent2', 'assignee' => 'parent2', 'visibility' => 'family', 'created' => -3, 'status' => 'in_progress'],
            ['title' => 'Prihlásiť syna na lyžiarsky kurz', 'due' => 60, 'creator' => 'parent1', 'assignee' => 'parent1', 'visibility' => 'family', 'created' => -1],
            ['title' => 'Schedule the boiler service before winter', 'due' => 90, 'creator' => 'parent2', 'assignee' => null, 'visibility' => 'family', 'created' => -1],
            // no due date
            ['title' => 'Vymyslieť nápady na darčeky pre celú rodinu', 'due' => null, 'creator' => 'parent1', 'assignee' => null, 'visibility' => 'family', 'created' => -30, 'status' => 'in_progress'],
            ['title' => 'Przejrzeć stare zdjęcia i zrobić album', 'due' => null, 'creator' => 'grandparent', 'assignee' => 'child1', 'visibility' => 'family', 'created' => -200],
            ['title' => 'Clear out the loft', 'due' => null, 'creator' => 'parent2', 'assignee' => null, 'visibility' => 'family', 'created' => -75],
            ['title' => 'Nájsť nový recept na nedeľný obed', 'due' => null, 'creator' => 'child1', 'assignee' => null, 'visibility' => 'family', 'created' => -4],
            // long title and notes (layout checks)
            ['title' => $long, 'due' => 12, 'creator' => 'parent1', 'assignee' => 'parent1', 'visibility' => 'family', 'created' => -3, 'notes' => $longNotes, 'status' => 'in_progress'],
            ['title' => 'Naplánovať veľké jarné upratovanie domu vrátane okien, záhrady, garáže, pivnice, podkrovia a všetkých skríň, rozdeliť úlohy medzi všetkých členov rodiny', 'due' => 75, 'creator' => 'parent2', 'assignee' => null, 'visibility' => 'family', 'created' => -2, 'notes' => str_repeat('Poznámka na testovanie dlhého textu v karte aj v detaile úlohy. ', 12)],
            // personal (private to the creator)
            ['title' => 'Kúpiť prekvapenie pre manželku k výročiu', 'due' => 25, 'creator' => 'parent1', 'assignee' => 'parent1', 'visibility' => 'personal', 'created' => -3, 'remind' => 1440, 'notes' => 'Nesmie sa to dozvedieť!'],
            ['title' => 'Surprise party for dad: secret guest list', 'due' => 40, 'creator' => 'parent2', 'assignee' => 'parent2', 'visibility' => 'personal', 'created' => -6],
            ['title' => 'Dokončiť projekt z dejepisu', 'due' => 5, 'time' => '20:00', 'remind' => 60, 'creator' => 'child1', 'assignee' => 'child1', 'visibility' => 'personal', 'created' => -8, 'status' => 'in_progress'],
            ['title' => 'Przygotować się do egzaminu z angielskiego', 'due' => 18, 'creator' => 'child2', 'assignee' => 'child2', 'visibility' => 'personal', 'created' => -10],
            ['title' => 'Zapisać się do lekarza (prywatnie)', 'due' => -2, 'creator' => 'grandparent', 'assignee' => 'grandparent', 'visibility' => 'personal', 'created' => -14],
            ['title' => 'Check my own savings plan', 'due' => 10, 'creator' => 'parent1', 'assignee' => 'parent1', 'visibility' => 'personal', 'created' => -2],
            // selected members only
            ['title' => 'Organizovať oslavu pre starých rodičov', 'due' => 33, 'creator' => 'parent1', 'assignee' => 'parent1', 'visibility' => 'selected', 'members' => ['parent2', 'child1'], 'created' => -5, 'notes' => 'Len pre organizátorov, ostatní majú byť prekvapení.'],
            ['title' => 'Plan the grandparents visit to the UK', 'due' => 50, 'creator' => 'parent2', 'assignee' => 'parent2', 'visibility' => 'selected', 'members' => ['parent1', 'grandparent'], 'created' => -9],
            ['title' => 'Wspólny zakup prezentu dla wujka', 'due' => 15, 'creator' => 'grandparent', 'assignee' => null, 'visibility' => 'selected', 'members' => ['parent1', 'parent2', 'child2'], 'created' => -4, 'remind' => 0],
        ];
    }

    /**
     * Recurring series: title, rule, mode, creator, assignee, the open occurrence date, how many closed occurrences precede it.
     *
     * @return list<array<string, mixed>>
     */
    private function series(): array
    {
        $today = $this->ctx->today;
        $tuesday = $today->next('Tuesday');
        $lastOfMonth = $today->endOfMonth();
        $firstNextMonth = $today->addMonthNoOverflow()->startOfMonth();

        return [
            ['title' => 'Vyvenčiť psa ráno', 'rule' => 'FREQ=DAILY;INTERVAL=1', 'mode' => 'schedule', 'creator' => 'parent1', 'assignee' => 'child1',
                'open' => $today, 'closed' => 5, 'step' => fn ($d) => $d->subDay(), 'remind' => null, 'time' => '07:00'],
            ['title' => 'Vynesenie smetí', 'rule' => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=TU,FR', 'mode' => 'schedule', 'creator' => 'parent2', 'assignee' => 'child2',
                'open' => $today->next('Friday'), 'closed' => 6, 'step' => fn ($d) => $this->prevTueFri($d), 'remind' => 1440],
            ['title' => 'Zaplatiť nájom a energie', 'rule' => 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=1', 'mode' => 'schedule', 'creator' => 'parent1', 'assignee' => 'parent1',
                'open' => $firstNextMonth, 'closed' => 8, 'step' => fn ($d) => $d->subMonthNoOverflow(), 'remind' => 1440],
            ['title' => 'Kontrola kontajnerov na bio odpad (every 2nd Tuesday)', 'rule' => 'FREQ=WEEKLY;INTERVAL=2', 'mode' => 'completion', 'creator' => 'grandparent', 'assignee' => 'grandparent',
                'open' => $tuesday, 'closed' => 4, 'step' => fn ($d) => $d->subWeeks(2), 'remind' => null],
            ['title' => 'Odpisać stav elektromera (posledný deň v mesiaci)', 'rule' => 'FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=-1', 'mode' => 'schedule', 'creator' => 'parent2', 'assignee' => 'parent2',
                'open' => $lastOfMonth, 'closed' => 4, 'step' => fn ($d) => $d->subMonthNoOverflow()->endOfMonth(), 'remind' => 0],
            // an open occurrence that is already overdue
            ['title' => 'Umyć samochód', 'rule' => 'FREQ=WEEKLY;INTERVAL=1;BYDAY=SA', 'mode' => 'completion', 'creator' => 'parent1', 'assignee' => null,
                'open' => $today->previous('Saturday'), 'closed' => 3, 'step' => fn ($d) => $d->subWeek(), 'remind' => null],
        ];
    }

    private function prevTueFri(CarbonImmutable $d): CarbonImmutable
    {
        return $d->dayOfWeek === 5 ? $d->previous('Tuesday') : $d->previous('Friday');
    }

    /** @param  array<string, mixed>  $s */
    private function saveSeries(array $s): int
    {
        $seriesId = (string) Str::uuid();
        $dates = [];
        $d = $s['open'];
        for ($i = 0; $i < $s['closed']; $i++) {
            $d = $s['step']($d);
            $dates[] = $d;
        }
        $dates = array_reverse($dates);

        $i = 0;
        foreach ($dates as $date) {
            $this->saveRow([
                'title' => $s['title'], 'series_id' => $seriesId, 'recurrence_rule' => $s['rule'], 'recurrence_mode' => $s['mode'],
                'creator' => $s['creator'], 'assignee' => $s['assignee'], 'visibility' => 'family', 'status' => 'done',
                'due_date' => $date->format('Y-m-d'), 'time' => $s['time'] ?? null, 'remind' => $s['remind'],
                'created_at' => $date->subDays(2)->setTime(12, 0),
                'completed_at' => $date->setTime(10 + ($i % 8), ($i * 11) % 60),
                'completed_by' => $i % 3 === 0 ? ($s['assignee'] ?? $s['creator']) : ($i % 3 === 1 ? $s['creator'] : ($s['assignee'] ?? 'parent2')),
            ]);
            $i++;
        }

        $this->saveRow([
            'title' => $s['title'], 'series_id' => $seriesId, 'recurrence_rule' => $s['rule'], 'recurrence_mode' => $s['mode'],
            'creator' => $s['creator'], 'assignee' => $s['assignee'], 'visibility' => 'family', 'status' => 'todo',
            'due_date' => $s['open']->format('Y-m-d'), 'time' => $s['time'] ?? null, 'remind' => $s['remind'],
            'created_at' => $dates[0]->subDays(2)->setTime(12, 0),
        ]);

        return count($dates) + 1;
    }

    /** @param  array<string, mixed>  $t  one-off task as defined in done() / open() */
    private function save(array $t): void
    {
        $this->saveRow([
            'title' => $t['title'], 'notes' => $t['notes'] ?? null, 'creator' => $t['creator'], 'assignee' => $t['assignee'] ?? null,
            'visibility' => $t['visibility'], 'status' => $t['status'] ?? 'todo', 'members' => $t['members'] ?? [],
            'due_date' => isset($t['due']) ? $this->ctx->day($t['due']) : null, 'time' => $t['time'] ?? null,
            'remind' => $t['remind'] ?? null, 'created_at' => $this->ctx->at($t['created'] ?? -1, 11, 30),
            'completed_at' => $t['completed_at'] ?? null,
            'completed_by' => isset($t['completed_at']) ? ($t['assignee'] ?? $t['creator']) : null,
        ]);
    }

    /** @param  array<string, mixed>  $r */
    private function saveRow(array $r): void
    {
        $task = new Task;
        $task->forceFill([
            'title' => $r['title'],
            'notes' => $r['notes'] ?? null,
            'status' => TaskStatus::from($r['status']),
            'visibility' => TaskVisibility::from($r['visibility']),
            'creator_id' => $this->ctx->user($r['creator'])->id,
            'assignee_id' => isset($r['assignee']) ? $this->ctx->user($r['assignee'])->id : null,
            'due_date' => $r['due_date'] ?? null,
            'due_time' => $r['time'] ?? null,
            'timezone' => (string) config('family.timezone'),
            'remind_before_minutes' => $r['remind'] ?? null,
            'series_id' => $r['series_id'] ?? null,
            'recurrence_rule' => $r['recurrence_rule'] ?? null,
            'recurrence_mode' => $r['recurrence_mode'] ?? null,
            'completed_at' => $r['completed_at'] ?? null,
            'completed_by' => isset($r['completed_by']) ? $this->ctx->user($r['completed_by'])->id : null,
            'created_at' => $r['created_at'],
            'updated_at' => $r['completed_at'] ?? $r['created_at'],
        ])->save();

        if (($r['members'] ?? []) !== []) {
            $task->members()->sync(array_map(fn (string $role) => $this->ctx->user($role)->id, $r['members']));
        }
    }
}
