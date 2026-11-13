<?php

namespace App\Http\Controllers\Finance;

use App\Finance\FinancePage;
use App\Finance\Import\CsvReader;
use App\Finance\Import\Decoder;
use App\Finance\Import\ImportError;
use App\Finance\Import\ImportStager;
use App\Finance\Import\ProfileSpec;
use App\Finance\Queries\AccountQuery;
use App\Finance\Queries\ImportQuery;
use App\Finance\Viewer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ImportPreviewRequest;
use App\Http\Requests\Finance\ImportUploadRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * CSV import, first half. The uploaded file is read once from its temporary path into memory and never stored, moved
 * or logged; only the parsed rows of a draft batch are kept.
 */
class ImportController extends Controller
{
    private const SAMPLE_ROWS = 5;

    public function index(Request $request, FinancePage $page, AccountQuery $accounts, ImportQuery $imports): Response
    {
        $viewer = Viewer::for($request->user());

        return Inertia::render('Finance/Import', [
            'finance' => $page->props($viewer, $request),
            'import' => [
                'accounts' => $accounts->visible($viewer)->map(fn ($a) => [
                    'id' => $a->id, 'name' => $a->name, 'currency' => $a->currency,
                ])->values()->all(),
                'profiles' => array_map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'settings' => $this->safeSettings((array) $p->settings),
                ], $imports->profiles()),
                'history' => array_map(fn ($b) => [
                    'id' => $b->id,
                    'createdAt' => CarbonImmutable::parse($b->created_at)->toIso8601String(),
                    'accountId' => $b->account_id,
                    'accountName' => $b->account_name,
                    'addedCount' => (int) $b->added_count,
                    'status' => $b->status,
                ], $imports->history($viewer)),
                'limits' => [
                    'maxBytes' => (int) config('finance.import.max_bytes'),
                    'maxRows' => (int) config('finance.import.max_rows'),
                ],
                'encodings' => config('finance.import.encodings'),
                'dateFormats' => config('finance.import.date_formats'),
                'delimiters' => config('finance.import.delimiters'),
            ],
        ]);
    }

    public function preview(ImportPreviewRequest $request): JsonResponse
    {
        Viewer::for($request->user());
        $bytes = $this->read($request);
        $headers = ['Cache-Control' => 'no-store'];

        try {
            $encoding = $request->filled('encoding') ? (string) $request->input('encoding') : null;
            $decoded = Decoder::decode($bytes, $encoding);
            $delimiter = $this->delimiter($request->input('delimiter'));
            $guessed = CsvReader::guessDelimiter($decoded->text);
            $delimiter ??= $guessed;
            $records = CsvReader::rows($decoded->text, $delimiter, (int) config('finance.import.max_rows'));
        } catch (ImportError $e) {
            return response()->json(['error' => $this->previewError((string) $e->code)], 422, $headers);
        }

        $headerRow = $request->filled('header_row') ? (int) $request->input('header_row') : $this->guessHeaderRow($records);
        $after = array_slice($records, $headerRow);
        if (count($after) > (int) config('finance.import.max_rows')) {
            return response()->json(['error' => 'too_many_rows'], 422, $headers);
        }
        if ($after === []) {
            return response()->json(['error' => 'empty'], 422, $headers);
        }

        return response()->json([
            'suggested' => ['encoding' => $decoded->suggested, 'reason' => $decoded->reason, 'delimiter' => $guessed],
            'encoding' => $decoded->encoding,
            'delimiter' => $delimiter,
            'headerRow' => $headerRow,
            'headers' => $headerRow > 0 ? array_map(fn ($c) => trim((string) $c), $records[$headerRow - 1] ?? []) : [],
            'sample' => array_map(fn (array $r) => array_values($r), array_slice($after, 0, self::SAMPLE_ROWS)),
            'recordCount' => count($after),
        ], 200, $headers);
    }

    public function store(ImportUploadRequest $request, ImportStager $stager, ImportQuery $imports): RedirectResponse
    {
        $viewer = Viewer::for($request->user());
        $bytes = $this->read($request);
        $failKey = $request->usesSavedProfile() ? 'profile_id' : 'settings';

        try {
            $batchId = $stager->stage($viewer, $request->account(), $bytes, $request->spec(), $request->usesSavedProfile());
        } catch (ImportError $e) {
            $code = (string) $e->code;

            return back()->withErrors($code === 'profile_mismatch'
                ? [$failKey => 'finance.imp.err_profile']
                : ['file' => $this->storeError($code)]);
        }

        $name = $request->profileNameToSave();
        if ($name !== null) {
            $imports->createProfile($name, $request->spec()->toArray(), $viewer->userId());
        }

        return redirect()->route('finance.import.show', ['batch' => $batchId], 303);
    }

    public function show(Request $request, string $batch, ImportQuery $imports): Response
    {
        $viewer = Viewer::for($request->user());
        $found = $imports->findBatchForViewer($viewer, $batch);
        abort_if($found === null, 404);

        $draft = $found->status === 'draft';
        $rows = [];
        if ($draft) {
            $rows = array_map(fn ($r) => [
                'id' => $r->id,
                'rowNo' => (int) $r->row_no,
                'bookedOn' => $r->booked_on,
                'description' => $r->description,
                'counterparty' => $r->counterparty,
                'amountMinor' => $r->amount_minor === null ? null : (string) $r->amount_minor,
                'kind' => $r->kind,
                'state' => $r->state,
                'errorCode' => $r->error_code,
                'include' => (bool) $r->include,
                'probable' => $r->probable_transaction_id === null ? null : [
                    'description' => $r->probable_description,
                    'bookedOn' => $r->probable_booked_on,
                    'amountMinor' => (string) $r->probable_amount_minor,
                ],
                'matchName' => null,
            ], $imports->batchRows($viewer, (string) $found->id));
        }

        return Inertia::render('Finance/ImportBatch', [
            'finance' => app(FinancePage::class)->props($viewer, $request),
            'batch' => [
                'id' => $found->id,
                'status' => $found->status,
                'accountId' => $found->account_id,
                'accountName' => $found->account_name,
                'currency' => $found->account_currency,
                'counts' => $imports->counts($viewer, (string) $found->id),
                'rows' => $rows,
                'result' => $draft ? null : [
                    'added' => (int) $found->added_count,
                    'matched' => (int) $found->matched_count,
                    'kept' => $found->kept_count === null ? 0 : (int) $found->kept_count,
                ],
            ],
        ]);
    }

    /** The upload is read once into memory; it is never stored or moved. */
    private function read(Request $request): string
    {
        $file = $request->file('file');
        $bytes = $file === null ? false : file_get_contents($file->getRealPath());

        return $bytes === false ? '' : $bytes;
    }

    private function delimiter(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $raw = $raw === 'tab' ? "\t" : $raw;

        return in_array($raw, config('finance.import.delimiters'), true) ? $raw : null;
    }

    /** @param  list<list<string>>  $records 1-based number of the first row that has the widest layout of the first 30 rows */
    private function guessHeaderRow(array $records): int
    {
        $head = array_slice($records, 0, 30);
        $widest = 0;
        foreach ($head as $row) {
            $widest = max($widest, count($row));
        }
        foreach ($head as $i => $row) {
            if (count($row) === $widest) {
                return $i + 1;
            }
        }

        return 0;
    }

    private function previewError(string $code): string
    {
        return in_array($code, ['too_large', 'binary', 'decode_failed', 'too_many_rows', 'empty'], true) ? $code : 'decode_failed';
    }

    private function storeError(string $code): string
    {
        return match ($code) {
            'too_large' => 'finance.imp.err_size',
            'too_many_rows' => 'finance.imp.err_rows',
            'decode_failed' => 'finance.imp.err_decode',
            default => 'finance.imp.err_type',
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function safeSettings(array $settings): array
    {
        try {
            return ProfileSpec::fromArray($settings)->toArray();
        } catch (InvalidArgumentException) {
            return [];
        }
    }
}
