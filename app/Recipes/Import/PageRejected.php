<?php

namespace App\Recipes\Import;

use RuntimeException;

/** A fetched page that cannot be reduced. Carries a reason code only, never page content. */
final class PageRejected extends RuntimeException
{
    public const NOT_HTML = 'not_html';

    public const TOO_LARGE = 'too_large';

    public const EMPTY = 'empty';

    /** @param  'not_html'|'too_large'|'empty'  $reason */
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
