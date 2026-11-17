<?php

namespace App\Shopping\Catalog;

use App\Models\ShoppingUnit;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Administrator units with three language names. Built-in units (those with a key) are never changed or deleted. */
final class UnitService
{
    private const LOCALES = ['en', 'sk', 'pl'];

    public function create(User $admin, string $en, string $sk, string $pl): ShoppingUnit
    {
        $names = $this->names($en, $sk, $pl, null);
        $unit = new ShoppingUnit($names + ['position' => (int) ShoppingUnit::query()->max('position') + 1]);
        $unit->forceFill(['created_by' => $admin->id]);
        $this->save($unit);

        return $unit;
    }

    public function update(User $admin, int $id, string $en, string $sk, string $pl): ShoppingUnit
    {
        $unit = $this->custom($id);
        $unit->fill($this->names($en, $sk, $pl, $unit->id));
        $this->save($unit);

        return $unit;
    }

    public function delete(User $admin, int $id): void
    {
        $unit = $this->custom($id);
        DB::transaction(fn () => $unit->delete());
    }

    private function custom(int $id): ShoppingUnit
    {
        $unit = ShoppingUnit::query()->findOrFail($id);
        if ($unit->isBuiltIn()) {
            throw ValidationException::withMessages(['unit' => 'shopping.settings.err_builtin_unit']);
        }

        return $unit;
    }

    /** @return array<string, string> */
    private function names(string $en, string $sk, string $pl, ?int $exceptId): array
    {
        $names = ['name_en' => trim($en), 'name_sk' => trim($sk), 'name_pl' => trim($pl)];
        $errors = [];
        foreach (self::LOCALES as $locale) {
            $col = "name_{$locale}";
            $taken = ShoppingUnit::query()->whereRaw("lower({$col}) = ?", [mb_strtolower($names[$col])])
                ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
            if ($taken) {
                $errors[$col] = 'shopping.settings.err_duplicate';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $names;
    }

    private function save(ShoppingUnit $unit): void
    {
        try {
            $unit->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name_en' => 'shopping.settings.err_duplicate']);
        }
    }
}
