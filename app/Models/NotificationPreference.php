<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'channel', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
