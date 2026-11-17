<?php

namespace App\Console\Commands;

use App\Medical\Ai\Agents\CodexAgent;
use App\Medical\Ai\Agents\CopilotAgent;
use App\Medical\Ai\Eval\Corpus;
use App\Medical\Ai\Eval\CorpusRejected;
use App\Medical\Ai\Eval\EvalReport;
use App\Medical\Ai\Eval\EvalRunner;
use App\Medical\Ai\LoginState;
use App\Medical\Ai\Preflight;
use App\Medical\Queries\AiAgentSettingsQuery;
use App\Medical\Queries\EvalGuardQuery;
use Illuminate\Console\Command;
use Throwable;

/**
 * The OCR evaluation harness (Phase 13). The only code allowed to send anything to a real AI CLI outside the family's
 * own runs, and only when an operator starts it on staging, with a synthetic corpus that proves itself (see Corpus).
 * Output rules: ids, numbers and failure codes only. What the model read is never printed, logged or written to the
 * report, and an unexpected exception prints its class name only (a message could embed content).
 */
class AiOcrEval extends Command
{
    protected $signature = 'ai:ocr-eval {--dir= : Synthetic corpus folder (default: <ai work dir>/eval)} {--stage=* : A to G, comma separated, or cleanup (default: A to G)} {--max-calls=150 : Hard cap of AI calls in this run} {--out= : Report file (default: <dir>/report-<UTC>.json)} {--confirm-synthetic : Required: the folder holds only synthetic documents} {--pause=5 : Seconds between two calls}';

    protected $description = 'OCR evaluation on synthetic documents (staging only, real AI CLI, sequential, capped)';

    public function handle(): int
    {
        try {
            return $this->evaluate();
        } catch (CorpusRejected $e) {
            return $this->refuse('corpus '.$e->reason);
        } catch (Throwable $e) {
            $this->error('ai:ocr-eval failed: '.class_basename($e));

            return 1;
        }
    }

