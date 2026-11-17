<?php

namespace App\Medical\Ai\Eval;

use App\Medical\Ai\Agents\Agent;
use App\Medical\Ai\Agents\AgentInput;
use App\Medical\Ai\Agents\AgentOutcome;
use App\Medical\Ai\FailureCode;
use App\Medical\Ai\Work\WorkDir;
use App\Medical\Documents\PdfRasterizer;
use App\Medical\Documents\Preprocess\DocumentPreprocessor;
use App\Wishlist\Catalog\ImageProcessor;
use App\Wishlist\Catalog\ImageRejected;
use Closure;

/**
 * The stages of the OCR evaluation (13-RESEARCH "Call budget"). Strictly sequential: one agent call at a time, a pause
 * between calls, a hard call cap, and a stop at the first usage-limit or login failure. Only numbers and codes are kept:
 * what the model read lives in memory while it is scored and, for the transcripts a later stage reuses, in the
 * 0600 `work/` folder of the synthetic corpus until cleanup. The state file holds numbers only.
 */
final class EvalRunner
{
    public const STAGES = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

    /** Conditions of the screening stage (13-RESEARCH "Call budget" C). */
    public const SCREEN = ['clean', 'rot8', 'persp', 'blur', 'lowc', 'noise', 'jpeg'];

    private const FULL_CHAIN = ['deskew', 'autolevel', 'denoise_sharpen', 'grayscale'];

    private const STATE_VERSION = 1;

    /** @var array<string, mixed> */
    private array $state;

    private int $callsRun = 0;

    /** @var list<string> */
    private array $selected = [];

    private ?Closure $onRow;

    private ?Closure $onLine;

    private ?Closure $sleep;

    private DocumentPreprocessor $pre;

    public function __construct(
        private readonly Corpus $corpus,
        private readonly Agent $codex,
        private readonly ?Agent $copilot,
        private readonly string $copilotSkip,
        private readonly int $maxCalls,
        private readonly int $pause,
        ?Closure $onRow = null,
        ?Closure $onLine = null,
        ?Closure $sleep = null,
    ) {
        $this->onRow = $onRow;
        $this->onLine = $onLine;
        $this->sleep = $sleep ?? static function (int $s): void {
            sleep($s);
        };
        $this->pre = new DocumentPreprocessor;
        $this->state = $this->loadState();
    }

    /** Removes the transcripts and the state file of a corpus folder (stage `cleanup`). */
    public static function cleanup(Corpus $corpus): void
    {
        WorkDir::removeTree($corpus->dir.'/work');
        foreach (['state.json', 'state.json.tmp'] as $f) {
            if (is_file($corpus->dir.'/'.$f) && ! is_link($corpus->dir.'/'.$f)) {
                @unlink($corpus->dir.'/'.$f);
            }
        }
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return $this->state;
    }

    public function callsRun(): int
    {
        return $this->callsRun;
    }

    /**
     * @param  list<string>  $stages  of self::STAGES, run in alphabetical order
     * @return array<string, mixed> the state, with stopped_reason when the run ended early
     */
    public function run(array $stages): array
    {
        $this->state['stopped_reason'] = null;
        $stages = array_values(array_intersect(self::STAGES, $stages));
        $this->selected = $stages;
        try {
            foreach ($stages as $stage) {
                match ($stage) {
                    'A' => $this->stageA(),
                    'B' => $this->stageB($stages),
                    'C' => $this->stageC(),
                    'D' => $this->stageD(),
                    'E' => $this->stageE(),
                    'F' => $this->stageF(),
                    'G' => $this->stageG(),
                };
                if (! in_array($stage, (array) $this->state['stages_done'], true)) {
                    $this->state['stages_done'][] = $stage;
                }
                $this->save();
            }
        } catch (EvalStopped $e) {
            $this->state['stopped_reason'] = $e->getMessage();
        }
        $this->save();

        return $this->state;
    }

