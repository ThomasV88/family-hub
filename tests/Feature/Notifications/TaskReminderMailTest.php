<?php

use App\Access\Preset;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskReminderNotification;

function reminderMail(?string $locale, array $task, ?array &$data = null)
{
    $user = User::factory()->withPreset(Preset::Parent)->create(['name' => 'Jana', 'locale' => $locale]);
    $model = Task::factory()->create(['creator_id' => $user->id, 'title' => 'Zaplatiť internet', 'notes' => 'SECRET-NOTES-TEXT'] + $task);
    $mail = (new TaskReminderNotification($model->fresh()))->toMail($user);
    $data = ['task' => $model, 'rendered' => (string) $mail->render()];

    return $mail;
}

it('renders a Slovak reminder with the due moment in the family zone', function () {
    $mail = reminderMail('sk', ['due_date' => '2027-05-10', 'due_time' => '18:00'], $data);

    expect($mail->subject)->toBe('Termín úlohy: Zaplatiť internet')
        ->and($mail->actionUrl)->toBe(url('/todo?task='.$data['task']->id))
        ->and(implode(' ', $mail->introLines))->toContain('10. mája 2027')->toContain('18:00')
        ->and($data['rendered'])->not->toContain('SECRET-NOTES-TEXT');
});

it('formats a date-only task without a time', function () {
    $mail = reminderMail('sk', ['due_date' => '2027-05-10'], $data);

    expect(implode(' ', $mail->introLines))->toContain('10. mája 2027')->not->toContain(':');
});

it('has en and pl variants and falls back to en', function () {
    expect(reminderMail('en', ['due_date' => '2027-05-10', 'due_time' => '18:00'])->subject)->toBe('Task due: Zaplatiť internet');
    expect(reminderMail('pl', ['due_date' => '2027-05-10', 'due_time' => '18:00'])->subject)->toBe('Termin zadania: Zaplatiť internet');
    expect(reminderMail(null, ['due_date' => '2027-05-10', 'due_time' => '18:00'])->subject)->toBe('Task due: Zaplatiť internet');
});

it('neutralizes Markdown and links in the task title and member name', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create(['name' => '[Jana](https://evil.example/n)', 'locale' => 'en']);
    $model = Task::factory()->create([
        'creator_id' => $user->id,
        'title' => '[Confirm your bank login](https://evil.example/x) ![i](https://evil.example/i.png) **bold** https://evil.example/auto',
        'due_date' => '2027-05-10',
    ]);
    $html = (string) (new TaskReminderNotification($model->fresh()))->toMail($user)->render();

    expect($html)->not->toContain('evil.example/x"')->not->toContain('href="https://evil.example')
        ->not->toContain('<img src="https://evil.example')->not->toContain('<strong>bold')
        ->toContain('Confirm your bank login');
    // The only anchor is the action button to our own app.
    preg_match_all('/<a [^>]*href="([^"]+)"/', $html, $m);
    foreach ($m[1] as $href) {
        expect($href)->toStartWith(url('/'));
    }
});
