<?php

namespace App\Medical\Queries;

use App\Access\AccessLevel;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\MedicalContact;
use App\Models\MedicalSubject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Emergency contacts of one subject (at most config('medical.max_contacts')), with the VisitQuery semantics: every
 * statement is constrained to the subject and to the viewer's scope. Contacts are only ever replaced as a whole.
 */
class ContactQuery
{
    /** @return Collection<int, MedicalContact> in the order the editor entered them */
    public function forSubject(Viewer $viewer, MedicalSubject $subject): Collection
    {
        return Visibility::apply(MedicalContact::query()->where('subject_id', $subject->getKey()), $viewer)
            ->orderBy('sort')
            ->get();
    }

    /**
     * One transaction: Edit-scoped DELETE of the subject's contacts, then INSERTs with sort 0..n. False when the subject
     * is not editable by the viewer (nothing is written) or the list is longer than the cap.
     *
     * @param  list<array{name: string, relation?: ?string, phone: string}>  $contacts
     */
    public function replaceAll(Viewer $viewer, MedicalSubject $subject, array $contacts): bool
    {
        $contacts = array_values($contacts);
        if (! $viewer->canEdit() || count($contacts) > (int) config('medical.max_contacts')) {
            return false;
        }
        $editable = Visibility::apply(DB::table('medical_subjects')->where('id', $subject->getKey()), $viewer, 'id', AccessLevel::Edit)->exists();
        if (! $editable) {
            return false;
        }

        return DB::transaction(function () use ($viewer, $subject, $contacts) {
            Visibility::apply(
                DB::table('medical_contacts')->where('subject_id', $subject->getKey()),
                $viewer,
                'subject_id',
                AccessLevel::Edit,
            )->delete();

            foreach ($contacts as $sort => $contact) {
                $row = new MedicalContact;
                $row->forceFill([
                    'subject_id' => $subject->getKey(),
                    'name' => $contact['name'],
                    'relation' => ($contact['relation'] ?? '') === '' ? null : $contact['relation'],
                    'phone' => $contact['phone'],
                    'sort' => $sort,
                ])->save();
            }

            return true;
        });
    }
}
