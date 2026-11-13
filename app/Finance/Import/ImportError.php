<?php

namespace App\Finance\Import;

use RuntimeException;

/** Refusal of an uploaded statement. Carries a string code only, never cell content. */
final class ImportError extends RuntimeException
{
    /**
     * 'too_large'|'binary'|'decode_failed'|'too_many_rows'|'empty'|'profile_mismatch'
     * (untyped redeclare of Exception::$code, which PHP types as protected without a type)
     *
     * @var string
     */
    public $code;

    public function __construct(string $code, ?int $row = null)
    {
        parent::__construct($row === null ? $code : $code.' (row '.$row.')');
        $this->code = $code;
    }
}
