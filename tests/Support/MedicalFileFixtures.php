<?php

namespace Tests\Support;

/** Tiny medical upload fixtures generated in code (no binaries). */
final class MedicalFileFixtures
{
    public static function pdf(string $marker = ''): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 10 10]>>endobj\n% {$marker}\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    /** A valid minimal PDF with $n blank pages (correct xref offsets) that poppler renders. */
    public static function pdfPages(int $n): string
    {
        $objs = [1 => '<</Type/Catalog/Pages 2 0 R>>'];
        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (3 + $i).' 0 R';
        }
        $objs[2] = '<</Type/Pages/Kids['.implode(' ', $kids).']/Count '.$n.'>>';
        for ($i = 0; $i < $n; $i++) {
            $objs[3 + $i] = '<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 300]>>';
        }
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= $num." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $out .= sprintf("%010d 00000 n \n", $off);
        }

        return $out.'trailer<</Size '.(count($objs) + 1)."/Root 1 0 R>>\nstartxref\n".$xref."\n%%EOF\n";
    }

    /** A PDF header followed by markup: accepted as a PDF (it is only ever served as an attachment). */
    public static function pdfWithScriptTail(): string
    {
        return self::pdf().'<script>alert(1)</script>';
    }

    /** Synthetic HEIC: an ftyp box with the given major brand plus padding (never decoded). */
    public static function heic(string $brand = 'heic'): string
    {
        return pack('N', 24).'ftyp'.$brand.pack('N', 0).'heic'.'mif1'.str_repeat("\0", 64);
    }

    public static function avif(): string
    {
        return self::heic('avif');
    }

    public static function bmp(): string
    {
        $img = imagecreatetruecolor(4, 4);
        ob_start();
        imagebmp($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /** Junk before the PDF magic: the byte 0 rule must refuse it. */
    public static function pdfWithLeadingJunk(): string
    {
        return "junk\n".self::pdf();
    }

    public static function pdfAfterSpaces(): string
    {
        return str_repeat(' ', 500).self::pdf();
    }

    /** A JPEG with trailing PDF magic and PHP code, which a re-encode must drop. */
    public static function jpegWithTail(): string
    {
        return ImageFixtures::jpeg().'%PDF-1.7 <?php system($_GET[0]); ?>';
    }

    /** A JPEG body whose client file name will claim .heic or .pdf. */
    public static function mislabeledJpeg(): string
    {
        return ImageFixtures::jpeg();
    }
}
