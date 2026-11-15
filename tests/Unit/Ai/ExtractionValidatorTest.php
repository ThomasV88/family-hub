<?php

use App\Medical\Ai\ExtractionOutcome;
use App\Medical\Ai\ExtractionSchema;
use App\Medical\Ai\ExtractionValidator;
use App\Medical\Ai\FailureCode;
use App\Medical\Ai\ProposalEntries;
use Tests\Support\MedicalPrivacy;
use Tests\TestCase;

uses(TestCase::class);

function goldenPayload(): array
{
    return [
        'schema_version' => 1,
        'document_date' => '2026-03-04',
        'specialty_key' => 'cardiology',
        'visits' => [
            ['visited_on' => '2026-03-04', 'doctor' => 'Dr. Novak', 'specialty_key' => 'cardiology', 'specialty_other' => null, 'diagnosis' => 'Hypertension', 'notes' => 'Control in 3 months'],
            ['visited_on' => null, 'doctor' => null, 'specialty_key' => null, 'specialty_other' => null, 'diagnosis' => null, 'notes' => null],
        ],
        'medications' => [['name' => 'Enalapril', 'dose' => '10 mg', 'schedule' => 'daily', 'started_on' => '2026-03-04', 'ended_on' => null, 'notes' => null]],
        'allergies' => [['substance' => 'Penicillin', 'reaction' => 'Rash', 'severity' => 'moderate']],
        'vaccinations' => [['vaccine' => 'Tetanus', 'given_on' => '2025-01-10', 'next_due_on' => '2035-01-10', 'notes' => null]],
    ];
}

function validate(array|string $payload): ExtractionOutcome
{
    return (new ExtractionValidator)->validate(is_string($payload) ? $payload : json_encode($payload));
}

it('accepts the golden payload unchanged', function () {
    $outcome = validate(goldenPayload());

    expect($outcome->ok)->toBeTrue()
        ->and($outcome->payload)->toBe(goldenPayload())
        ->and($outcome->entryCount)->toBe(5)
        ->and($outcome->failure)->toBeNull();
});

it('fails with invalid_output and no payload on every violation', function (string $case) {
    $p = goldenPayload();
    $json = null;
    switch ($case) {
        case 'bad json': $json = '{not json';
            break;
        case 'top-level array': $json = '[1,2]';
            break;
        case 'too deep': $json = str_repeat('[', 12).str_repeat(']', 12);
            break;
        case 'missing top key': unset($p['allergies']);
            break;
        case 'extra top key': $p['confidence'] = 1;
            break;
        case 'missing entry key': unset($p['visits'][0]['notes']);
            break;
        case 'extra entry key': $p['medications'][0]['quote'] = 'x';
            break;
        case 'wrong type': $p['visits'][0]['doctor'] = 5;
            break;
        case 'object for list': $p['visits'] = ['a' => $p['visits'][0]];
            break;
        case 'unknown specialty': $p['specialty_key'] = 'witchcraft';
            break;
        case 'unknown entry specialty': $p['visits'][0]['specialty_key'] = 'witchcraft';
            break;
        case 'unknown severity': $p['allergies'][0]['severity'] = 'deadly';
            break;
        case 'version 2': $p['schema_version'] = 2;
            break;
        case 'version string': $p['schema_version'] = '1';
            break;
        case '26 visits': $p['visits'] = array_fill(0, 26, $p['visits'][0]);
            break;
        case 'long string': $p['visits'][0]['notes'] = str_repeat('a', 4001);
            break;
        case 'null name': $p['medications'][0]['name'] = null;
            break;
        case 'blank name': $p['allergies'][0]['substance'] = " \u{202E} ";
            break;
        case 'non-string date': $p['document_date'] = 20260304;
            break;
    }
    $outcome = validate($json ?? $p);

    expect($outcome->ok)->toBeFalse()
        ->and($outcome->payload)->toBeNull()
        ->and($outcome->entryCount)->toBeNull()
        ->and($outcome->failure)->toBe(FailureCode::InvalidOutput);
})->with(['bad json', 'top-level array', 'too deep', 'missing top key', 'extra top key', 'missing entry key', 'extra entry key', 'wrong type', 'object for list', 'unknown specialty', 'unknown entry specialty', 'unknown severity', 'version 2', 'version string', '26 visits', 'long string', 'null name', 'blank name', 'non-string date']);

it('accepts exactly 25 entries and 4000 characters', function () {
    $p = goldenPayload();
    $p['visits'] = array_fill(0, 25, $p['visits'][0]);
    $p['visits'][0]['notes'] = str_repeat('a', 4000);

    expect(validate($p)->ok)->toBeTrue();
});

it('maps file problems to failure codes', function () {
    $validator = new ExtractionValidator;
    $dir = sys_get_temp_dir();
    $missing = $dir.'/ev-missing-'.bin2hex(random_bytes(4));
    $empty = tempnam($dir, 'ev');
    $big = tempnam($dir, 'ev');
    file_put_contents($big, str_repeat(' ', 300 * 1024));
    $good = tempnam($dir, 'ev');
    file_put_contents($good, json_encode(goldenPayload()));

    expect($validator->fromFile($missing)->failure)->toBe(FailureCode::NoOutput)
        ->and($validator->fromFile($empty)->failure)->toBe(FailureCode::NoOutput)
        ->and($validator->fromFile($big)->failure)->toBe(FailureCode::OutputTooLarge)
        ->and($validator->fromFile($good)->ok)->toBeTrue();

    array_map('unlink', [$empty, $big, $good]);
});

