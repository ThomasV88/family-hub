<?php

namespace App\Models;

use App\Tasks\TaskStatus;
use App\Tasks\TaskVisibility;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * creator_id, timezone, series_id, remind_at, completed_at, completed_by and skipped_at are
     * deliberately not fillable: services set them with forceFill.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title', 'notes', 'status', 'visibility', 'assignee_id', 'due_date', 'due_time',
        'remind_before_minutes', 'recurrence_rule', 'recurrence_mode',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'visibility' => TaskVisibility::class,
            'due_date' => 'date:Y-m-d',
            'remind_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'skipped_at' => 'immutable_datetime',
            'remind_before_minutes' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_members');
    }

    /** Record-level visibility: the creator, everyone for Family, listed members for Selected. There is no admin bypass. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('tasks.creator_id', $user->id)
            ->orWhere('tasks.visibility', TaskVisibility::Family->value)
            ->orWhere(fn (Builder $q) => $q->where('tasks.visibility', TaskVisibility::Selected->value)
                ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('task_members')
                    ->whereColumn('task_members.task_id', 'tasks.id')
                    ->where('task_members.user_id', $user->id))));
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('tasks.completed_at')->whereNull('tasks.skipped_at');
    }

    public function isRecurring(): bool
    {
        return $this->recurrence_rule !== null;
    }

    /**
     * A completed or skipped occurrence of a series is history and immutable. It is defined by series_id
     * (kept when the rule is cleared), so clearing the recurrence cannot turn history back into a plain task.
     */
    public function isClosedOccurrence(): bool
    {
        return ($this->series_id !== null || $this->isRecurring())
            && ($this->completed_at !== null || $this->skipped_at !== null);
    }
}
