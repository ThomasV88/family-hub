<?php

namespace App\Medical\Ai;

use App\Medical\PlainText;
use App\Medical\Specialty;
use JsonException;

/**
 * Server-side re-validation and normalisation of the CLI result. Any deviation is a failed outcome with no payload.
 * No field value is ever put into an exception message or returned in an outcome.
 */
final class ExtractionValidator
{
    private const MAX_ITEMS = 25;

    private const MAX_STRING = 4000;

    private const MAX_ISSUES = 20;

    private const SEVERITIES = ['mild', 'moderate', 'severe', 'unknown'];

    private const LISTS = ['visits' => 'visit', 'medications' => 'medication', 'allergies' => 'allergy', 'vaccinations' => 'vaccination'];

    /**
     * Spec names: version, date, specialty, severity, string (nullable), req (required non-null string), list:<object>.
     *
     * @return array<string, array<string, string>>
     */
    public static function ruleTable(): array
    {
        return [
            'root' => ['schema_version' => 'version', 'document_date' => 'date', 'specialty_key' => 'specialty',
                'visits' => 'list:visit', 'medications' => 'list:medication', 'allergies' => 'list:allergy', 'vaccinations' => 'list:vaccination'],
            'visit' => ['visited_on' => 'date', 'doctor' => 'string', 'specialty_key' => 'specialty', 'specialty_other' => 'string', 'diagnosis' => 'string', 'notes' => 'string'],
            'medication' => ['name' => 'req', 'dose' => 'string', 'schedule' => 'string', 'started_on' => 'date', 'ended_on' => 'date', 'notes' => 'string'],
            'allergy' => ['substance' => 'req', 'reaction' => 'string', 'severity' => 'severity'],
            'vaccination' => ['vaccine' => 'req', 'given_on' => 'date', 'next_due_on' => 'date', 'notes' => 'string'],
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
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            $payload = is_array($data) ? $this->object($data, 'root') : null;
        } catch (JsonException) {
            $payload = null;
        }
        if ($payload === null) {
            return ExtractionOutcome::failed(FailureCode::InvalidOutput);
        }
        $count = 0;
        foreach (array_keys(self::LISTS) as $list) {
            $count += count($payload[$list]);
        }

        return ExtractionOutcome::success($payload, $count);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, mixed>|null
     */
    private function object(array $data, string $name): ?array
    {
        $rules = self::ruleTable()[$name];
        $keys = array_keys($data);
        if (array_is_list($data) && $data !== [] || count($keys) !== count($rules) || array_diff($keys, array_keys($rules)) !== []) {
            return null;
        }
        $out = [];
        foreach ($rules as $field => $spec) {
            $value = $this->field($data[$field], $spec);
            if ($value === false) {
                return null;
            }
            $out[$field] = $value[0];
        }

        return $out;
    }

    /** @return array{0: mixed}|false false = invalid */
    private function field(mixed $value, string $spec): array|false
    {
        if (str_starts_with($spec, 'list:')) {
            if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_ITEMS) {
                return false;
            }
            $items = [];
            foreach ($value as $item) {
                $clean = is_array($item) ? $this->object($item, substr($spec, 5)) : null;
                if ($clean === null) {
                    return false;
                }
                $items[] = $clean;
            }

            return [$items];
        }

        switch ($spec) {
            case 'version':
                return $value === ExtractionSchema::SCHEMA_VERSION ? [$value] : false;
            case 'date':
                if ($value === null) {
                    return [null];
                }

                return is_string($value) ? [$this->date($value)] : false;
            case 'specialty':
            case 'severity':
                if ($value === null) {
                    return [null];
                }
                $allowed = $spec === 'severity' ? self::SEVERITIES : [...array_map(fn (Specialty $s) => $s->value, Specialty::cases()), 'other'];

                return is_string($value) && in_array($value, $allowed, true) ? [$value] : false;
            case 'req':
            case 'string':
                if ($value === null && $spec === 'string') {
                    return [null];
                }
                if (! is_string($value) || mb_strlen($value) > self::MAX_STRING) {
                    return false;
                }
                $text = trim((string) PlainText::clean($value));
                if ($text === '') {
                    return $spec === 'req' ? false : [null];
                }

                return [$text];
        }

        return false;
    }

