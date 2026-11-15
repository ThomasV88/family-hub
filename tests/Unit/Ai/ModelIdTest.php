<?php

use App\Medical\Ai\Agents\AgentInput;
use App\Medical\Ai\Agents\AgentOutcome;
use App\Medical\Ai\FailureCode;
use App\Medical\Ai\ModelId;
use App\Medical\Ai\ProcessResult;
use Tests\TestCase;

uses(TestCase::class);

it('accepts valid model ids', function (string $id) {
    expect(ModelId::isValid($id))->toBeTrue();
})->with(['gpt-5.6-sol', 'claude-haiku-4.5', 'o4:mini', 'a', str_repeat('a', 60)]);

it('refuses invalid model ids', function (string $id) {
    expect(ModelId::isValid($id))->toBeFalse();
})->with(['', '-m', '.x', 'a b', 'a/b', str_repeat('a', 61), "abc\n"]);

it('carries the phase 9 config defaults', function () {
    expect(config('ai.timeouts.min_call'))->toBe(30)
        ->and(config('ai.timeouts.job'))->toBe(660)
        ->and(config('ai.bulk.default_cap'))->toBeLessThanOrEqual(config('ai.bulk.max_cap'))
        ->and(config('ai.bulk.queue'))->toBe('ai-bulk')
        ->and(config('ai.quota.ladder'))->toBe([60, 180, 720, 1440]);

    foreach (['GH_TOKEN', 'GITHUB_TOKEN', 'COPILOT_GITHUB_TOKEN', 'COPILOT_ALLOW_ALL', 'COPILOT_PROVIDER_BASE_URL',
        'COPILOT_PROVIDER_API_KEY', 'COPILOT_PROVIDER_API_KEY_COMMAND', 'COPILOT_PROVIDER_BEARER_TOKEN',
        'COPILOT_OFFLINE', 'OPENAI_API_KEY'] as $name) {
        expect(config('ai.forbidden_env'))->toContain($name);
    }
});

it('has failure codes that fit the column', function () {
    expect(FailureCode::from('quota_timeout'))->toBe(FailureCode::QuotaTimeout)
        ->and(FailureCode::from('notice_required'))->toBe(FailureCode::NoticeRequired);
    foreach (FailureCode::cases() as $case) {
        expect(strlen($case->value))->toBeLessThanOrEqual(24);
    }
});

it('keeps phase 8 constructor shapes valid', function () {
    $outcome = new AgentOutcome(false, null, null, FailureCode::Timeout, false, 5, 'default');
    $result = new ProcessResult(0, false, false, false, false, false, 10);
    $input = (new ReflectionClass(AgentInput::class))->getConstructor();

    expect($outcome->quotaResetAt)->toBeNull()->and($outcome->quotaMinutes)->toBeNull()
        ->and($result->quotaSignature)->toBeFalse()->and($result->quotaMinutes)->toBeNull()
        ->and($result->quotaResetAt)->toBeNull()
        ->and($input->getNumberOfRequiredParameters())->toBe(2);
});
