<?php

use App\Medical\Ai\ExtractionOutcome;
use App\Medical\Ai\FailureCode;
use App\Medical\Ai\PromptBuilder;
use App\Recipes\Ai\RecipeTask;
use App\Recipes\Ai\RecipeValidator;
use Tests\TestCase;

uses(TestCase::class);

function rvPayload(array $over = []): array
{
    return array_replace([
        'schema_version' => 1,
        'is_recipe' => true,
        'title' => 'Tomato soup',
        'description' => 'A simple soup.',
        'servings' => 4,
        'prep_minutes' => 10,
        'cook_minutes' => 30,
        'categories' => ['soup'],
        'ingredients' => [
            ['name' => 'Tomatoes', 'quantity' => 500, 'unit' => 'g', 'note' => null, 'original' => '500 g tomatoes'],
            ['name' => 'Salt', 'quantity' => null, 'unit' => null, 'note' => 'to taste', 'original' => 'Salt to taste'],
        ],
        'steps' => ['Chop.', 'Cook.'],
    ], $over);
}

function rvRun(array|string $payload): ExtractionOutcome
{
    return (new RecipeValidator)->validate(is_string($payload) ? $payload : json_encode($payload));
}

it('keeps the schema file and the rule table in step', function () {
    $schema = json_decode(file_get_contents(RecipeTask::schemaFile()), true);
    $rules = RecipeValidator::ruleTable();

    expect($schema['additionalProperties'])->toBeFalse()
        ->and($schema['required'])->toEqualCanonicalizing(array_keys($schema['properties']))
        ->and(array_keys($rules['root']))->toEqualCanonicalizing(array_keys($schema['properties']))
        ->and($schema['properties']['schema_version']['enum'])->toBe([1]);

    foreach (['servings', 'prep_minutes', 'cook_minutes'] as $f) {
        expect($schema['properties'][$f]['minimum'])->toBe($rules['root'][$f]['min'])
            ->and($schema['properties'][$f]['maximum'])->toBe($rules['root'][$f]['max']);
    }
    foreach (['categories' => 'category', 'ingredients' => 'ingredient', 'steps' => 'step'] as $list => $of) {
        expect($schema['properties'][$list]['maxItems'])->toBe($rules['root'][$list]['maxItems'])
            ->and($rules['root'][$list]['of'])->toBe($of);
    }

    $item = $schema['properties']['ingredients']['items'];
    expect($item['additionalProperties'])->toBeFalse()
        ->and($item['required'])->toEqualCanonicalizing(array_keys($item['properties']))
        ->and(array_keys($rules['ingredient']))->toEqualCanonicalizing(array_keys($item['properties']))
        ->and(array_values(array_filter($item['properties']['unit']['enum'])))->toBe(RecipeValidator::UNITS)
        ->and(end($item['properties']['unit']['enum']))->toBeNull();

    // Strict-mode schemas of the CLI must not carry maxLength (see the medical schema); the validator cuts instead.
    expect(file_get_contents(RecipeTask::schemaFile()))->not->toContain('maxLength')->not->toContain('allOf');
});

it('passes a valid payload with the ingredient count', function () {
    $o = rvRun(rvPayload());

    expect($o->ok)->toBeTrue()->and($o->entryCount)->toBe(2)->and($o->payload)->toBe(rvPayload(['ingredients' => [
        ['name' => 'Tomatoes', 'quantity' => 500.0, 'unit' => 'g', 'note' => null, 'original' => '500 g tomatoes'],
        ['name' => 'Salt', 'quantity' => null, 'unit' => null, 'note' => 'to taste', 'original' => 'Salt to taste'],
    ]]));
});

it('fails with no_recipe when is_recipe is false', function () {
    $o = rvRun(rvPayload(['is_recipe' => false, 'title' => null, 'ingredients' => [], 'steps' => []]));

    expect($o->ok)->toBeFalse()->and($o->failure)->toBe(FailureCode::NoRecipe);
});

