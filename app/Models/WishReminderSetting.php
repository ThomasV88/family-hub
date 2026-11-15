<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per member who changed the gift reminder lead days; no row means the config default. */
class WishReminderSetting extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = ['user_id', 'lead_days'];

    protected function casts(): array
    {
        return ['lead_days' => 'array'];
    }
}
