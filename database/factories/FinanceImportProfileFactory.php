<?php

namespace Database\Factories;

use App\Models\FinanceImportProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceImportProfile>
 */
class FinanceImportProfileFactory extends Factory
{
    protected $model = FinanceImportProfile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'settings' => self::skSettings(),
        ];
    }

    /** Settings that fit the committed Windows-1250 SK fixture. */
    public static function skSettings(): array
    {
        return [
            'delimiter' => ';',
            'encoding' => 'WINDOWS-1250',
            'decimalSeparator' => ',',
            'groupingSeparator' => ' ',
            'dateFormat' => 'd.m.Y',
            'headerRow' => 4,
            'amountMode' => 'single',
            'columns' => [
                'date' => ['name' => 'Dátum', 'index' => 0],
                'description' => ['name' => 'Popis', 'index' => 1],
                'counterparty' => ['name' => 'Protistrana', 'index' => 2],
                'amount' => ['name' => 'Suma', 'index' => 3],
                'currency' => ['name' => 'Mena', 'index' => 4],
            ],
            'indicatorExpense' => [],
            'indicatorIncome' => [],
        ];
    }
}