it('fails with invalid_output without steps or ingredients and on structural errors', function (array|string $payload) {
    $o = rvRun($payload);

    expect($o->ok)->toBeFalse()->and($o->failure)->toBe(FailureCode::InvalidOutput);
})->with([
    'no steps' => fn () => rvPayload(['steps' => []]),
    'no ingredients' => fn () => rvPayload(['ingredients' => []]),
    'blank steps only' => fn () => rvPayload(['steps' => ['  ', '<br>']]),
    'not json' => '{nope',
    'a list' => '[1]',
    'version 2' => fn () => rvPayload(['schema_version' => 2]),
    'extra key' => fn () => rvPayload(['url' => 'https://x.example']),
    'missing key' => fn () => array_diff_key(rvPayload(), ['steps' => 1]),
    'title not a string' => fn () => rvPayload(['title' => 5]),
    'ingredient without a name' => fn () => rvPayload(['ingredients' => [['name' => '  ', 'quantity' => 1, 'unit' => null, 'note' => null, 'original' => null]]]),
    'ingredient extra key' => fn () => rvPayload(['ingredients' => [['name' => 'a', 'quantity' => 1, 'unit' => null, 'note' => null, 'original' => null, 'x' => 1]]]),
    'quantity as text' => fn () => rvPayload(['ingredients' => [['name' => 'a', 'quantity' => '1/2', 'unit' => null, 'note' => null, 'original' => null]]]),
]);

it('cleans tags, control, zero-width and bidi characters', function () {
    $o = rvRun(rvPayload(['title' => "  <b>Soup</b>\u{200B}\u{202E} of\x01 the\t day  "]));

    expect($o->payload['title'])->toBe('Soup of the day');
});

it('turns an unknown unit into null with the word in the note, and bad quantities into null', function () {
    $ing = fn (mixed $q, ?string $unit, ?string $note = null) => ['name' => 'x', 'quantity' => $q, 'unit' => $unit, 'note' => $note, 'original' => null];
    $o = rvRun(rvPayload(['ingredients' => [$ing(2, 'cup'), $ing(1, 'cup', 'warm'), $ing(0, 'g'), $ing(1e7, 'g'), $ing(-3, null), $ing(1.239, 'kg'), $ing(1, 'g')]]));
    $rows = $o->payload['ingredients'];

    expect($rows[0]['unit'])->toBeNull()->and($rows[0]['note'])->toBe('cup')->and($rows[0]['quantity'])->toBe(2.0)
        ->and($rows[1]['note'])->toBe('warm cup')
        ->and($rows[2]['quantity'])->toBeNull()
        ->and($rows[3]['quantity'])->toBeNull()
        ->and($rows[4]['quantity'])->toBeNull()
        ->and($rows[5]['quantity'])->toBe(1.24)
        ->and($rows[6]['unit'])->toBe('g');
});

it('limits categories, lengths and numbers', function () {
    $o = rvRun(rvPayload([
        'categories' => ['Soup', 'soup', 'Dinner', 'Easy', 'Quick', 'Winter'],
        'steps' => [str_repeat('é', 2000)],
        'title' => str_repeat('t', 300),
        'servings' => 500,
        'prep_minutes' => -1,
        'cook_minutes' => 99999,
    ]));

    expect($o->payload['categories'])->toBe(['Soup', 'Dinner', 'Easy'])
        ->and(mb_strlen($o->payload['steps'][0]))->toBe(1000)
        ->and(mb_strlen($o->payload['title']))->toBe(120)
        ->and($o->payload['servings'])->toBeNull()
        ->and($o->payload['prep_minutes'])->toBeNull()
        ->and($o->payload['cook_minutes'])->toBeNull();
});

it('reports issue codes without any value', function () {
    $secret = 'SECRET-CANARY';
    $r = (new RecipeValidator)->inspect(json_encode(rvPayload(['title' => 5, 'steps' => [$secret, 7], 'zz'.$secret => 1])));

    expect($r['outcome']->ok)->toBeFalse()
        ->and(implode(' ', $r['issues']))->not->toContain($secret)
        ->and($r['issues'])->toContain('root.title:bad_type', 'steps[1]:bad_type');
    foreach ($r['issues'] as $code) {
        expect($code)->toMatch(PromptBuilder::ISSUE_PATTERN);
    }
});

