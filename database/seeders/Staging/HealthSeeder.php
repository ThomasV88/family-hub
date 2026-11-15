<?php

namespace Database\Seeders\Staging;

use App\Access\AccessLevel;
use Carbon\CarbonImmutable;
use Database\Factories\MedicalAllergyFactory;
use Database\Factories\MedicalContactFactory;
use Database\Factories\MedicalMedicationFactory;
use Database\Factories\MedicalSubjectFactory;
use Database\Factories\MedicalVaccinationFactory;
use Database\Factories\MedicalVisitFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Five medical people with several years of visits, medications, allergies, vaccinations and emergency contacts.
 *
 * Written through the medical factories (the arch tests keep the medical models and query classes out of everything
 * but the medical layers and the factories) plus the membership rows SubjectQuery::create would add. No audit row is
 * written, so a deploy does not add "person created" rows to the append-only audit log every time.
 *
 * People: A is linked to parent1 (own record), D to the grandparent (own record), B is an adult without an account, C a
 * child, E is hidden from the Child member and from the Guest. The Guest, the second Child and the admin have no health
 * grant at all and no membership.
 */
final class HealthSeeder
{
    private StagingContext $ctx;

    /** @return array{subjects: array<string, array{id: string, name: string, kind?: string}>, counts: array<string, int>} */
    public function seed(StagingContext $ctx): array
    {
        $this->ctx = $ctx;
        $by = $ctx->user('parent1')->id;

        $subjects = [];
        $counts = ['subjects' => 0, 'visits' => 0, 'medications' => 0, 'allergies' => 0, 'vaccinations' => 0, 'contacts' => 0];
        foreach ($this->people() as $key => $p) {
            $subject = $this->subject($p);
            $subjects[$key] = ['id' => (string) $subject->getKey(), 'name' => (string) $subject->name];
            $id = $subjects[$key]['id'];
            $counts['subjects']++;

            foreach ($p['visits'] as [$ago, $specialty, $doctor, $diagnosis, $notes]) {
                MedicalVisitFactory::new()->create([
                    'subject_id' => $id, 'visited_on' => $ctx->day(-$ago), 'doctor' => $doctor, 'specialty_key' => $specialty,
                    'specialty_other' => $specialty === 'other' ? 'Športová medicína' : null, 'diagnosis' => $diagnosis, 'notes' => $notes,
                    'source' => 'manual', 'created_by' => $by,
                ] + $this->aged(-max($ago, 30)));
                $counts['visits']++;
            }
            foreach ($p['medications'] as [$name, $dose, $schedule, $startAgo, $endAgo, $notes]) {
                MedicalMedicationFactory::new()->create([
                    'subject_id' => $id, 'name' => $name, 'dose' => $dose, 'schedule' => $schedule, 'started_on' => $ctx->day(-$startAgo),
                    'ended_on' => $endAgo === null ? null : $ctx->day(-$endAgo), 'notes' => $notes, 'source' => 'manual', 'created_by' => $by,
                ] + $this->aged(-max($startAgo, 30)));
                $counts['medications']++;
            }
            foreach ($p['allergies'] as [$substance, $reaction, $severity]) {
                MedicalAllergyFactory::new()->create([
                    'subject_id' => $id, 'substance' => $substance, 'reaction' => $reaction, 'severity' => $severity, 'source' => 'manual', 'created_by' => $by,
                ] + $this->aged(-30));
                $counts['allergies']++;
            }
            // Vaccinations: [vaccine, given days ago, next due in days (negative = overdue) or null, notes]
            foreach ($p['vaccinations'] as [$vaccine, $givenAgo, $dueIn, $notes]) {
                MedicalVaccinationFactory::new()->create([
                    'subject_id' => $id, 'vaccine' => $vaccine, 'given_on' => $ctx->day(-$givenAgo),
                    'next_due_on' => $dueIn === null ? null : $ctx->day($dueIn), 'notes' => $notes, 'source' => 'manual', 'created_by' => $by,
                ] + $this->aged(-max($givenAgo, 30)));
                $counts['vaccinations']++;
            }
            foreach ($p['contacts'] as $sort => $contact) {
                MedicalContactFactory::new()->create([
                    'subject_id' => $id, 'name' => $contact['name'], 'relation' => $contact['relation'], 'phone' => $contact['phone'], 'sort' => $sort,
                ]);
                $counts['contacts']++;
            }
        }

        $this->seedArchived($subjects, $counts, $by);

        return ['subjects' => $subjects, 'counts' => $counts];
    }