    // ---- stages ----------------------------------------------------------------------------------------------

    private function stageA(): void
    {
        $png = $this->corpus->find('probe-png');
        $jpg = $this->corpus->find('probe-jpg');
        $arms = [];
        if ($png !== null) {
            $arms['p4000png'] = [$png, null];
            $arms['p2048png'] = [$png, 'fit'];
        }
        if ($jpg !== null) {
            $arms['p4000jpg'] = [$jpg, null];
            $arms['p2048jpg'] = [$jpg, 'fit'];
        }
        foreach ($arms as $arm => [$item, $how]) {
            if ($this->done('A', $item['id'], $arm, 0)) {
                continue;
            }
            $path = $item['path'];
            $pre = ['ms' => 0, 'peak_bytes' => 0];
            if ($how === 'fit') {
                $path = $this->workPath('arm', $item['id'].'.'.$arm.'.png');
                $pre = $this->pre->fit($item['path'], $path);
            }
            $this->transcribe('A', $item, $arm, 0, $path, $pre);
        }
    }

    /** @param list<string> $selected */
    private function stageB(array $selected): void
    {
        $docs = [];
        foreach ($this->corpus->where('image', null, 'clean') as $item) {
            $docs[$item['doc']] = $item;
        }
        foreach (array_slice($docs, 0, 3) as $item) {
            for ($rep = 0; $rep < 2; $rep++) {
                if ($this->done('B', $item['id'], 'A0', $rep)) {
                    continue;
                }
                $this->transcribeArm('B', $item, 'A0', $rep);
            }
        }
        $this->state['noise_sd'] = EvalReport::noiseSd($this->rows());
        $this->save();

        $planned = 0;
        foreach (array_diff(array_intersect(['C', 'E', 'F', 'G'], $selected), (array) $this->state['stages_done']) as $s) {
            $planned += $this->planned($s);
        }
        $projected = $this->callsRun + $planned;
        $this->state['projected_total'] = $projected;
        $this->line('projected_total='.$projected.' max_calls='.$this->maxCalls.' noise_sd='.round((float) $this->state['noise_sd'], 4));
        if ($planned > 0 && $projected > $this->maxCalls) {
            throw new EvalStopped('projected_over_cap');
        }
    }

    private function stageC(): void
    {
        foreach ($this->docs() as $doc) {
            foreach (self::SCREEN as $cond) {
                $item = $this->corpus->where('image', $doc, $cond)[0] ?? null;
                if ($item === null) {
                    continue;
                }
                foreach (['A0', 'A5'] as $arm) {
                    if (! $this->done('C', $item['id'], $arm, 0)) {
                        $this->transcribeArm('C', $item, $arm, 0, keepText: $arm === 'A0');
                    }
                }
            }
        }
    }

