<?php

namespace App\Shopping\Mail;

use App\Models\ShoppingList;
use App\Models\ShoppingUnit;
use App\Shopping\ItemState;
use App\Shopping\ListStatus;
use App\Shopping\ResponsiblePerson;

/**
 * Subject and plain-text body of a list e-mail, built in one locale as a string snapshot. Nothing is HTML-escaped (the
 * mail has a text part only) and no member e-mail address or link into the app appears.
 */
final class ShoppingListText
{
    private const LOCALES = ['en', 'sk', 'pl'];

    /** @return array{subject: string, body: string} */
    public static function for(ShoppingList $list, string $locale): array
    {
        $locale = in_array($locale, self::LOCALES, true) ? $locale : 'en';
        $list->loadMissing(['store', 'items.unit']);

        $subject = self::oneLine(trans('mail.shopping_list.subject', ['name' => self::oneLine($list->name)], $locale));

        $lines = [];
        if ($list->store !== null) {
            $lines[] = trans('mail.shopping_list.store', ['name' => self::oneLine($list->store->name)], $locale);
        }
        $lines[] = trans('mail.shopping_list.responsible', ['name' => self::oneLine(app(ResponsiblePerson::class)->effective($list)->name)], $locale);
        $lines[] = '';

        $marked = $list->status !== ListStatus::Open;
        foreach ($list->items as $item) {
            $line = '- '.self::quantity((string) $item->quantity);
            $unit = $item->unit !== null ? self::unitLabel($item->unit, (string) $item->quantity, $locale) : null;
            if ($unit !== null && $unit !== '') {
                $line .= ' '.$unit;
            }
            $line .= ' '.self::oneLine($item->name);
            if ($item->note !== null && trim($item->note) !== '') {
                $line .= ' ('.self::oneLine($item->note).')';
            }
            if ($marked && $item->state !== ItemState::Pending) {
                $line .= ' ('.trans('mail.shopping_list.'.$item->state->value, [], $locale).')';
            }
            $lines[] = $line;
        }

        return ['subject' => $subject, 'body' => implode("\n", $lines)."\n"];
    }

    /** "2.50" is written "2.5", "3.00" "3". */
    private static function quantity(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }

    private static function unitLabel(ShoppingUnit $unit, string $quantity, string $locale): ?string
    {
        if (! $unit->isBuiltIn()) {
            return self::oneLine((string) $unit->{'name_'.$locale});
        }

        // A fraction takes the plural form of "many" (2.5 kg, 0.5 balenia): count 5 selects it in en, sk and pl.
        $count = str_contains(self::quantity($quantity), '.') ? 5 : (int) $quantity;

        return trans_choice('mail.shopping_list.units.'.$unit->key, $count, [], $locale);
    }

    /** Header safety: no CR, LF or other control characters. */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
    }
}