    /**
     * created_at / updated_at for a seeded row. Every seeded person and record is older than the delete window (72 hours by
     * default), so on staging deleting one archives it instead, and the Archive section can be tried with the demo data.
     *
     * @return array{created_at: CarbonImmutable, updated_at: CarbonImmutable}
     */
    private function aged(int $dayOffset): array
    {
        $at = $this->ctx->at($dayOffset, 9);

        return ['created_at' => $at, 'updated_at' => $at];
    }

    /**
     * The archive demo: one archived visit of person A (hidden from every list, export and reminder, restorable from the
     * Archive of that person) and one archived person with a visit and an allergy (listed under "Archived people" for
     * parent1 and parent2, who can change it). The archived document is added by DocumentsSeeder.
     *
     * @param  array<string, array{id: string, name: string}>  $subjects
     * @param  array<string, int>  $counts
     */
    private function seedArchived(array &$subjects, array &$counts, int $by): void
    {
        $ctx = $this->ctx;
        MedicalVisitFactory::new()->create([
            'subject_id' => $subjects['adult_linked']['id'], 'visited_on' => $ctx->day(-1500), 'doctor' => 'MUDr. Starý (demo)', 'specialty_key' => 'general_practice',
            'diagnosis' => 'Archivovaná návšteva (demo)', 'notes' => 'Archivovaný záznam, obnoviteľný v Archíve osoby.', 'source' => 'manual', 'created_by' => $by,
            'archived_at' => $ctx->at(-5, 10), 'archived_by' => $by,
        ] + $this->aged(-90));
        $counts['visits']++;

        $parents = ['parent1' => AccessLevel::Edit->value, 'parent2' => AccessLevel::Edit->value];
        $person = MedicalSubjectFactory::new()->create([
            'name' => 'Archivovaná osoba (demo)', 'birth_date' => $ctx->today->subYears(67)->format('Y-m-d'), 'sex' => 'female', 'blood_type' => 'B+',
            'notes' => 'Archivovaná osoba pre demo, obnoviteľná z časti Archivované osoby.', 'user_id' => null, 'self_access_enabled' => true, 'ai_consent' => false,
            'created_by' => $by, 'archived_at' => $ctx->at(-3, 10), 'archived_by' => $by,
        ] + $this->aged(-45));
        foreach ($parents as $role => $level) {
            DB::table('medical_subject_members')->insert([
                'subject_id' => $person->getKey(), 'user_id' => $ctx->user($role)->id, 'level' => $level, 'granted_by' => $by, 'created_at' => $ctx->at(-45, 9),
            ]);
        }
        MedicalVisitFactory::new()->create([
            'subject_id' => $person->getKey(), 'visited_on' => $ctx->day(-200), 'doctor' => 'MUDr. Archívna (demo)', 'specialty_key' => 'general_practice',
            'diagnosis' => 'Kontrola (demo)', 'source' => 'manual', 'created_by' => $by,
        ] + $this->aged(-45));
        MedicalAllergyFactory::new()->create([
            'subject_id' => $person->getKey(), 'substance' => 'Peľ (demo)', 'reaction' => 'Kýchanie', 'severity' => 'mild', 'source' => 'manual', 'created_by' => $by,
        ] + $this->aged(-45));
        $counts['subjects']++;
        $counts['visits']++;
        $counts['allergies']++;
        // Not part of the $subjects map the other demo seeders address by key.
    }

