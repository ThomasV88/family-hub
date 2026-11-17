<?php

namespace App\Medical\Ai\Transcribe;

use App\Medical\Ai\ExtractionOutcome;
use App\Medical\Ai\FailureCode;
use JsonException;

/**
 * Strict check of one transcription reply (one batch of pages). Anything that deviates is a failed outcome with no
 * payload. Issue codes name a place and a kind only; no text of the reply ever enters an outcome, a code or an exception.
 */
final class TranscriptionValidator
{
    private const LANGUAGES = ['sk', 'pl', 'en', 'mixed', 'other'];

    public function fromFile(string $path, int $expectedPages): ExtractionOutcome
    {
        if (! is_file($path)) {
            return ExtractionOutcome::failed(FailureCode::NoOutput);
        }
        $size = filesize($path);
        if ($size === false || $size === 0) {
            return ExtractionOutcome::failed(FailureCode::NoOutput);
        }
        if ($size > (int) config('ai.ocr.result_cap', 524288)) {
            return ExtractionOutcome::failed(FailureCode::OutputTooLarge);
        }
        $json = file_get_contents($path);

        return $json === false ? ExtractionOutcome::failed(FailureCode::NoOutput) : $this->inspect($json, $expectedPages)['outcome'];
    }

    /**
     * Success payload: ['language' => ?string, 'pages' => list<string>] with the texts ordered by page number.
     *
     * @return array{outcome: ExtractionOutcome, issues: list<string>}
     */
    public function inspect(string $json, int $expectedPages): array
    {
        try {
            $data = json_decode($json, true, 12, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->failed('root:bad_value');
        }
        if (! is_array($data) || ($data !== [] && array_is_list($data)) || ! self::sameKeys(array_keys($data), ['schema_version', 'language', 'pages'])) {
            return $this->failed('root:bad_value');
        }
        if (($data['schema_version'] ?? null) !== 1) {
            return $this->failed('schema_version:bad_value');
        }
        $language = $data['language'];
        if ($language !== null && (! is_string($language) || ! in_array($language, self::LANGUAGES, true))) {
            return $this->failed('language:bad_value');
        }
        $pages = $data['pages'];
        if (! is_array($pages) || ! array_is_list($pages)) {
            return $this->failed('pages:bad_value');
        }

        $maxPage = (int) config('ai.ocr.max_page_chars', 12000);
        $byNumber = [];
        $total = 0;
        foreach ($pages as $i => $page) {
            if (! is_array($page) || ! self::sameKeys(array_keys($page), ['page', 'legible', 'text'])) {
                return $this->failed("pages[$i]:bad_value");
            }
            if (! is_int($page['page']) || ! is_bool($page['legible']) || ! is_string($page['text'])) {
                return $this->failed("pages[$i]:bad_value");
            }
            $length = mb_strlen($page['text']);
            if ($length > $maxPage) {
                return $this->failed("pages[$i].text:too_long");
            }
            $total += $length;
            if (isset($byNumber[$page['page']])) {
                return $this->failed('pages:bad_value');
            }
            $byNumber[$page['page']] = (! $page['legible'] && trim($page['text']) === '') ? '' : $page['text'];
        }
        if ($total > (int) config('ai.ocr.max_total_chars', 200000)) {
            return $this->failed('pages:too_long');
        }
        $numbers = array_keys($byNumber);
        sort($numbers);
        if ($numbers !== ($expectedPages > 0 ? range(1, $expectedPages) : [])) {
            return $this->failed('pages:bad_value');
        }
        ksort($byNumber);
        $texts = array_values($byNumber);

        return ['outcome' => ExtractionOutcome::success(['language' => $language, 'pages' => $texts], count($texts)), 'issues' => []];
    }

    /**
     * @param  list<int|string>  $a
     * @param  list<string>  $b
     */
    private static function sameKeys(array $a, array $b): bool
    {
        $a = array_map('strval', $a);
        sort($a);
        sort($b);

        return $a === $b;
    }

    /** @return array{outcome: ExtractionOutcome, issues: list<string>} */
    private function failed(string $issue): array
    {
        return ['outcome' => ExtractionOutcome::failed(FailureCode::InvalidOutput), 'issues' => [$issue]];
    }
}