    private function stageD(): void
    {
        $rows = $this->rows();
        $noise = (float) ($this->state['noise_sd'] ?? 0.0);
        $gains = [];
        foreach (self::SCREEN as $cond) {
            if (in_array($cond, EvalDecision::CLEAN, true)) {
                continue;
            }
            $diffs = $base = [];
            foreach ($this->docs() as $doc) {
                $item = $this->corpus->where('image', $doc, $cond)[0] ?? null;
                $a0 = $item === null ? null : $this->rowFor($rows, 'C', $item['id'], 'A0');
                $a5 = $item === null ? null : $this->rowFor($rows, 'C', $item['id'], 'A5');
                if ($a0 !== null && $a5 !== null) {
                    $diffs[] = $a0['cer'] - $a5['cer'];
                    $base[] = $a0['cer'];
                }
            }
            if ($diffs !== [] && Stats::mean($diffs) >= EvalDecision::margin(Stats::mean($base), $noise)) {
                $gains[$cond] = Stats::mean($diffs);
            }
        }
        arsort($gains);
        $conds = array_slice(array_keys($gains), 0, 3);
        $this->state['d_conditions'] = $conds;
        if ($conds === []) {
            $this->line('stage=D skipped reason=no_condition_improved');

            return;
        }
        foreach (['A1', 'A2', 'A4'] as $arm) {
            $this->ablation($conds, $arm);
        }
        // A3 only if A2 helped on these conditions.
        $rows = $this->rows();
        $diffs = $base = [];
        foreach ($conds as $cond) {
            foreach ($this->docs() as $doc) {
                $item = $this->corpus->where('image', $doc, $cond)[0] ?? null;
                $a0 = $item === null ? null : $this->rowFor($rows, 'C', $item['id'], 'A0');
                $a2 = $item === null ? null : $this->rowFor($rows, 'D', $item['id'], 'A2');
                if ($a0 !== null && $a2 !== null) {
                    $diffs[] = $a0['cer'] - $a2['cer'];
                    $base[] = $a0['cer'];
                }
            }
        }
        if ($diffs !== [] && Stats::mean($diffs) >= EvalDecision::margin(Stats::mean($base), $noise)) {
            $this->ablation($conds, 'A3');
        } else {
            $this->line('stage=D arm=A3 skipped reason=a2_did_not_help');
        }
    }

    /** @param list<string> $conds */
    private function ablation(array $conds, string $arm): void
    {
        foreach ($this->docs() as $doc) {
            foreach ($conds as $cond) {
                $item = $this->corpus->where('image', $doc, $cond)[0] ?? null;
                if ($item !== null && ! $this->done('D', $item['id'], $arm, 0)) {
                    $this->transcribeArm('D', $item, $arm, 0);
                }
            }
        }
    }

    private function stageE(): void
    {
        foreach ($this->docs() as $doc) {
            foreach (['clean', 'phone'] as $cond) {
                $item = $this->corpus->where('image', $doc, $cond)[0] ?? null;
                if ($item === null) {
                    continue;
                }
                $expected = $this->corpus->expected($doc) ?? [];
                $img = $this->ingested($item);
                if ($img === null) {
                    continue;
                }
                $pre = ['ms' => 0, 'peak_bytes' => 0];

                // P0: today, images straight to entries.
                if (! $this->done('E', $item['id'], 'P0', 0)) {
                    $out = $this->call(new EvalTask('baseline'), [$img]);
                    $this->recordEntries('E', $item, 'P0', $out, $expected, $pre, 0);
                }

                // P1: two calls. The transcript of stage C is reused for clean items; otherwise it is made now.
                if (! $this->done('E', $item['id'], 'P1', 0)) {
                    $text = $this->savedText('C', $item['id'], 'A0');
                    $stepMs = $this->rowFor($this->rows(), 'C', $item['id'], 'A0')['ms'] ?? 0;
                    if ($text === null) {
                        $this->transcribeArm('E', $item, 'A0', 0, keepText: true, armLabel: 'P1T');
                        $text = $this->savedText('E', $item['id'], 'P1T');
                        $stepMs = $this->rowFor($this->rows(), 'E', $item['id'], 'P1T')['ms'] ?? 0;
                    }
                    if ($text !== null) {
                        $out = $this->call(new EvalTask('extract_from_text', [1], $text), []);
                        $this->recordEntries('E', $item, 'P1', $out, $expected, $pre, (int) $stepMs);
                    }
                }

                // P2: one combined call.
                if (! $this->done('E', $item['id'], 'P2', 0)) {
                    $out = $this->call(new EvalTask('combined'), [$img]);
                    $this->recordEntries('E', $item, 'P2', $out, $expected, $pre, 0, $this->corpus->groundTruth($doc));
                }
            }
        }
    }