    /** @param  array<string, mixed>  $p */
    private function subject(array $p): Model
    {
        $linked = $p['user'] === null ? null : $this->ctx->user($p['user']);
        $parent1 = $this->ctx->user('parent1')->id;
        $born = $this->ctx->today->subYears($p['age'])->subDays($p['born_days_ago']);

        $subject = MedicalSubjectFactory::new()->create([
            'name' => $linked?->name ?? $p['name'],
            'birth_date' => $born->format('Y-m-d'),
            'sex' => $p['sex'],
            'blood_type' => $p['blood'],
            'notes' => $p['notes'],
            'user_id' => $linked?->id,
            'self_access_enabled' => true,
            'ai_consent' => $p['consent'],
            'ai_consent_changed_at' => $p['consent'] ? $this->ctx->at(-20, 9) : null,
            'ai_consent_changed_by' => $p['consent'] ? $parent1 : null,
            'created_by' => $parent1,
        ] + $this->aged(-60));

        // Per-person grants: parent1 and parent2 change everything; the others only what the plan lists.
        foreach ($p['members'] as $role => $level) {
            DB::table('medical_subject_members')->insert([
                'subject_id' => $subject->getKey(), 'user_id' => $this->ctx->user($role)->id, 'level' => $level,
                'granted_by' => $parent1, 'created_at' => $this->ctx->at(-60, 9),
            ]);
        }

        return $subject;
    }