    /**
     * Structural issue codes for a reply, taken from the rule table only (never a key name or value), at most 20.
     * The outcome is exactly what validate() returns.
     *
     * @return array{outcome: ExtractionOutcome, issues: list<string>}
     */
    public function inspect(string $json): array
    {
        $outcome = $this->validate($json);
        $issues = [];
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['outcome' => $outcome, 'issues' => ['json_syntax']];
        }
        if (! is_array($data) || (array_is_list($data) && $data !== [])) {
            return ['outcome' => $outcome, 'issues' => ['root:not_object']];
        }
        $this->inspectObject($data, 'root', 'root', $issues);

        return ['outcome' => $outcome, 'issues' => array_slice($issues, 0, self::MAX_ISSUES)];
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $issues
     */
    private function inspectObject(array $data, string $name, string $path, array &$issues): void
    {
        $rules = self::ruleTable()[$name];
        foreach (array_keys($rules) as $field) {
            if (! array_key_exists($field, $data)) {
                $this->issue($issues, $path.':keys_missing:'.$field);
            }
        }
        if (array_diff(array_keys($data), array_keys($rules)) !== []) {
            $this->issue($issues, $path.':keys_unexpected');
        }
        $prefix = $name === 'root' ? 'root' : $path;
        foreach ($rules as $field => $spec) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (str_starts_with($spec, 'list:')) {
                if (! is_array($value) || ! array_is_list($value)) {
                    $this->issue($issues, 'root.'.$field.':type');

                    continue;
                }
                if (count($value) > self::MAX_ITEMS) {
                    $this->issue($issues, $field.':too_many_items');
                }
                foreach ($value as $i => $item) {
                    $itemPath = $field.'['.$i.']';
                    if (! is_array($item) || (array_is_list($item) && $item !== [])) {
                        $this->issue($issues, $field.'['.$i.']:not_object');

                        continue;
                    }
                    $this->inspectObject($item, substr($spec, 5), $itemPath, $issues);
                }

                continue;
            }
            $rule = $this->fieldRule($value, $spec);
            if ($rule !== null) {
                $this->issue($issues, ($name === 'root' && $field === 'schema_version' ? 'root' : $prefix.'.'.$field).':'.$rule);
            }
        }
    }

    private function fieldRule(mixed $value, string $spec): ?string
    {
        switch ($spec) {
            case 'version':
                return $value === ExtractionSchema::SCHEMA_VERSION ? null : 'schema_version';
            case 'date':
                if ($value === null) {
                    return null;
                }

                return is_string($value) ? ($this->date($value) === null ? 'date_format' : null) : 'type';
            case 'specialty':
            case 'severity':
                if ($value === null) {
                    return null;
                }
                $allowed = $spec === 'severity' ? self::SEVERITIES : [...array_map(fn (Specialty $s) => $s->value, Specialty::cases()), 'other'];

                return is_string($value) ? (in_array($value, $allowed, true) ? null : 'enum') : 'type';
            case 'req':
            case 'string':
                if ($value === null) {
                    return $spec === 'req' ? 'required_empty' : null;
                }
                if (! is_string($value)) {
                    return 'type';
                }
                if (mb_strlen($value) > self::MAX_STRING) {
                    return 'too_long';
                }

                return $spec === 'req' && trim((string) PlainText::clean($value)) === '' ? 'required_empty' : null;
        }

        return 'type';
    }

    /** @param list<string> $issues */
    private function issue(array &$issues, string $code): void
    {
        if (count($issues) < self::MAX_ISSUES) {
            $issues[] = $code;
        }
    }

    private function date(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        if ($value < '1900-01-01' || $value > now()->addYears(10)->format('Y-m-d')) {
            return null;
        }

        return $value;
    }
}
