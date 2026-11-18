<?php

namespace App\Console\Commands;

use App\Support\SyntheticChild;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StagingAudit extends Command
{
    protected $signature = 'staging:audit';

    protected $description = 'Fail if staging contains any non-synthetic data';

    /**
     * Rules of [table, SQL condition matching NON-synthetic rows].
     * Later phases append rules for their own tables.
     *
     * @return list<array{0: string, 1: string}>
     */
    protected function rules(): array
    {
        // A child has no e-mail (NULL), so NOT LIKE would be NULL and silently pass: the synthetic marker is explicit.
        // The name suffix is user-editable text, so a child is only synthetic when all its managers are synthetic too.
        $synthetic = "(select c.id from users c where c.email like '%@example.test' or ".SyntheticChild::condition('c').')';
        // Phase 5: staging must never hold real finance data. Rows created by a deleted (null) or non-synthetic user fail.
        // Null stays flagged: StagingSeeder deletes the rows of the synthetic users before it deletes them, so a null
        // creator can only be a real user who was deleted (real data) and never a reseeded synthetic one.
        $real = "(select id from users where id not in {$synthetic})";
        $anyUser = '(select id from users)';
        $createdByReal = "(created_by is null or created_by not in {$synthetic})";

        return [
            ['users', "id not in {$synthetic}"],
            // Phase 10: a real child's photo is a real person's picture.
            ['users', "photo_path is not null and id not in {$synthetic}"],
            // Phase 2: invitations hold the invited address; audit rows keep names (labels) and invite emails.
            ['invitations', "email not like '%@example.test'"],
            // A reseed deletes the synthetic users, and audit_logs and ai_calls are append-only, so their rows keep ids that no
            // longer exist. Only a deleted synthetic user can leave such an id, so a dangling id is accepted: the rules look
            // for a REAL thing instead (an existing non-synthetic user, a real email, or a stored label that is not a demo label).
            ['audit_logs', "(meta->>'email' is not null and meta->>'email' not like '%@example.test')"
                ." or actor_id in {$real}"
                ." or target_user_id in {$real}"
                ." or (actor_id is not null and actor_id not in {$anyUser} and actor_label is not null and actor_label not like '%(demo)')"
                ." or (target_user_id is not null and target_user_id not in {$anyUser} and target_label is not null and target_label not like '%(demo)')"],
            ['finance_accounts', $createdByReal],
            ['finance_transactions', $createdByReal],
            ['finance_recurring', $createdByReal],
            ['finance_recurring_matches', $createdByReal],
            ['finance_budgets', $createdByReal],
            ['finance_import_profiles', $createdByReal],
            ['finance_import_batches', "(user_id not in {$synthetic})"],
            ['finance_account_members', "(user_id not in {$synthetic})"],
            // Phase 7: staging must never hold real medical data. A subject created by a deleted (null) or real user, or
            // linked to a real user, fails; so does any membership of a real user.
            ['medical_subjects', "{$createdByReal} or (user_id is not null and user_id not in {$synthetic}) or archived_by in {$real}"],
            ['medical_subject_members', "(user_id not in {$synthetic})"],
            // Archive (owner decision B): a record archived by a real user means real use of the archive on staging.
            ['medical_visits', "(archived_by in {$real})"],
            ['medical_medications', "(archived_by in {$real})"],
            ['medical_allergies', "(archived_by in {$real})"],
            ['medical_vaccinations', "(archived_by in {$real})"],
            ['medical_documents', "(archived_by in {$real})"],
            // Phase 8: a run started by a real user means a real document may have gone to the AI provider and a proposal
            // (health content) may sit in the database. started_by has no foreign key and ai_calls is append-only, so a
            // dangling id is a deleted synthetic user and is accepted; an existing non-synthetic user fails.
            ['medical_ai_extractions', "(started_by in {$real})"],
            // Phase 13: the recognised full text of a document started by a real user. Same dangling-id rule.
            ['medical_document_texts', "(started_by in {$real})"],
            ['ai_calls', "(started_by in {$real})"],
            // Phase 15: an upload batch holds titles of pending documents and the choices of a real member. A batch needs a
            // synthetic creator (null means a real user who was deleted); the acting member and the member who requested a run
            // may only be null or synthetic.
            ['medical_upload_batches', "{$createdByReal} or (acted_by is not null and acted_by not in {$synthetic})"],
            ['medical_upload_batch_items', "(requested_by is not null and requested_by not in {$synthetic})"],
            // Phase 11: shopping data of a real user (names, lists, stores) must never reach staging. A list needs a
            // synthetic creator (the column is NOT NULL and RESTRICT, so a null cannot occur). As with finance, a null creator on
            // an item, catalog item, store or custom unit stays flagged: it can only be a real user who was deleted (nullOnDelete).
            // Only built-in units (they have a key) legitimately have no creator. A responsible person who was removed may be null.
            ['shopping_lists', "(creator_id not in {$synthetic}) or (responsible_id is not null and responsible_id not in {$synthetic})"],
            ['shopping_list_members', "(user_id not in {$synthetic})"],
            ['shopping_items', "(created_by is null or created_by not in {$synthetic}) or (state_changed_by is not null and state_changed_by not in {$synthetic})"],
            ['shopping_catalog_items', "(created_by is null or created_by not in {$synthetic})"],
            ['shopping_stores', "(created_by is null or created_by not in {$synthetic})"],
            ['shopping_units', "(key is null and (created_by is null or created_by not in {$synthetic})) or (key is not null and created_by is not null and created_by not in {$synthetic})"],
            // Phase 12: recipes (titles, ingredients, photos, personal notes) of a real member must never reach staging.
            // author_id is NOT NULL and RESTRICT, so a null cannot occur. Member state, ticks and done marks cascade with
            // their user, so any row of a non-synthetic user is real use. recipe_imports.user_id has no foreign key and holds
            // the pasted address of a real member, so it is checked like ai_calls.started_by but any non-synthetic id fails.
            ['recipes', "(author_id not in {$synthetic})"],
            ['recipe_member_states', "(user_id not in {$synthetic})"],
            ['recipe_ingredient_ticks', "(user_id not in {$synthetic})"],
            ['recipe_step_done', "(user_id not in {$synthetic})"],
            ['recipe_imports', "(user_id not in {$synthetic})"],
        ];
    }

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('staging:audit refused: environment production');

            return 1;
        }

        $failed = false;
        foreach ($this->rules() as [$table, $condition]) {
            $n = DB::table($table)->whereRaw($condition)->count();
            if ($n > 0) {
                $this->error("staging:audit FAILED: {$n} non-synthetic {$table}");
                $failed = true;
            }
        }

        if ($failed) {
            return 1;
        }

        $this->info('staging:audit OK');

        return 0;
    }
}