    /** @return array<string, array<string, mixed>> */
    private function people(): array
    {
        $edit = AccessLevel::Edit->value;
        $view = AccessLevel::View->value;
        $parents = ['parent1' => $edit, 'parent2' => $edit];

        return [
            // A: adult linked to parent1 (own record, AI consent on)
            'adult_linked' => [
                'user' => 'parent1', 'name' => null, 'age' => 42, 'born_days_ago' => 40, 'sex' => 'unspecified', 'blood' => 'A+', 'consent' => true,
                'notes' => 'Bežecké aktivity, jarná alergia. Syntetický záznam pre demo.',
                'members' => $parents,
                'visits' => [
                    [1400, 'general_practice', 'MUDr. Hronská (demo)', 'Preventívna prehliadka', 'Bez nálezu.'],
                    [1230, 'dentistry', 'MDDr. Kubík (demo)', 'Zubný kaz 36', 'Plomba, kontrola o pol roka.'],
                    [1050, 'orthopedics', 'MUDr. Slezák (demo)', 'Podvrtnutý členok', 'Pokoj dva týždne, elastický obväz.'],
                    [880, 'dentistry', 'MDDr. Kubík (demo)', 'Dentálna hygiena', null],
                    [760, 'dermatology', 'MUDr. Bartošová (demo)', 'Atopický ekzém, ľahká forma', 'Predpísaná masť, kontrola po šiestich mesiacoch.'],
                    [640, 'general_practice', 'MUDr. Hronská (demo)', 'Preventívna prehliadka', 'Mierne zvýšený cholesterol.'],
                    [520, 'cardiology', 'MUDr. Fiala (demo)', 'Palpitácie bez organického nálezu', 'EKG a Holter v norme.'],
                    [400, 'dentistry', 'MDDr. Kubík (demo)', null, 'Kontrola bez nálezu.'],
                    [290, 'ophthalmology', 'MUDr. Dvorská (demo)', 'Krátkozrakosť -1,5 D', 'Nové okuliare.'],
                    [170, 'general_practice', 'MUDr. Hronská (demo)', 'Chrípka', 'Antibiotiká nie sú potrebné, pokoj a tekutiny.'],
                    [95, 'orthopedics', 'MUDr. Slezák (demo)', 'Bolesť kolena po behu', 'Cvičebný plán od fyzioterapeuta.'],
                    [30, 'general_practice', 'MUDr. Hronská (demo)', 'Kontrola výsledkov krvných testov', 'Výsledky v norme, cholesterol hraničný.'],
                    [10, 'other', 'MUDr. Pavlík (demo)', 'Záťažový test', 'Výkonnosť nadpriemerná.'],
                ],
                'medications' => [
                    ['Vitamín D3', '2000 IU', 'denne ráno', 400, null, 'Od jesene do jari.'],
                    ['Ibuprofen', '400 mg', 'podľa potreby, max. 3x denne', 95, null, null],
                    ['Hydrokortizónová masť', '1 %', 'tenká vrstva 2x denne pri vzplanutí', 760, 700, 'Ukončené po zlepšení.'],
                    ['Amoxicilín', '500 mg', '3x denne 7 dní', 1500, 1493, 'Kúra po angíne.'],
                ],
                'allergies' => [['Peľ brezy', 'Slzenie očí, kýchanie', 'mild'], ['Penicilín', 'Žihľavka a opuch tváre', 'severe']],
                'vaccinations' => [
                    ['Tetanus (preočkovanie)', 3640, 14, 'Pripomienka približne o dva týždne.'],
                    ['Chrípka (sezónna)', 40, 325, null],
                    ['COVID-19 (posilňujúca dávka)', 410, null, null],
                    ['Hepatitída B', 2100, null, 'Ukončená séria.'],
                ],
                'contacts' => [
                    ['name' => 'Manželka/Manžel (demo)', 'relation' => 'partner', 'phone' => '+421 900 000 101'],
                    ['name' => 'Starý rodič (demo)', 'relation' => 'rodič', 'phone' => '+48 500 000 102'],
                ],
            ],
            // B: adult without an account
            'adult' => [
                'user' => null, 'name' => 'Peter Kováč (demo)', 'age' => 45, 'born_days_ago' => 200, 'sex' => 'male', 'blood' => 'O-', 'consent' => false,
                'notes' => 'Strýko, nemá vlastný účet v aplikácii.',
                'members' => $parents + ['grandparent' => $view],
                'visits' => [
                    [1300, 'internal_medicine', 'MUDr. Hudec (demo)', 'Vysoký krvný tlak', 'Nastavená liečba, domáce meranie.'],
                    [1100, 'gastroenterology', 'MUDr. Lukáč (demo)', 'Refluxná choroba', 'Diéta a inhibítor protónovej pumpy.'],
                    [900, 'cardiology', 'MUDr. Fiala (demo)', 'Kontrola hypertenzie', 'Tlak v cieľových hodnotách.'],
                    [700, 'urology', 'MUDr. Jurík (demo)', null, 'Preventívna prehliadka.'],
                    [520, 'general_practice', 'MUDr. Hronská (demo)', 'Preventívna prehliadka', null],
                    [380, 'gastroenterology', 'MUDr. Lukáč (demo)', 'Gastroskopia', 'Bez závažného nálezu.'],
                    [240, 'dentistry', 'MDDr. Kubík (demo)', 'Zubný kameň', 'Hygiena.'],
                    [120, 'internal_medicine', 'MUDr. Hudec (demo)', 'Kontrola krvného tlaku', 'Dávka lieku bez zmeny.'],
                    [45, 'laboratory', 'Laboratórium (demo)', 'Krvný obraz a lipidy', 'V norme.'],
                ],
                'medications' => [
                    ['Amlodipín', '5 mg', 'denne ráno', 1300, null, 'Pri vysokom tlaku.'],
                    ['Pantoprazol', '20 mg', 'denne pred raňajkami', 1100, 380, 'Ukončené po gastroskopii.'],
                    ['Omega-3', '1000 mg', 'denne', 600, null, null],
                ],
                'allergies' => [['Lieskové orechy', 'Svrbenie v ústach, opuch pier', 'moderate']],
                'vaccinations' => [
                    ['Hepatitída B', 1500, 400, 'Posilňujúca dávka.'],
                    ['Chrípka (sezónna)', 38, 327, null],
                ],
                'contacts' => [['name' => 'Sestra (demo)', 'relation' => 'sestra', 'phone' => '+421 900 000 111']],
            ],
            // C: child
            'child' => [
                'user' => null, 'name' => 'Miško Horváth (demo)', 'age' => 8, 'born_days_ago' => 120, 'sex' => 'male', 'blood' => 'B+', 'consent' => false,
                'notes' => 'Školák, alergia na roztoče. Syntetický záznam.',
                'members' => $parents + ['child1' => $view, 'grandparent' => $view],
                'visits' => [
                    [1400, 'pediatrics', 'MUDr. Šimková (demo)', 'Preventívna prehliadka', 'Vývoj zodpovedá veku.'],
                    [1200, 'pediatrics', 'MUDr. Šimková (demo)', 'Zápal stredného ucha', 'Antibiotiká 7 dní.'],
                    [1100, 'ent', 'MUDr. Kopecký (demo)', 'Zväčšený nosový mandľ', 'Sledovať, bez operácie.'],
                    [950, 'pediatrics', 'MUDr. Šimková (demo)', 'Preventívna prehliadka', null],
                    [800, 'allergology', 'MUDr. Baláž (demo)', 'Alergia na roztoče', 'Kožné testy pozitívne, odporúčané opatrenia v byte.'],
                    [650, 'dentistry', 'MDDr. Kubík (demo)', 'Prvá preventívna prehliadka', 'Bez kazu.'],
                    [500, 'pediatrics', 'MUDr. Šimková (demo)', 'Preventívna prehliadka', null],
                    [420, 'ophthalmology', 'MUDr. Dvorská (demo)', 'Kontrola zraku', 'Bez poruchy.'],
                    [300, 'pediatrics', 'MUDr. Šimková (demo)', 'Bronchitída', 'Inhalácie a pokoj.'],
                    [210, 'allergology', 'MUDr. Baláž (demo)', 'Kontrola alergie', 'Stabilný stav.'],
                    [150, 'dentistry', 'MDDr. Kubík (demo)', 'Kontrola', 'Jeden kaz mliečneho zuba.'],
                    [60, 'pediatrics', 'MUDr. Šimková (demo)', 'Preventívna prehliadka pred 3. triedou', 'Bez nálezu.'],
                ],
                'medications' => [
                    ['Cetirizín (sirup)', '5 mg', 'denne večer v sezóne', 800, null, 'Od jari do jesene.'],
                    ['Paracetamol (čapíky)', '250 mg', 'pri horúčke, max. 4x denne', 1500, null, null],
                    ['Amoxicilín', '250 mg/5 ml', '3x denne 7 dní', 1200, 1193, 'Kúra pri zápale ucha.'],
                ],
                'allergies' => [['Roztoče domáceho prachu', 'Kýchanie, upchatý nos', 'mild'], ['Kravské mlieko', 'Vyrážka', 'moderate'], ['Osie bodnutie', 'Veľký opuch v mieste', 'unknown']],
                'vaccinations' => [
                    ['Hexavakcína (4. dávka)', 2300, null, null],
                    ['MMR (2. dávka)', 2200, -45, 'Preočkovanie meškáme, termín treba dohodnúť.'],
                    ['Chrípka (sezónna)', 38, 327, null],
                    ['Pneumokok', 2400, null, null],
                ],
                'contacts' => [
                    ['name' => 'Mama (demo)', 'relation' => 'matka', 'phone' => '+421 900 000 121'],
                    ['name' => 'Otec (demo)', 'relation' => 'otec', 'phone' => '+421 900 000 122'],
                    ['name' => 'Stará mama (demo)', 'relation' => 'stará mama', 'phone' => '+48 500 000 123'],
                ],
            ],
            // D: grandparent, linked to the grandparent member (own record, AI consent on)
            'grandparent' => [
                'user' => 'grandparent', 'name' => null, 'age' => 74, 'born_days_ago' => 90, 'sex' => 'unspecified', 'blood' => 'AB+', 'consent' => true,
                'notes' => 'Cukrovka 2. typu, vysoký tlak. Syntetický záznam.',
                'members' => $parents + ['child1' => $view],
                'visits' => [
                    [1420, 'geriatrics', 'MUDr. Nowak (demo)', 'Komplexné vyšetrenie', 'Plán pravidelných kontrol.'],
                    [1350, 'diabetology', 'MUDr. Wójcik (demo)', 'Diabetes mellitus 2. typu', 'Úprava dávky metformínu.'],
                    [1250, 'cardiology', 'MUDr. Fiala (demo)', 'Hypertenzia', 'Pridaný druhý liek.'],
                    [1130, 'ophthalmology', 'MUDr. Dvorská (demo)', 'Začínajúci katarakt', 'Sledovať.'],
                    [1000, 'diabetology', 'MUDr. Wójcik (demo)', 'Kontrola glykémie', 'HbA1c 7,1 %.'],
                    [900, 'endocrinology', 'MUDr. Zielińska (demo)', 'Hypotyreóza', 'Nasadený levotyroxín.'],
                    [780, 'cardiology', 'MUDr. Fiala (demo)', 'Kontrola hypertenzie', 'Tlak v norme.'],
                    [680, 'nephrology', 'MUDr. Kaczmarek (demo)', 'Mierne zníženie funkcie obličiek', 'Kontrola o pol roka.'],
                    [560, 'general_practice', 'MUDr. Hronská (demo)', 'Chrípka', 'Domáci režim.'],
                    [450, 'orthopedics', 'MUDr. Slezák (demo)', 'Artróza kolena', 'Rehabilitácia a cvičenie.'],
                    [400, 'rehabilitation', 'Rehabilitačné centrum (demo)', null, 'Séria 10 procedúr.'],
                    [330, 'ophthalmology', 'MUDr. Dvorská (demo)', 'Operácia katarakty, ľavé oko', 'Priebeh bez komplikácií.'],
                    [250, 'cardiology', 'MUDr. Fiala (demo)', 'Kontrola po operácii', 'Bez problémov.'],
                    [160, 'diabetology', 'MUDr. Wójcik (demo)', 'Kontrola glykémie', 'HbA1c 6,8 %.'],
                    [75, 'general_practice', 'MUDr. Hronská (demo)', 'Preventívna prehliadka', 'Doplniť očkovanie proti pneumokoku.'],
                    [20, 'geriatrics', 'MUDr. Nowak (demo)', 'Ročná kontrola', 'Stav stabilný.'],
                ],
                'medications' => [
                    ['Metformín', '850 mg', '2x denne s jedlom', 1350, null, 'Pri cukrovke.'],
                    ['Ramipril', '5 mg', 'denne ráno', 1250, null, null],
                    ['Levotyroxín', '50 mcg', 'denne nalačno', 900, null, null],
                    ['Acetylsalicylová kyselina', '100 mg', 'denne', 1420, 700, 'Ukončené po odporúčaní kardiológa.'],
                ],
                'allergies' => [['Sulfónamidy', 'Rozsiahla vyrážka, horúčka', 'severe'], ['Latex', 'Kontaktná vyrážka', 'moderate']],
                'vaccinations' => [
                    ['Pneumokok', 1800, 200, 'Pripomienka na preočkovanie.'],
                    ['Chrípka (sezónna)', 35, 330, null],
                    ['COVID-19 (posilňujúca dávka)', 380, null, null],
                    ['Tetanus', 2900, null, null],
                ],
                'contacts' => [
                    ['name' => 'Dcéra (demo)', 'relation' => 'dcéra', 'phone' => '+421 900 000 131'],
                    ['name' => 'Syn (demo)', 'relation' => 'syn', 'phone' => '+44 7700 900132'],
                ],
            ],
            // E: hidden from the Child member and the Guest
            'hidden' => [
                'user' => null, 'name' => 'Marek Skrytý (demo)', 'age' => 52, 'born_days_ago' => 260, 'sex' => 'male', 'blood' => 'O+', 'consent' => false,
                'notes' => 'Citlivý záznam: vidia ho len rodičia. Slúži na test skrytia.',
                'members' => $parents,
                'visits' => [
                    [1100, 'psychology', 'Mgr. Straka (demo)', 'Konzultácia: pracovný stres', 'Päť stretnutí, zlepšenie.'],
                    [900, 'general_practice', 'MUDr. Hronská (demo)', 'Preventívna prehliadka', null],
                    [700, 'neurology', 'MUDr. Čermák (demo)', 'Migréna', 'Nastavená profylaxia.'],
                    [540, 'neurology', 'MUDr. Čermák (demo)', 'Kontrola migrény', 'Menej záchvatov.'],
                    [330, 'hematology', 'MUDr. Rybár (demo)', 'Mierna anémia', 'Doplnenie železa.'],
                    [180, 'internal_medicine', 'MUDr. Hudec (demo)', 'Kontrola krvného obrazu', 'V norme.'],
                    [50, 'general_practice', 'MUDr. Hronská (demo)', 'Preventívna prehliadka', null],
                ],
                'medications' => [
                    ['Propranolol', '40 mg', 'denne večer', 700, null, 'Profylaxia migrény.'],
                    ['Železo (perorálne)', '80 mg', 'denne', 330, 150, 'Ukončené po úprave hodnôt.'],
                ],
                'allergies' => [['Kyselina acetylsalicylová', 'Astmatický záchvat', 'severe']],
                'vaccinations' => [
                    ['Kliešťová encefalitída', 700, 90, 'Preočkovanie.'],
                    ['Tetanus', 1500, null, null],
                ],
                'contacts' => [['name' => 'Priateľ (demo)', 'relation' => 'priateľ', 'phone' => '+421 900 000 141']],
            ],
        ];
    }
}
