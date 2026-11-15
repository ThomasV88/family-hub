<?php

namespace Database\Seeders\Staging;

use App\Medical\Documents\DocumentRejected;
use App\Medical\Documents\MedicalDocumentStore;
use Database\Factories\MedicalDocumentFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Six tiny synthetic documents (two PDFs with two pages, a PNG and JPEGs drawn with GD) stored through
 * MedicalDocumentStore (the row is made with the document factory, as in DocumentQuery::create), so previews, thumbnails and downloads work like for real uploads.
 *
 * Files live on the private medical disk. A reseed removes the rows (cascade from the people), and StagingSeeder removes
 * the files of the people it deletes after the transaction commits; `medical:prune-orphans` is the safety net for any
 * file left behind. When the disk is not writable the documents are skipped (the rest of the seed still runs).
 */
final class DocumentsSeeder
{
    /**
     * @param  array<string, array{id: string, name: string}>  $subjects
     * @return array<string, array{id: string, subject_id: string, kind: string}> keyed by a short document key
     */
    public function seed(StagingContext $ctx, array $subjects): array
    {
        $store = app(MedicalDocumentStore::class);
        $uploader = $ctx->user('parent1')->id;

        $plan = [
            'blood_pdf' => ['adult_linked', 'Výsledky krvných testov (demo)', 'pdf', ['Krvny obraz', 'Lipidy a cukor']],
            'rash_jpg' => ['adult_linked', 'Fotka vyrážky (demo)', 'jpg', 'Foto vyrazky'],
            'lab_png' => ['adult', 'Laboratórny výsledok (demo)', 'png', 'Laboratorny vysledok'],
            'vaccination_pdf' => ['child', 'Očkovací preukaz, kópia (demo)', 'pdf', ['Ockovaci preukaz', 'Dalsie davky']],
            'discharge_pdf' => ['grandparent', 'Prepúšťacia správa (demo)', 'pdf', ['Prepustacia sprava', 'Odporucania']],
            'prescription_jpg' => ['hidden', 'Recept (demo)', 'jpg', 'Recept'],
            // Archived demo document: older than the delete window and archived, so it is hidden everywhere and restorable.
            'archived_pdf' => ['adult_linked', 'Archivovaný starý výsledok (demo)', 'pdf', ['Stary vysledok', 'Archivovane'], true],
        ];

        $out = [];
        foreach ($plan as $key => $row) {
            [$who, $title, $kind, $text] = $row;
            $archived = $row[4] ?? false;
            try {
                $bytes = $kind === 'pdf' ? $this->pdf($text) : $this->image($text, $kind);
                $stored = $store->put($subjects[$who]['id'], $bytes);
                $doc = MedicalDocumentFactory::new()->create([
                    'subject_id' => $subjects[$who]['id'], 'visit_id' => null, 'kind' => $stored['kind'], 'title' => $title, 'mime' => $stored['mime'],
                    'size_bytes' => $stored['size_bytes'], 'sha256' => $stored['sha256'], 'path' => $stored['path'], 'thumb_path' => $stored['thumb_path'],
                    'width' => $stored['width'], 'height' => $stored['height'], 'source' => 'manual', 'verified_at' => null, 'uploaded_by' => $uploader,
                    'created_at' => $ctx->at($archived ? -70 : -30, 10), 'updated_at' => $ctx->at($archived ? -70 : -30, 10),
                    'archived_at' => $archived ? $ctx->at(-4, 10) : null, 'archived_by' => $archived ? $uploader : null,
                ]);
                $out[$key] = ['id' => (string) $doc->getKey(), 'subject_id' => $subjects[$who]['id'], 'kind' => $stored['kind']];
            } catch (DocumentRejected $e) {
                Log::warning('staging seed: document skipped', ['key' => $key, 'reason' => $e->getMessage()]);
            }
        }

        // The lab result belongs to the lab visit of the same person.
        if (isset($out['lab_png'])) {
            $visit = DB::table('medical_visits')->where('subject_id', $subjects['adult']['id'])->where('specialty_key', 'laboratory')->value('id');
            if ($visit !== null) {
                DB::table('medical_documents')->where('id', $out['lab_png']['id'])->update(['visit_id' => $visit]);
            }
        }

        return $out;
    }

    /** @param  list<string>  $pages */
    private function pdf(array $pages): string
    {
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $kids = [];
        $next = 4;
        foreach ($pages as $n => $text) {
            $page = $next++;
            $content = $next++;
            $kids[] = "{$page} 0 R";
            $stream = "BT /F1 22 Tf 60 740 Td (SYNTHETIC DEMO DOCUMENT) Tj ET\nBT /F1 16 Tf 60 700 Td (".$this->escape($text).") Tj ET\n"
                .'BT /F1 11 Tf 60 660 Td (Page '.($n + 1).' of '.count($pages).". Not a real medical document.) Tj ET\n"
                ."0.6 g 60 640 475 1 re f\n";
            $objects[$page] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents {$content} 0 R >>";
            $objects[$content] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream";
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function image(string $text, string $format): string
    {
        $img = imagecreatetruecolor(640, 420);
        imagefilledrectangle($img, 0, 0, 639, 419, imagecolorallocate($img, 244, 247, 250));
        imagefilledrectangle($img, 0, 0, 639, 60, imagecolorallocate($img, 70, 110, 160));
        $ink = imagecolorallocate($img, 30, 40, 55);
        imagestring($img, 5, 20, 22, 'SYNTHETIC DEMO DOCUMENT', imagecolorallocate($img, 255, 255, 255));
        imagestring($img, 5, 20, 90, $text, $ink);
        imagestring($img, 3, 20, 130, 'Not a real medical document.', $ink);
        for ($i = 0; $i < 6; $i++) {
            imagefilledrectangle($img, 20, 180 + $i * 32, 220 + ($i * 61) % 330, 196 + $i * 32, imagecolorallocate($img, 190, 205, 225));
        }

        ob_start();
        $format === 'png' ? imagepng($img) : imagejpeg($img, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }
}
