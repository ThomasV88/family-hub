<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One contextual help topic: a text per locale plus a per-locale "edited by an administrator" marker. */
class HelpText extends Model
{
    protected $table = 'help_texts';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'edited_sk' => 'boolean',
            'edited_pl' => 'boolean',
            'edited_en' => 'boolean',
            'edited_at' => 'datetime',
        ];
    }
}