    private function evaluate(): int
    {
        if (! app()->environment(['staging', 'testing'])) {
            return $this->refuse('environment '.app()->environment());
        }
        if (! $this->option('confirm-synthetic')) {
            return $this->refuse('--confirm-synthetic is required');
        }
        if (! (new Preflight)->envKeysClean()) {
            return $this->refuse('forbidden AI environment variables are set');
        }

        $maxCalls = filter_var($this->option('max-calls'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        $pause = filter_var($this->option('pause'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 120]]);
        if ($maxCalls === false || $pause === false) {
            return $this->refuse('--max-calls must be 1 to 500 and --pause 0 to 120');
        }
        $stages = $this->stages();
        if ($stages === null) {
            return $this->refuse('--stage must be A to G or cleanup');
        }

        $dir = (string) ($this->option('dir') ?: rtrim((string) config('ai.work_dir'), '/').'/eval');
        $corpus = Corpus::load($dir);

        if ($stages === ['cleanup']) {
            EvalRunner::cleanup($corpus);
            $this->info('cleaned: transcripts and state removed');

            return 0;
        }

        $busy = EvalGuardQuery::busyReason();
        if ($busy !== null) {
            return $this->refuse('AI worker is not free ('.$busy.')');
        }

        $settings = app(AiAgentSettingsQuery::class);
        $codex = new CodexAgent(null, $settings->model('codex'));
        if ($codex->loginStatus() !== LoginState::LoggedIn) {
            return $this->refuse('the Codex login is not usable');
        }

        // Copilot is optional and its spawns are not counted in the database (the evaluation writes no database row).
        $copilot = null;
        $skip = 'copilot_not_enabled';
        if (in_array('copilot', $settings->enabledKeys(), true)) {
            $candidate = new CopilotAgent(null, $settings->model('copilot'));
            if ($candidate->loginStatus() === LoginState::LoggedIn) {
                $copilot = $candidate;
            } else {
                $skip = 'copilot_not_logged_in';
            }
        }

        $report = $this->reportPath($corpus);
        if ($report === null) {
            return $this->refuse('--out must be a file inside the corpus folder');
        }

        $runner = new EvalRunner(
            $corpus, $codex, $copilot, $skip, $maxCalls, $pause,
            fn (array $row) => $this->line($this->rowLine($row)),
            fn (string $line) => $this->line($line),
        );
        $state = $runner->run($stages);

        $data = EvalReport::build($state, [
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'calls_this_run' => $runner->callsRun(),
            'generator' => null,
        ]);
        file_put_contents($report, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($report, 0600);

        $d = $data['decisions'];
        $this->line('calls='.$runner->callsRun().' total='.$data['calls']['total'].' stopped_reason='.($data['stopped_reason'] ?? 'none').' noise_sd='.$data['noise_sd']);
        $this->line('decisions preprocess='.$d['preprocess'].' pdf_scale='.($d['pdf_scale'] ?? 'n/a').' pipeline='.($d['pipeline'] ?? 'n/a').' store_text='.$d['store_text']);
        $this->line('report='.basename($report));

        return 0;
    }

    private function refuse(string $why): int
    {
        $this->error('ai:ocr-eval refused: '.$why);

        return 1;
    }

    /** @return list<string>|null null for an unknown stage */
    private function stages(): ?array
    {
        $given = [];
        foreach ((array) $this->option('stage') as $s) {
            foreach (explode(',', (string) $s) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $given[] = strtolower($part) === 'cleanup' ? 'cleanup' : strtoupper($part);
                }
            }
        }
        if ($given === []) {
            return EvalRunner::STAGES;
        }
        foreach ($given as $s) {
            if ($s !== 'cleanup' && ! in_array($s, EvalRunner::STAGES, true)) {
                return null;
            }
        }
        if (in_array('cleanup', $given, true)) {
            return count($given) === 1 ? ['cleanup'] : null;
        }

        return array_values(array_unique($given));
    }

    private function reportPath(Corpus $corpus): ?string
    {
        $out = (string) $this->option('out');
        if ($out === '') {
            return $corpus->dir.'/report-'.gmdate('Ymd\THis\Z').'.json';
        }
        $dirReal = realpath(dirname($out));
        $name = basename($out);
        if ($dirReal === false || $dirReal !== $corpus->dir || preg_match('/^report[A-Za-z0-9._-]{0,60}\.json$/', $name) !== 1) {
            return null;
        }

        return $dirReal.'/'.$name;
    }

    /** One progress line: ids, numbers and a failure code only. @param array<string, mixed> $row */
    private function rowLine(array $row): string
    {
        $safe = fn ($v) => is_string($v) && preg_match('/^[A-Za-z0-9_.-]{1,60}$/', $v) === 1 ? $v : '-';
        $num = fn ($v) => is_numeric($v) ? (string) round((float) $v, 4) : '-';
        $parts = [
            'stage='.$safe($row['stage'] ?? null), 'item='.$safe($row['item'] ?? null), 'doc='.$safe($row['doc'] ?? null),
            'cond='.$safe($row['condition'] ?? null), 'arm='.$safe($row['arm'] ?? null), 'ok='.(($row['ok'] ?? false) ? '1' : '0'),
            'fail='.$safe($row['failure'] ?? null), 'ms='.$num($row['ms'] ?? null), 'pre_ms='.$num($row['pre_ms'] ?? null),
            'peak_mb='.$num($row['peak_mb'] ?? null),
            'tok_in='.$num($row['tokens']['input'] ?? null), 'tok_out='.$num($row['tokens']['output'] ?? null),
        ];
        foreach (['cer', 'wer', 'diacritics', 'numeric', 'recall', 'f1'] as $k) {
            if (isset($row[$k])) {
                $parts[] = $k.'='.$num($row[$k]);
            }
        }

        return implode(' ', $parts);
    }
}
