<?php

namespace App\Models;

use Database\Factories\RecipeImportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One AI recipe import from a page. Only App\Recipes\Import may read or write it; rows are written by the import
 * service and the two jobs, never from client input.
 */
class RecipeImport extends Model
{
    /** @use HasFactory<RecipeImportFactory> */
    use HasFactory, HasUuids;

    protected $table = 'recipe_imports';

    /** @var list<string> */
    protected $guarded = ['*'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'text_cut' => 'boolean',
            'schema_version' => 'integer',
            'prompt_version' => 'integer',
            'cancel_requested_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