it('normalises strings and nulls implausible dates', function () {
    $p = goldenPayload();
    $p['visits'][0]['doctor'] = "\u{202E}ab\x07c ";
    $p['visits'][0]['notes'] = '   ';
    $p['visits'][0]['visited_on'] = '2026-02-30';
    $p['medications'][0]['started_on'] = '1850-01-01';
    $p['vaccinations'][0]['next_due_on'] = '2099-01-01';
    $p['document_date'] = '2026-3-4';
    $out = validate($p)->payload;

    expect($out['visits'][0]['doctor'])->toBe('abc')
        ->and($out['visits'][0]['notes'])->toBeNull()
        ->and($out['visits'][0]['visited_on'])->toBeNull()
        ->and($out['medications'][0]['started_on'])->toBeNull()
        ->and($out['vaccinations'][0]['next_due_on'])->toBeNull()
        ->and($out['document_date'])->toBeNull()
        ->and($out['visits'][0]['diagnosis'])->toBe('Hypertension');
});

it('keys and resolves entries in a fixed order', function () {
    $payload = validate(goldenPayload())->payload;

    expect(ProposalEntries::keys($payload))->toBe(['visit-0', 'visit-1', 'medication-0', 'allergy-0', 'vaccination-0'])
        ->and(ProposalEntries::entry($payload, 'visit-1'))->toBe(['type' => 'visit', 'fields' => $payload['visits'][1]])
        ->and(ProposalEntries::entry($payload, 'allergy-0')['fields']['substance'])->toBe('Penicillin')
        ->and(ProposalEntries::entry($payload, 'visit-9'))->toBeNull()
        ->and(ProposalEntries::entry($payload, 'lab-0'))->toBeNull();
    foreach (['visit:0', 'visit-01', 'lab-0', 'visit-100', 'visit-', 'visit-0 '] as $bad) {
        expect(ProposalEntries::isKey($bad))->toBeFalse();
    }
    expect(ProposalEntries::isKey('vaccination-24'))->toBeTrue();
});

it('keeps the schema file and the validator rule table in step', function () {
    $schema = ExtractionSchema::decoded();
    $rules = ExtractionValidator::ruleTable();

    $walk = function (array $node, string $name) use ($rules) {
        expect($node['additionalProperties'])->toBeFalse()
            ->and($node['required'])->toEqualCanonicalizing(array_keys($node['properties']))
            ->and(array_keys($rules[$name]))->toEqualCanonicalizing(array_keys($node['properties']));
    };
    $names = ['visits' => 'visit', 'medications' => 'medication', 'allergies' => 'allergy', 'vaccinations' => 'vaccination'];
    $walk($schema, 'root');
    foreach ($names as $list => $name) {
        expect($schema['properties'][$list]['maxItems'])->toBe(25)
            ->and($rules['root'][$list])->toBe('list:'.$name);
        $walk($schema['properties'][$list]['items'], $name);
    }
    expect($schema['properties']['schema_version']['enum'])->toBe([ExtractionSchema::SCHEMA_VERSION])
        ->and(array_keys($rules))->toEqualCanonicalizing(['root', ...array_values($names)]);

    $raw = file_get_contents(ExtractionSchema::path());
    expect($raw)->not->toContain('allOf')->not->toContain('maxLength')->not->toContain('minLength');
});

function inspectIssues(array|string $payload): array
{
    return (new ExtractionValidator)->inspect(is_string($payload) ? $payload : json_encode($payload));
}

it('inspects the golden payload without issues and describes failures by rule-table codes', function () {
    $golden = inspectIssues(goldenPayload());
    expect($golden['outcome']->ok)->toBeTrue()->and($golden['issues'])->toBe([])
        ->and(inspectIssues('{oops')['issues'])->toBe(['json_syntax'])
        ->and(inspectIssues('[1]')['issues'])->toBe(['root:not_object']);

    $p = goldenPayload();
    $p['visits'][] = $p['visits'][0];
    $p['visits'][2]['visited_on'] = '31.12.2026';
    $p['allergies'][] = ['substance' => 'x', 'reaction' => null, 'severity' => 'deadly'];
    unset($p['visits'][0]['notes']);
    $p['schema_version'] = 2;
    $p['medications'][0]['name'] = null;
    $p['vaccinations'][0]['notes'] = str_repeat('a', 4001);
    $p['document_date'] = 5;
    $issues = inspectIssues($p)['issues'];

    expect($issues)->toContain('visits[2].visited_on:date_format', 'allergies[1].severity:enum', 'visits[0]:keys_missing:notes',
        'root:schema_version', 'medications[0].name:required_empty', 'vaccinations[0].notes:too_long', 'root.document_date:type');
});

it('never echoes a key name or value in issue codes and caps them at 20', function () {
    $c = MedicalPrivacy::CANARY;
    $p = goldenPayload();
    $p[$c] = $c;
    foreach (['document_date', 'specialty_key'] as $f) {
        $p[$f] = $c;
    }
    foreach (['visits' => 'visit', 'medications' => 'medication', 'allergies' => 'allergy', 'vaccinations' => 'vaccination'] as $list => $_) {
        foreach ($p[$list][0] as $field => $v) {
            $p[$list][0][$field] = [$c];
        }
        $p[$list][0][$c] = $c;
    }
    $issues = inspectIssues($p)['issues'];

    expect($issues)->not->toBeEmpty()->and(implode('|', $issues))->not->toContain($c)->not->toContain('Zx');
    foreach ($issues as $issue) {
        expect($issue)->toMatch('/^[a-z_]+(\[\d+\])?(\.[a-z_]+)?:[a-z_]+(:[a-z_]+)?$/');
    }

    $many = goldenPayload();
    $many['visits'] = array_fill(0, 3, array_fill_keys(array_keys($many['visits'][0]), 5));
    $many['allergies'] = array_fill(0, 10, ['substance' => null, 'reaction' => 1, 'severity' => 'x']);

    expect(count(inspectIssues($many)['issues']))->toBe(20);
});