    private function stageF(): void
    {
        $raster = new PdfRasterizer;
        foreach ($this->corpus->where('pdf') as $item) {
            foreach ([1800, 2048] as $scale) {
                $arm = 'PDF'.$scale;
                if ($this->done('F', $item['id'], $arm, 0)) {
                    continue;
                }
                $tmp = WorkDir::makeTempDir();
                $previous = config('ai.pdf.scale');
                try {
                    config(['ai.pdf.scale' => $scale]);
                    $t = hrtime(true);
                    $r = $raster->pages($item['path'], $tmp, 1);
                    $ms = (int) round((hrtime(true) - $t) / 1_000_000);
                    config(['ai.pdf.scale' => $previous]);
                    if ($r['written'] === []) {
                        $this->push($this->failureRow('F', $item, $arm, 0, FailureCode::UnreadableDocument));

                        continue;
                    }
                    $this->transcribe('F', $item, $arm, 0, $r['written'][0], ['ms' => $ms, 'peak_bytes' => 0]);
                } finally {
                    config(['ai.pdf.scale' => $previous]);
                    WorkDir::removeTree($tmp);
                }
            }
        }
    }

    private function stageG(): void
    {
        if ($this->copilot === null) {
            $this->state['copilot'] = ['skipped' => $this->copilotSkip];
            $this->line('stage=G skipped reason='.$this->copilotSkip);

            return;
        }
        $item = $this->corpus->where('image', null, 'clean')[0] ?? null;
        $img = $item === null ? null : $this->ingested($item);
        if ($item === null || $img === null) {
            return;
        }
        $truth = $this->corpus->groundTruth($item['doc']);
        $expected = $this->corpus->expected($item['doc']) ?? [];
        $pre = ['ms' => 0, 'peak_bytes' => 0];
        $text = null;
        try {
            if (! $this->done('G', $item['id'], 'copilot-transcribe', 0)) {
                $out = $this->call(new EvalTask('transcribe'), [$img], $this->copilot);
                $this->push($this->metricsRow('G', $item, 'copilot-transcribe', 0, $out, $pre, $truth));
                $text = $out->ok ? $this->pagesText((array) $out->payload) : null;
            }
            if (! $this->done('G', $item['id'], 'copilot-extract', 0)) {
                $out = $this->call(new EvalTask('extract_from_text', [1], $text ?? $truth), [], $this->copilot);
                $this->recordEntries('G', $item, 'copilot-extract', $out, $expected, $pre, 0);
            }
            $this->state['copilot'] = ['skipped' => null];
        } catch (EvalStopped $e) {
            // A Copilot quota or login problem ends only this optional check, not the whole run.
            if ($e->getMessage() === 'max_calls') {
                throw $e;
            }
            $this->state['copilot'] = ['skipped' => 'copilot_'.$e->getMessage()];
        }
    }

    // ---- recording ---------------------------------------------------------------------------------------------

    /** Pre-processes the item for the arm, sends it and records the transcription metrics. */
    private function transcribeArm(string $stage, array $item, string $arm, int $rep, bool $keepText = false, ?string $armLabel = null): void
    {
        $label = $armLabel ?? $arm;
        $img = $this->ingested($item);
        if ($img === null) {
            $this->push($this->failureRow($stage, $item, $label, $rep, FailureCode::UnreadableDocument));

            return;
        }
        try {
            [$path, $pre] = $this->armImage($item, $arm, $img);
        } catch (\Throwable) {
            $this->push($this->failureRow($stage, $item, $label, $rep, FailureCode::UnreadableDocument));

            return;
        }
        $this->transcribe($stage, $item, $label, $rep, $path, $pre, $keepText);
    }

    /** @param array{ms: int, peak_bytes: int} $pre */
    private function transcribe(string $stage, array $item, string $arm, int $rep, string $path, array $pre, bool $keepText = false): void
    {
        $out = $this->call(new EvalTask('transcribe'), [$path]);
        $row = $this->metricsRow($stage, $item, $arm, $rep, $out, $pre, $this->corpus->groundTruth($item['doc']));
        if ($keepText && $out->ok) {
            $this->saveText($stage, $item['id'], $arm, $this->pagesText((array) $out->payload));
        }
        $this->push($row);
    }

