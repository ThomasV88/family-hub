<?php

namespace App\Shopping\Mail;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Mail\ShoppingListMail;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/** Queues one text e-mail per address, enforces the per-address daily cap and writes an address-free audit row. */
final class ShoppingListMailer
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** The refusal used by the limiter and by the per-address cap. */
    public static function limitResponse(array $headers = []): JsonResponse
    {
        return response()->json(['message' => 'shopping.mail.err_limit', 'error' => 'shopping.mail.err_limit'], 429, $headers);
    }

    /**
     * @param  list<string>  $addresses  already validated, lower-cased and unique
     * @return int the number of e-mails queued
     */
    public function send(User $sender, ShoppingList $list, array $addresses): int
    {
        $cap = (int) config('shopping.mail.per_recipient_day');
        $keys = array_map(fn (string $a) => 'shopping-mail-to:'.sha1($a), $addresses);

        // All or nothing: one address over its cap refuses the whole send before anything is counted or queued.
        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, $cap)) {
                throw new HttpResponseException(self::limitResponse(['Retry-After' => (string) RateLimiter::availableIn($key)]));
            }
        }

        $text = ShoppingListText::for($list, app()->getLocale());

        foreach ($addresses as $i => $address) {
            RateLimiter::hit($keys[$i], 86400);
            Mail::to($address)->queue(new ShoppingListMail($text['subject'], $text['body']));
        }

        $this->audit->record(AuditEvent::ShoppingListMailed, $sender, ['recipient_count' => count($addresses)]);

        return count($addresses);
    }
}
