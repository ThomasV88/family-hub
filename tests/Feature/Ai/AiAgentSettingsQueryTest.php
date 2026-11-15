<?php

use App\Audit\AuditLogger;
use App\Medical\Queries\AiAgentSettingsQuery;
use App\Models\AiAgentSetting;
use App\Models\AuditEntry;
use App\Models\User;
use Tests\Support\FailingAuditLogger;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->q = fn () => app(AiAgentSettingsQuery::class);
    $this->audits = fn () => AuditEntry::query()->where('event', 'ai_agent_setting_changed')->get();
});

it('lists codex on at 1 then copilot off at 2, with defaults for a missing row', function () {
    expect(($this->q)()->all())->toBe([
        ['agent' => 'codex', 'enabled' => true, 'position' => 1, 'model' => null],
        ['agent' => 'copilot', 'enabled' => false, 'position' => 2, 'model' => null],
    ]);
    AiAgentSetting::query()->toBase()->where('agent', 'copilot')->delete();

    expect(($this->q)()->all()[1])->toBe(['agent' => 'copilot', 'enabled' => false, 'position' => 2, 'model' => null])
        ->and(($this->q)()->enabledKeys())->toBe(['codex']);
});

it('enables copilot with one audit row and writes nothing when repeated', function () {
    $r = ($this->q)()->setEnabled($this->admin, 'copilot', true);
    expect($r['ok'])->toBeTrue()->and(($this->q)()->enabledKeys())->toBe(['codex', 'copilot']);
    $rows = ($this->audits)();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->meta)->toBe(['agent' => 'copilot', 'field' => 'enabled', 'value' => 'on']);

    expect(($this->q)()->setEnabled($this->admin, 'copilot', true)['ok'])->toBeTrue()
        ->and(($this->audits)())->toHaveCount(1);
});

it('refuses switching off the last enabled agent and unknown agents', function () {
    $r = ($this->q)()->setEnabled($this->admin, 'codex', false);
    expect($r['ok'])->toBeFalse()->and($r['reason'])->toBe('last_enabled')
        ->and(($this->q)()->enabledKeys())->toBe(['codex'])
        ->and(($this->audits)())->toHaveCount(0)
        ->and(($this->q)()->setEnabled($this->admin, 'nope', true)['reason'])->toBe('unknown');
});

it('saves and audits valid models and refuses invalid ones', function () {
    $r = ($this->q)()->setModel($this->admin, 'codex', 'gpt-5.6-sol');
    expect($r['ok'])->toBeTrue()->and(($this->q)()->model('codex'))->toBe('gpt-5.6-sol')
        ->and(($this->audits)()->last()->meta['value'])->toBe('gpt-5.6-sol');

    expect(($this->q)()->setModel($this->admin, 'codex', '')['ok'])->toBeTrue()
        ->and(($this->q)()->model('codex'))->toBeNull()
        ->and(($this->audits)()->last()->meta['value'])->toBe('default');

    foreach (['a/b', '-x'] as $bad) {
        expect(($this->q)()->setModel($this->admin, 'codex', $bad)['reason'])->toBe('invalid_model');
    }
    expect(($this->audits)())->toHaveCount(2);
});

it('moves an agent, ignores moves past an end and detects a stale order', function () {
    $r = ($this->q)()->move($this->admin, 'copilot', 'up');
    expect($r['ok'])->toBeTrue()
        ->and(array_column(($this->q)()->all(), 'agent'))->toBe(['copilot', 'codex'])
        ->and(($this->audits)())->toHaveCount(1)
        ->and(($this->audits)()[0]->meta)->toBe(['agent' => 'copilot', 'field' => 'position', 'value' => '1']);

    expect(($this->q)()->move($this->admin, 'copilot', 'up')['ok'])->toBeTrue()
        ->and(($this->audits)())->toHaveCount(1);

    $stale = ($this->q)()->move($this->admin, 'copilot', 'down', 2);
    expect($stale['ok'])->toBeFalse()->and($stale['reason'])->toBe('stale')
        ->and(array_column(($this->q)()->all(), 'agent'))->toBe(['copilot', 'codex']);
});

it('audits before the change, so a failing audit leaves the row unchanged', function () {
    app()->instance(AuditLogger::class, new FailingAuditLogger);

    expect(fn () => app(AiAgentSettingsQuery::class)->setEnabled($this->admin, 'copilot', true))->toThrow(RuntimeException::class)
        ->and(fn () => app(AiAgentSettingsQuery::class)->move($this->admin, 'copilot', 'up'))->toThrow(RuntimeException::class);
    expect(AiAgentSetting::query()->find('copilot')->enabled)->toBeFalse()
        ->and(AiAgentSetting::query()->find('copilot')->position)->toBe(2);
});

it('writes nothing but agent, field and value into the audit meta', function () {
    ($this->q)()->setModel($this->admin, 'codex', 'gpt-x');
    ($this->q)()->move($this->admin, 'copilot', 'up');

    foreach (($this->audits)() as $row) {
        expect(array_keys($row->meta))->toBe(['agent', 'field', 'value']);
    }
});