    /**
     * @param  array{ms: int, peak_bytes: int}  $pre
     * @return array<string, mixed>
     */
    private function metricsRow(string $stage, array $item, string $arm, int $rep, AgentOutcome $out, array $pre, string $truth): array
    {
        $row = $this->baseRow($stage, $item, $arm, $rep, $out, $pre);
        if ($out->ok && is_array($out->payload)) {
            $hyp = $this->pagesText($out->payload);
            $d = TextMetrics::diacritics($truth, $hyp);
            $row += [
                'cer' => round(TextMetrics::cer($truth, $hyp), 5),
                'wer' => round(TextMetrics::wer($truth, $hyp), 5),
                'diacritics' => round($d['accuracy'], 5),
                'drop_rate' => round($d['drop_rate'], 5),
                'numeric' => round(TextMetrics::numeric($truth, $hyp), 5),
                'recall' => round(TextMetrics::searchRecall($truth, $hyp), 5),
            ];
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array{ms: int, peak_bytes: int}  $pre
     */
    private function recordEntries(string $stage, array $item, string $arm, AgentOutcome $out, array $expected, array $pre, int $extraMs, ?string $truth = null): void
    {
        $row = $this->baseRow($stage, $item, $arm, 0, $out, $pre);
        $row['ms'] += $extraMs;
        if ($out->ok && is_array($out->payload)) {
            $s = EntryScorer::score($expected, $out->payload);
            $row += ['f1' => round($s['f1'], 5), 'precision' => round($s['precision'], 5), 'recall_entries' => round($s['recall'], 5), 'tp' => $s['tp'], 'fp' => $s['fp'], 'fn' => $s['fn']];
            if ($truth !== null && isset($out->payload['pages'])) {
                $hyp = $this->pagesText($out->payload);
                $row += ['cer' => round(TextMetrics::cer($truth, $hyp), 5), 'recall' => round(TextMetrics::searchRecall($truth, $hyp), 5)];
            }
        }
        $this->push($row);
    }

    /**
     * @param  array{ms: int, peak_bytes: int}  $pre
     * @return array<string, mixed>
     */
    private function baseRow(string $stage, array $item, string $arm, int $rep, AgentOutcome $out, array $pre): array
    {
        $u = $out->usage;

        return [
            'stage' => $stage, 'item' => $item['id'], 'doc' => $item['doc'], 'condition' => $item['condition'], 'arm' => $arm, 'rep' => $rep,
            'ok' => $out->ok, 'failure' => $out->ok ? null : ($out->failure?->value ?? 'aborted'),
            'ms' => $out->durationMs, 'pre_ms' => $pre['ms'], 'peak_mb' => round($pre['peak_bytes'] / 1048576, 1),
            'tokens' => $u === null ? null : ['input' => $u->input, 'cached' => $u->cached, 'output' => $u->output, 'reasoning' => $u->reasoning],
        ];
    }

    /** @return array<string, mixed> */
    private function failureRow(string $stage, array $item, string $arm, int $rep, FailureCode $code): array
    {
        return ['stage' => $stage, 'item' => $item['id'], 'doc' => $item['doc'], 'condition' => $item['condition'], 'arm' => $arm, 'rep' => $rep,
            'ok' => false, 'failure' => $code->value, 'ms' => 0, 'pre_ms' => 0, 'peak_mb' => 0.0, 'tokens' => null];
    }

    /** @param array<string, mixed> $row */
    private function push(array $row): void
    {
        $this->state['rows'][] = $row;
        $this->save();
        if ($this->onRow !== null) {
            ($this->onRow)($row);
        }
    }

    // ---- calling ---------------------------------------------------------------------------------------------

    /**
     * One agent call: budget, pause, a fresh work dir with copies of the images, and a stop on quota or login failure.
     *
     * @param  list<string>  $images  absolute paths of png or jpg files
     */
    private function call(EvalTask $task, array $images, ?Agent $agent = null): AgentOutcome
    {
        if ($this->callsRun >= $this->maxCalls) {
            throw new EvalStopped('max_calls');
        }
        if ($this->callsRun > 0 && $this->pause > 0) {
            ($this->sleep)($this->pause);
        }
        $dir = WorkDir::create();
        try {
            $names = [];
            foreach (array_values($images) as $i => $path) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png' ? 'png' : 'jpg';
                $name = 'page-'.($i + 1).'.'.$ext;
                if (! copy($path, $dir->file($name))) {
                    throw new \RuntimeException('eval image not copyable');
                }
                chmod($dir->file($name), 0600);
                $names[] = $name;
            }
            $this->callsRun++;
            $this->state['calls_total'] = (int) $this->state['calls_total'] + 1;
            $out = ($agent ?? $this->codex)->extract(new AgentInput($dir, $names, time() + (int) config('ai.timeouts.process') + 10, $task));
        } finally {
            $dir->cleanup();
        }
        if ($out->failure === FailureCode::QuotaExhausted) {
            throw new EvalStopped('quota');
        }
        if ($out->failure === FailureCode::Auth) {
            throw new EvalStopped('auth');
        }

        return $out;
    }

    // ---- images ----------------------------------------------------------------------------------------------

    /** The image as the real ingestion stores it: EXIF applied and stripped, at most 3600 px, JPEG q90. Never the medical disk. */
    private function ingested(array $item): ?string
    {
        $path = $this->workPath('ingested', $item['id'].'.jpg');
        if (is_file($path)) {
            return $path;
        }
        try {
            $bytes = (string) file_get_contents($item['path']);
            $img = (new ImageProcessor)->process(
                $bytes,
                (int) config('medical.document_max_bytes'),
                (int) config('medical.image_full_px'),
                (int) config('medical.image_thumb_px'),
                (int) config('medical.image_jpeg_quality'),
                (int) config('medical.image_decode_budget_bytes'),
            );
        } catch (ImageRejected) {
            return null;
        }
        file_put_contents($path, $img->fullBytes);
        chmod($path, 0600);

        return $path;
    }

    /** @return array{0: string, 1: array{ms: int, peak_bytes: int}} */
    private function armImage(array $item, string $arm, string $ingested): array
    {
        if ($arm === 'A0') {
            return [$ingested, ['ms' => 0, 'peak_bytes' => 0]];
        }
        $dst = $this->workPath('arm', $item['id'].'.'.$arm.'.png');
        $r = match ($arm) {
            'A1' => $this->pre->fit($ingested, $dst),
            'A2' => $this->pre->autolevel($ingested, $dst),
            'A3' => $this->pre->denoiseSharpen($ingested, $dst),
            'A4' => $this->pre->deskew($ingested, $dst),
            'A5' => $this->pre->chain($ingested, $dst, self::FULL_CHAIN),
            default => throw new \InvalidArgumentException('unknown arm'),
        };

        return [$dst, ['ms' => $r['ms'], 'peak_bytes' => $r['peak_bytes']]];
    }

    // ---- state -----------------------------------------------------------------------------------------------

    /** @return list<string> */
    private function docs(): array
    {
        $docs = [];
        foreach ($this->corpus->where('image') as $i) {
            $docs[$i['doc']] = true;
        }

        return array_keys($docs);
    }

    private function planned(string $stage): int
    {
        $n = 0;
        $count = function (string $s, string $item, string $arm) use (&$n): void {
            if (! $this->done($s, $item, $arm, 0)) {
                $n++;
            }
        };
        foreach ($this->docs() as $doc) {
            if ($stage === 'C') {
                foreach (self::SCREEN as $cond) {
                    $item = $this->corpus->where('image', $doc, $cond)[0] ?? null;
                    if ($item !== null) {
                        $count('C', $item['id'], 'A0');
                        $count('C', $item['id'], 'A5');
                    }
                }
            }
            if ($stage === 'E') {
                foreach (['clean', 'phone'] as $cond) {
                    $item = $this->corpus->where('image', $doc, $cond)[0] ?? null;
                    if ($item !== null) {
                        $count('E', $item['id'], 'P0');
                        $count('E', $item['id'], 'P1');
                        $count('E', $item['id'], 'P2');
                        if ($cond !== 'clean' || (! in_array('C', $this->selected, true) && $this->savedText('C', $item['id'], 'A0') === null)) {
                            $n++;
                        }
                    }
                }
            }
        }
        if ($stage === 'F') {
            foreach ($this->corpus->where('pdf') as $item) {
                $count('F', $item['id'], 'PDF1800');
                $count('F', $item['id'], 'PDF2048');
            }
        }
        if ($stage === 'G' && $this->copilot !== null) {
            $item = $this->corpus->where('image', null, 'clean')[0] ?? null;
            if ($item !== null) {
                $count('G', $item['id'], 'copilot-transcribe');
                $count('G', $item['id'], 'copilot-extract');
            }
        }

        return $n;
    }

    private function done(string $stage, string $item, string $arm, int $rep): bool
    {
        foreach ($this->rows() as $r) {
            if ($r['stage'] === $stage && $r['item'] === $item && $r['arm'] === $arm && $r['rep'] === $rep) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->state['rows'];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null the row if it succeeded and carries metrics
     */
    private function rowFor(array $rows, string $stage, string $item, string $arm): ?array
    {
        foreach ($rows as $r) {
            if ($r['stage'] === $stage && $r['item'] === $item && $r['arm'] === $arm && ($r['ok'] ?? false) && isset($r['cer'])) {
                return $r;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function pagesText(array $payload): string
    {
        $pages = (array) ($payload['pages'] ?? []);
        usort($pages, fn ($a, $b) => $a['page'] <=> $b['page']);

        return implode("\n", array_map(fn ($p) => (string) $p['text'], $pages));
    }

    private function workPath(string $sub, string $name): string
    {
        $dir = $this->corpus->dir.'/work/'.$sub;
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('eval work folder not writable');
        }

        return $dir.'/'.$name;
    }

    private function saveText(string $stage, string $item, string $arm, string $text): void
    {
        $path = $this->workPath('transcripts', $stage.'-'.$item.'-'.$arm.'.txt');
        file_put_contents($path, $text);
        chmod($path, 0600);
    }

    private function savedText(string $stage, string $item, string $arm): ?string
    {
        $path = $this->corpus->dir.'/work/transcripts/'.$stage.'-'.$item.'-'.$arm.'.txt';

        return is_file($path) && ! is_link($path) ? (string) file_get_contents($path) : null;
    }

    private function line(string $text): void
    {
        if ($this->onLine !== null) {
            ($this->onLine)($text);
        }
    }

    /** @return array<string, mixed> */
    private function loadState(): array
    {
        $fresh = ['version' => self::STATE_VERSION, 'calls_total' => 0, 'noise_sd' => 0.0, 'rows' => [], 'stages_done' => [], 'stopped_reason' => null];
        $path = $this->corpus->dir.'/state.json';
        if (! is_file($path) || is_link($path)) {
            return $fresh;
        }
        $d = json_decode((string) file_get_contents($path), true, 16);
        if (! is_array($d) || ($d['version'] ?? null) !== self::STATE_VERSION || ! is_array($d['rows'] ?? null)) {
            return $fresh;
        }

        return array_merge($fresh, $d);
    }

    private function save(): void
    {
        $path = $this->corpus->dir.'/state.json';
        $tmp = $path.'.tmp';
        file_put_contents($tmp, (string) json_encode($this->state, JSON_UNESCAPED_SLASHES));
        chmod($tmp, 0600);
        rename($tmp, $path);
    }
}
