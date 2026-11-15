<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const IOS_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';
const WIN_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

function listSessionRow(?User $user, string $id, string $ua, int $at, array $payload = []): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user?->id, 'ip_address' => '203.0.113.5', 'user_agent' => $ua,
        'payload' => base64_encode(json_encode($payload + ['_token' => 'secret-token'])), 'last_activity' => $at,
    ]);
}

it('lists only the member sessions, newest first, with flags and no payload', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $other = User::factory()->create();
    $a = str_repeat('a', 40);
    $b = str_repeat('b', 40);
    listSessionRow($member, $a, IOS_UA, now()->subHour()->timestamp, ['auth_remember' => true]);
    listSessionRow($member, $b, WIN_UA, now()->timestamp);
    listSessionRow($other, str_repeat('c', 40), WIN_UA, now()->timestamp);

    $response = $this->actingAs($admin)->getJson("/settings/members/{$member->id}/sessions")->assertOk();
    $items = $response->json('sessions');

    expect($items)->toHaveCount(2);
    expect($items[0])->toMatchArray(['id' => $b, 'browser' => 'Chrome', 'os' => 'Windows', 'mobile' => false, 'ip' => '203.0.113.5', 'isCurrent' => false, 'staysSignedIn' => false]);
    expect($items[1])->toMatchArray(['id' => $a, 'browser' => 'Safari', 'os' => 'iOS', 'mobile' => true, 'staysSignedIn' => true]);
    expect($items[0]['lastActivity'])->toBeString();
    expect($response->getContent())->not->toContain('payload')->not->toContain('secret-token');
});

it('marks only the viewer own session as current', function () {
    $admin = User::factory()->admin()->create();
    $mine = str_repeat('m', 40);
    listSessionRow($admin, $mine, WIN_UA, now()->timestamp);
    listSessionRow($admin, str_repeat('n', 40), WIN_UA, now()->subMinute()->timestamp);

    $items = $this->actingAs($admin)->withCookie(config('session.cookie'), $mine)
        ->get("/settings/members/{$admin->id}/sessions", ['Accept' => 'application/json'])->json('sessions');

    expect(collect($items)->where('isCurrent', true)->pluck('id')->all())->toBe([$mine]);
});

it('treats an undecodable payload as not persistent', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    DB::table('sessions')->insert(['id' => str_repeat('d', 40), 'user_id' => $member->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '!!!', 'last_activity' => now()->timestamp]);

    $item = $this->actingAs($admin)->getJson("/settings/members/{$member->id}/sessions")->json('sessions.0');
    expect($item)->toMatchArray(['browser' => null, 'os' => null, 'staysSignedIn' => false]);
});
