<?php

namespace App\Recipes\Ai;

use App\Medical\Ai\ExtractionOutcome;
use App\Medical\Ai\FailureCode;
use App\Recipes\PlainText;
use App\Shopping\NameKey;
use JsonException;

/**
 * Server-side re-validation and normalisation of a model reply. The structure is strict (exact keys and types, else
 * invalid_output); text is cleaned and cut to the limits and out-of-range numbers become null. No value is ever put
 * into an exception message, an issue code or a log line.
 */
final class RecipeValidator
{
    /** The Shopping unit keys the model may use (the built-in units of the shopping catalog). */
    public const UNITS = ['g', 'kg', 'ml', 'l', 'pcs', 'pack', 'bunch', 'tbsp', 'tsp'];

    private const MAX_ISSUES = 20;

    /**
     * Field specs. text: string cut to max (line = single line, req = must not end up empty), int: integer in
     * [min, max], number: positive number up to max with 2 decimals, list: array of items, unit: one of UNITS.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function ruleTable(): array
    {
        $c = fn (string $key) => (int) config('recipes.'.$key);

        return [
            'root' => [
                'schema_version' => ['type' => 'version'],
                'is_recipe' => ['type' => 'bool'],
                'title' => ['type' => 'text', 'max' => $c('title_max'), 'line' => true],
                'description' => ['type' => 'text', 'max' => $c('description_max'), 'line' => false],
                'servings' => ['type' => 'int', 'min' => $c('servings_min'), 'max' => $c('servings_max')],
                'prep_minutes' => ['type' => 'int', 'min' => 0, 'max' => $c('minutes_max')],
                'cook_minutes' => ['type' => 'int', 'min' => 0, 'max' => $c('minutes_max')],
                'categories' => ['type' => 'list', 'of' => 'category', 'maxItems' => $c('categories_max')],
                'ingredients' => ['type' => 'list', 'of' => 'ingredient', 'maxItems' => $c('ingredients_max')],
                'steps' => ['type' => 'list', 'of' => 'step', 'maxItems' => $c('steps_max')],
            ],
            // List item rules: a plain string each.
            'category' => ['value' => ['type' => 'text', 'max' => $c('category_max'), 'line' => true]],
            'step' => ['value' => ['type' => 'text', 'max' => $c('step_max'), 'line' => false]],
            'ingredient' => [
                'name' => ['type' => 'text', 'max' => $c('ingredient_name_max'), 'line' => true, 'req' => true],
                'quantity' => ['type' => 'number', 'max' => $c('quantity_max')],
                'unit' => ['type' => 'unit'],
                'note' => ['type' => 'text', 'max' => $c('ingredient_note_max'), 'line' => true],
                'original' => ['type' => 'text', 'max' => 200, 'line' => true],
            ],
        ];
    }

    public function fromFile(string $path): ExtractionOutcome
    {
        if (! is_file($path)) {
            return ExtractionOutcome::failed(FailureCode::NoOutput);
        }
        $size = filesize($path);
        if ($size === false || $size === 0) {
            return ExtractionOutcome::failed(FailureCode::NoOutput);
        }
        if ($size > (int) config('ai.caps.result', 262144)) {
            return ExtractionOutcome::failed(FailureCode::OutputTooLarge);
        }
        $json = file_get_contents($path);

        return $json === false ? ExtractionOutcome::failed(FailureCode::NoOutput) : $this->validate($json);
    }

    public function validate(string $json): ExtractionOutcome
    {
        return $this->inspect($json)['outcome'];
    }

    /**
     * Structural issue codes (rule-table names only, never a value), at most 20, and the outcome.
     *
     * @return array{outcome: ExtractionOutcome, issues: list<string>}
     */
    public function inspect(string $json): array
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['outcome' => ExtractionOutcome::failed(FailureCode::InvalidOutput), 'issues' => ['json_syntax']];
        }
        if (! is_array($data) || (array_is_list($data) && $data !== [])) {
            return ['outcome' => ExtractionOutcome::failed(FailureCode::InvalidOutput), 'issues' => ['root:not_object']];
        }

        $issues = [];
        $payload = $this->object($data, 'root', 'root', $issues);
        $issues = array_slice($issues, 0, self::MAX_ISSUES);
        if ($payload === null) {
            return ['outcome' => ExtractionOutcome::failed(FailureCode::InvalidOutput), 'issues' => $issues];
        }
        if ($payload['is_recipe'] === false) {
            return ['outcome' => ExtractionOutcome::failed(FailureCode::NoRecipe), 'issues' => []];
        }
        if ($payload['ingredients'] === []) {
            $issues[] = 'ingredients:empty';
        }
        if ($payload['steps'] === []) {
            $issues[] = 'steps:empty';
        }
        if ($issues !== []) {
            return ['outcome' => ExtractionOutcome::failed(FailureCode::InvalidOutput), 'issues' => $issues];
        }

        return ['outcome' => ExtractionOutcome::success($payload, count($payload['ingredients'])), 'issues' => []];
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $issues
     * @return array<string, mixed>|null
     */
    private function object(array $data, string $name, string $path, array &$issues): ?array
    {
        $rules = self::ruleTable()[$name];
        if (array_is_list($data) && $data !== []) {
            $issues[] = $path.':not_object';

            return null;
        }
        $ok = true;
        foreach (array_keys($data) as $key) {
            if (! array_key_exists($key, $rules)) {
                $issues[] = $path.':unexpected_key';
                $ok = false;
                break;
            }
        }
        $out = [];
        foreach ($rules as $field => $spec) {
            if (! array_key_exists($field, $data)) {
                $issues[] = $path.'.'.$field.':missing';
                $ok = false;

                continue;
            }
            $value = $this->field($data[$field], $spec, $path.'.'.$field, $issues);
            if ($value === false) {
                $ok = false;

                continue;
            }
            $out[$field] = $value[0];
        }

        return $ok ? $out : null;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $issues
     * @return array{0: mixed}|false false = structurally invalid (an issue was recorded)
     */
    private function field(mixed $value, array $spec, string $path, array &$issues): array|false
    {
        $bad = function (string $why) use (&$issues, $path): false {
            $issues[] = $path.':'.$why;

            return false;
        };

        switch ($spec['type']) {
            case 'version':
                return $value === 1 ? [1] : $bad('bad_value');
            case 'bool':
                return is_bool($value) ? [$value] : $bad('bad_type');
            case 'text':
                if ($value === null) {
                    return empty($spec['req']) ? [null] : $bad('bad_type');
                }
                if (! is_string($value)) {
                    return $bad('bad_type');
                }
                $text = $this->text($value, (int) $spec['max'], (bool) $spec['line']);
                if ($text === '') {
                    return empty($spec['req']) ? [null] : $bad('empty');
                }

                return [$text];
            case 'int':
                if ($value === null) {
                    return [null];
                }
                if (! is_int($value) && ! is_float($value)) {
                    return $bad('bad_type');
                }
                $int = is_float($value) ? (floor($value) === $value && abs($value) < 1e9 ? (int) $value : null) : $value;

                return [$int !== null && $int >= $spec['min'] && $int <= $spec['max'] ? $int : null];
            case 'number':
                if ($value === null) {
                    return [null];
                }
                if (! is_int($value) && ! is_float($value)) {
                    return $bad('bad_type');
                }
                $n = round((float) $value, 2);

                return [is_finite($n) && $n > 0 && $n <= $spec['max'] ? $n : null];
            case 'unit':
                if ($value === null) {
                    return [null];
                }

                return is_string($value) ? [$value] : $bad('bad_type');
            case 'list':
                if (! is_array($value) || ! array_is_list($value)) {
                    return $bad('bad_type');
                }

                return $this->list($value, $spec, $path, $issues);
        }

        return $bad('bad_type');
    }

    /**
     * @param  array<mixed>  $value
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $issues
     * @return array{0: list<mixed>}|false
     */
    private function list(array $value, array $spec, string $path, array &$issues): array|false
    {
        $of = (string) $spec['of'];
        $items = [];
        $ok = true;
        foreach ($value as $i => $item) {
            // Issue codes name the list, not the root: steps[1], ingredients[0].name.
            $itemPath = preg_replace('/^root\./', '', $path).'['.$i.']';
            if ($of === 'ingredient') {
                // A row without a usable name (a section header such as "For the sauce:", or a bare quantity) is
                // dropped like a blank step; the reply only fails when nothing usable remains (ingredients:empty).
                if ($this->isBlankNamedIngredient($item)) {
                    continue;
                }
                $clean = is_array($item) ? $this->object($item, 'ingredient', $itemPath, $issues) : null;
                if ($clean === null) {
                    if (! is_array($item)) {
                        $issues[] = $itemPath.':not_object';
                    }
                    $ok = false;

                    continue;
                }
                $items[] = $this->unit($clean);

                continue;
            }
            if (! is_string($item)) {
                $issues[] = $itemPath.':bad_type';
                $ok = false;

                continue;
            }
            $rule = self::ruleTable()[$of]['value'];
            $text = $this->text($item, (int) $rule['max'], (bool) $rule['line']);
            if ($text !== '') {
                $items[] = $text;
            }
        }
        if (! $ok) {
            return false;
        }

        // Ingredients and steps are never cut silently: too many is a structural error (the Copilot repair pass can ask
        // for fewer). Categories are a few labels with duplicates removed, so keeping the first ones is the contract.
        if ($of !== 'category' && count($items) > (int) $spec['maxItems']) {
            $issues[] = preg_replace('/^root\./', '', $path).':too_many';

            return false;
        }

        if ($of === 'category') {
            $seen = [];
            $items = array_values(array_filter($items, function (string $t) use (&$seen) {
                $key = NameKey::from($t);

                return isset($seen[$key]) ? false : ($seen[$key] = true);
            }));
        }

        return [array_slice($items, 0, (int) $spec['maxItems'])];
    }

    /** True for a well-formed ingredient object whose name is missing, null or blank once cleaned. */
    private function isBlankNamedIngredient(mixed $item): bool
    {
        if (! is_array($item) || array_is_list($item)) {
            return false;
        }
        $rules = self::ruleTable()['ingredient'];
        if (array_diff_key($item, $rules) !== [] || array_diff_key($rules, $item) !== []) {
            return false;
        }
        $name = $item['name'];
        if ($name !== null && ! is_string($name)) {
            return false;
        }

        return $this->text((string) $name, (int) $rules['name']['max'], true) === '';
    }

    /**
     * An unknown unit becomes null and its word is appended to the note.
     *
     * @param  array<string, mixed>  $ing
     * @return array<string, mixed>
     */
    private function unit(array $ing): array
    {
        $unit = $ing['unit'];
        if ($unit === null || in_array($unit, self::UNITS, true)) {
            return $ing;
        }
        $word = $this->text($unit, 20, true);
        $ing['unit'] = null;
        if ($word !== '') {
            $note = trim(($ing['note'] !== null ? $ing['note'].' ' : '').$word);
            $ing['note'] = $this->text($note, (int) config('recipes.ingredient_note_max'), true);
        }

        return $ing;
    }

    /**
     * Markup removed (never strip_tags, see PlainText: a literal "<180" must survive) and entities decoded; control characters (newline kept unless single line), zero-width and bidi characters removed;
     * whitespace collapsed; cut to $max characters.
     */
    private function text(string $value, int $max, bool $singleLine): string
    {
        $text = PlainText::strip($value);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace(["\t", "\u{00A0}"], ' ', $text);
        if ($singleLine) {
            $text = str_replace("\n", ' ', $text);
        }
        $text = preg_replace('/[^\P{Cc}\n]|[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}\x{2028}\x{2029}]/u', '', $text) ?? '';
        $text = preg_replace('/ {2,}/', ' ', $text) ?? '';
        $text = preg_replace('/ ?\n ?/', "\n", $text) ?? '';
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? '';

        return trim(mb_substr(trim($text), 0, $max));
    }
}