it('reads a result file and refuses a missing or empty one', function () {
    $path = sys_get_temp_dir().'/rv-'.bin2hex(random_bytes(4)).'.json';
    expect((new RecipeValidator)->fromFile($path)->failure)->toBe(FailureCode::NoOutput);
    file_put_contents($path, '');
    expect((new RecipeValidator)->fromFile($path)->failure)->toBe(FailureCode::NoOutput);
    file_put_contents($path, json_encode(rvPayload()));
    expect((new RecipeValidator)->fromFile($path)->ok)->toBeTrue();
    @unlink($path);
});

it('keeps a literal less-than sign in step and ingredient text (never strip_tags)', function () {
    $o = rvRun(rvPayload([
        'title' => 'Roast <b>chicken</b>',
        'steps' => ['Bake <180 °C until golden, then reduce to <160 °C.', 'Rest < 5 min <br> then serve', 'Heat to <b>200</b> °C'],
        'ingredients' => [['name' => 'Oil <1 tbsp', 'quantity' => null, 'unit' => null, 'note' => '<script>alert(1)</script>fresh', 'original' => null]],
    ]));

    expect($o->ok)->toBeTrue()
        ->and($o->payload['title'])->toBe('Roast chicken')
        ->and($o->payload['steps'])->toBe(['Bake <180 °C until golden, then reduce to <160 °C.', 'Rest < 5 min then serve', 'Heat to 200 °C'])
        ->and($o->payload['ingredients'][0]['name'])->toBe('Oil <1 tbsp')
        ->and($o->payload['ingredients'][0]['note'])->toBe('fresh');
});

it('decodes entities but never lets them bring a tag back', function () {
    $o = rvRun(rvPayload(['title' => 'Tom &amp; Jerry &lt;script&gt;x&lt;/script&gt; soup', 'steps' => ['Mix &lt;180 &deg;C']]));

    expect($o->payload['title'])->toBe('Tom & Jerry soup')->and($o->payload['steps'])->toBe(['Mix <180 °C']);
});

it('drops an ingredient row without a name and fails only when nothing usable remains', function () {
    $row = fn (?string $name) => ['name' => $name, 'quantity' => null, 'unit' => null, 'note' => null, 'original' => null];
    $ok = rvRun(rvPayload(['ingredients' => [$row('For the sauce:'), $row('  '), $row(null), $row('Tomatoes')]]));

    expect($ok->ok)->toBeTrue()->and($ok->entryCount)->toBe(2)
        ->and(array_column($ok->payload['ingredients'], 'name'))->toBe(['For the sauce:', 'Tomatoes']);

    $none = (new RecipeValidator)->inspect(json_encode(rvPayload(['ingredients' => [$row(' '), $row('<br>')]])));
    expect($none['outcome']->ok)->toBeFalse()->and($none['issues'])->toBe(['ingredients:empty']);
});

it('rejects too many ingredients or steps instead of cutting them silently', function () {
    $many = array_fill(0, (int) config('recipes.steps_max') + 1, 'Stir.');
    $r = (new RecipeValidator)->inspect(json_encode(rvPayload(['steps' => $many])));

    expect($r['outcome']->ok)->toBeFalse()->and($r['issues'])->toContain('steps:too_many');

    $ing = array_fill(0, (int) config('recipes.ingredients_max') + 1, ['name' => 'x', 'quantity' => null, 'unit' => null, 'note' => null, 'original' => null]);
    $r = (new RecipeValidator)->inspect(json_encode(rvPayload(['ingredients' => $ing])));
    expect($r['issues'])->toContain('ingredients:too_many');

    $exact = array_fill(0, (int) config('recipes.steps_max'), 'Stir.');
    expect(rvRun(rvPayload(['steps' => $exact]))->ok)->toBeTrue();
});
