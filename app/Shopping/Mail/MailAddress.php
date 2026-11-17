<?php

namespace App\Shopping\Mail;

use Symfony\Component\Mime\Address;
use Throwable;

/**
 * The one definition of an address the list mail accepts: a single line, a plain dot-atom local part and a host with
 * a dot. No quoted local part, no comment, no folding whitespace, no control character, no host like "localhost".
 * Anything Symfony would refuse at send time is refused here, before a rate-limit slot is spent.
 */
final class MailAddress
{
    private const SPECIAL = '\s\x00-\x1F\x7F"(),:;<>@\[\\\\\]';

    /** True when the address is safe to queue. */
    public static function acceptable(mixed $address): bool
    {
        if (! is_string($address) || $address === '' || mb_strlen($address) > 254) {
            return false;
        }
        if (preg_match('/^[^'.self::SPECIAL.']+@[^'.self::SPECIAL.']+\.[^'.self::SPECIAL.']+$/u', $address) !== 1) {
            return false;
        }
        if (str_contains($address, '..') || str_starts_with($address, '.') || str_contains($address, '.@') || str_ends_with($address, '.')) {
            return false;
        }

        try {
            new Address($address);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /** Lower-case address with the "+tag" of the local part removed: the bucket of the per-address daily cap. */
    public static function capKey(string $address): string
    {
        $address = mb_strtolower(trim($address));
        $at = strrpos($address, '@');
        if ($at === false) {
            return $address;
        }
        $local = substr($address, 0, $at);
        $plus = strpos($local, '+');
        if ($plus !== false && $plus > 0) {
            $local = substr($local, 0, $plus);
        }

        return $local.substr($address, $at);
    }
}
