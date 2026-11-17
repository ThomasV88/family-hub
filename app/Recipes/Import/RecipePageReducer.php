<?php

namespace App\Recipes\Import;

use App\Recipes\PlainText;
use App\Wishlist\Fetch\FetchResult;
use DateInterval;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use Normalizer;
use Throwable;

/**
 * Pure reduction of a fetched page to short, sanitised, labelled text for the recipe AI. No network, no
 * logging. The page is hostile data: nothing is executed or followed, every size is capped and the text is
 * normalised before it leaves this class.
 *
 * What is and is not guaranteed about hidden content (this is a filter, not a security boundary):
 * - Removed: elements with the `hidden` attribute, aria-hidden, a hidden-style class (hidden, d-none, sr-only, ...),
 *   and inline styles that hide by display:none, visibility:hidden, font-size:0, opacity:0, a zero or clipped box
 *   (height:0, max-height:0, width:0, clip, clip-path, scale(0)), or a far offscreen position (left, top, text-indent,
 *   margin). JSON-LD blocks inside such an element are skipped as well.
 * - NOT detectable: text hidden by colour (white on white), by an external or <style> stylesheet, by a script, by
 *   overlap with another element, or by tiny but non-zero sizes. Such text reaches the model.
 * - A JSON-LD block is hidden by nature and is read as it stands (strings are tag-stripped and capped: 500 characters
 *   for the description, 300 per ingredient, 1000 elsewhere, 300 lines). Text in it reaches the model like any other.
 * The real defences are elsewhere: the nonce markers around the page text, the tool-less read-only agent, the strict
 * validation of the reply, and the review-before-save screen the member always sees.
 */
final class RecipePageReducer
{
    private const ALLOWED_TYPES = ['text/html', 'application/xhtml+xml', 'text/plain'];

    private const CHARSETS = [
        'utf-8' => null, 'utf8' => null, 'us-ascii' => null, 'ascii' => null,
        'windows-1250' => 'Windows-1250', 'cp1250' => 'Windows-1250',
        'iso-8859-2' => 'ISO-8859-2', 'latin2' => 'ISO-8859-2',
        'windows-1252' => 'Windows-1252', 'cp1252' => 'Windows-1252',
        'iso-8859-1' => 'ISO-8859-1', 'latin1' => 'ISO-8859-1',
    ];

    private const JSON_DEPTH = 32;

    private const MAX_NODES = 5000;

    private const MAX_SCRIPTS = 50;

    private const MAX_LINES = 300;

    private const STRING_MAX = 1000;

    private const DESCRIPTION_MAX = 500;

    private const INGREDIENT_MAX = 300;

    private const NOISE = ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'form', 'nav', 'header', 'footer', 'aside', 'button', 'select'];

    /** Matched against an inline style with all whitespace removed and lower-cased. */
    private const HIDDEN_STYLE = '/display:none|visibility:hidden|font-size:0(?:px|pt|em|rem|%)?(?![\d.])|(?<![-\w])opacity:0(?![\d.])'
        .'|(?<![-\w])(?:max-)?(?:height|width):0(?:px|pt|em|rem|%)?(?![\d.])'
        .'|(?<![-\w])clip:rect\(0|clip-path:inset\((?:50|100)%|transform:scale\(0(?:,0)?\)'
        .'|(?:left|right|top|bottom|text-indent):-\d{3,}/';

    private const HIDDEN_CLASSES = ['hidden', 'd-none', 'invisible', 'sr-only', 'visually-hidden', 'screen-reader-text'];

    private const BLOCKS = ['address', 'article', 'blockquote', 'br', 'dd', 'details', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'h1', 'h2',
        'h3', 'h4', 'h5', 'h6', 'hr', 'li', 'main', 'ol', 'p', 'pre', 'section', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul'];

    private bool $cut = false;

    /** @throws PageRejected */
    public function reduce(FetchResult $r): ReducedPage
    {
        $this->cut = false;

        $this->assertContentType($r->contentType);
        $body = $this->decompress($r->body);
        if ($body === '') {
            throw new PageRejected(PageRejected::EMPTY);
        }
        $html = $this->toUtf8($body, $r->contentType);

        $doc = $this->parse($html);

        $recipe = $this->jsonLdRecipe($doc);
        if ($recipe !== null) {
            return $this->done(ReducedPage::KIND_JSON_LD, $recipe);
        }

        $this->stripNoise($doc);

        $micro = $this->microdata($doc);
        if ($micro !== null) {
            return $this->done(ReducedPage::KIND_MICRODATA, $micro);
        }

        return $this->done(ReducedPage::KIND_TEXT, $this->readableText($doc));
    }

    private function done(string $kind, string $text): ReducedPage
    {
        $text = $this->sanitise($text);
        $max = max(1, (int) config('recipes.import.max_text_chars'));
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max);
            $nl = mb_strrpos($text, "\n");
            if ($nl !== false && $nl > 0) {
                $text = mb_substr($text, 0, $nl);
            }
            $text = rtrim($text);
            $this->cut = true;
        }

        return new ReducedPage($kind, $text, $this->cut);
    }

    private function assertContentType(?string $contentType): void
    {
        if ($contentType === null || trim($contentType) === '') {
            return;
        }
        $type = strtolower(trim(explode(';', $contentType, 2)[0]));
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new PageRejected(PageRejected::NOT_HTML);
        }
    }

    private function decompress(string $body): string
    {
        $cap = max(1, (int) config('recipes.import.decoded_max_bytes'));
        if (! str_starts_with($body, "\x1f\x8b")) {
            if (strlen($body) > $cap) {
                throw new PageRejected(PageRejected::TOO_LARGE);
            }

            return $body;
        }

        // Incremental inflate in small steps so that a bomb never allocates more than about the cap.
        $ctx = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($ctx === false) {
            throw new PageRejected(PageRejected::NOT_HTML);
        }
        $out = '';
        $length = strlen($body);
        for ($offset = 0; $offset < $length; $offset += 1024) {
            $chunk = @inflate_add($ctx, substr($body, $offset, 1024), ZLIB_SYNC_FLUSH);
            if ($chunk === false) {
                throw new PageRejected(PageRejected::NOT_HTML);
            }
            $out .= $chunk;
            if (strlen($out) > $cap) {
                throw new PageRejected(PageRejected::TOO_LARGE);
            }
        }
        if (@inflate_get_status($ctx) !== ZLIB_STREAM_END) {
            throw new PageRejected(PageRejected::NOT_HTML);
        }

        return $out;
    }

    private function toUtf8(string $body, ?string $contentType): string
    {
        $charset = null;
        if ($contentType !== null && preg_match('/charset\s*=\s*["\']?\s*([A-Za-z0-9_\-:.]+)/i', $contentType, $m) === 1) {
            $charset = $this->knownCharset($m[1]);
        }
        if ($charset === null && preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([A-Za-z0-9_\-:.]+)/i', substr($body, 0, 4096), $m) === 1) {
            $charset = $this->knownCharset($m[1]);
        }
        $charset ??= 'utf-8';

        $target = self::CHARSETS[$charset];
        $html = $body;
        if ($target !== null) {
            try {
                $html = mb_convert_encoding($body, 'UTF-8', $target);
            } catch (Throwable) {
                $converted = @iconv($target, 'UTF-8//IGNORE', $body);
                if ($converted === false) {
                    throw new PageRejected(PageRejected::NOT_HTML);
                }
                $html = $converted;
            }
        }
        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            $html = substr($html, 3);
        }
        if (! mb_check_encoding($html, 'UTF-8')) {
            throw new PageRejected(PageRejected::NOT_HTML);
        }

        return $html;
    }

    private function knownCharset(string $name): ?string
    {
        $name = strtolower($name);

        return array_key_exists($name, self::CHARSETS) ? $name : null;
    }

    private function parse(string $html): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            // The XML declaration pins UTF-8 whatever the page's own meta tag claims.
            $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $doc;
    }

    // ---- JSON-LD ----------------------------------------------------------------------------------------

    private function jsonLdRecipe(DOMDocument $doc): ?string
    {
        $xp = new DOMXPath($doc);
        $scripts = $xp->query('//script[@type]');
        $fallback = null;
        $seen = 0;
        foreach ($scripts ?: [] as $script) {
            if (strtolower(trim($script->getAttribute('type'))) !== 'application/ld+json' || $this->insideHidden($script)) {
                continue;
            }
            if (++$seen > self::MAX_SCRIPTS) {
                break;
            }
            $data = json_decode($script->textContent, true, self::JSON_DEPTH);
            if (! is_array($data)) {
                continue;
            }
            $queue = [$data];
            for ($i = 0; $i < count($queue) && $i < self::MAX_NODES; $i++) {
                $node = $queue[$i];
                if ($this->isRecipe($node)) {
                    $hasIngredients = isset($node['recipeIngredient']) || isset($node['ingredients']);
                    if ($hasIngredients && isset($node['recipeInstructions'])) {
                        return $this->composeJsonLd($node);
                    }
                    if ($fallback === null && ($hasIngredients || isset($node['recipeInstructions']))) {
                        $fallback = $node;
                    }
                }
                foreach ($node as $child) {
                    if (is_array($child)) {
                        $queue[] = $child;
                    }
                }
            }
        }

        return $fallback === null ? null : $this->composeJsonLd($fallback);
    }

    /** @param  array<mixed>  $node */
    private function isRecipe(array $node): bool
    {
        $type = $node['@type'] ?? null;
        foreach (is_array($type) ? $type : [$type] as $t) {
            if (is_string($t) && strcasecmp(substr(strrchr('/'.str_replace(':', '/', $t), '/'), 1), 'Recipe') === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<mixed>  $node */
    private function composeJsonLd(array $node): string
    {
        $lines = [];
        $title = $this->scalarText($node['name'] ?? $node['headline'] ?? null);
        if ($title !== '') {
            $lines[] = 'Title: '.$title;
        }
        $description = $this->scalarText($node['description'] ?? null, self::DESCRIPTION_MAX, false);
        if ($description !== '') {
            $lines[] = 'Description: '.$description;
        }
        $yield = $this->listText($node['recipeYield'] ?? null);
        if ($yield !== '') {
            $lines[] = 'Yield: '.$yield;
        }
        foreach (['prepTime' => 'Prep time', 'cookTime' => 'Cook time', 'totalTime' => 'Total time'] as $key => $label) {
            $minutes = $this->minutes($node[$key] ?? null);
            if ($minutes !== null) {
                $lines[] = $label.': '.$minutes.' min';
            }
        }
        $category = $this->listText($node['recipeCategory'] ?? null);
        if ($category !== '') {
            $lines[] = 'Category: '.$category;
        }

        $ingredients = [];
        $this->collectLines($node['recipeIngredient'] ?? $node['ingredients'] ?? null, $ingredients, 0);
        if ($ingredients !== []) {
            $lines[] = 'Ingredients:';
            array_push($lines, ...$ingredients);
        }
        $steps = [];
        $this->collectSteps($node['recipeInstructions'] ?? null, $steps, 0);
        if ($steps !== []) {
            $lines[] = 'Steps:';
            array_push($lines, ...$steps);
        }

        return implode("\n", $lines);
    }

    private function scalarText(mixed $value, int $max = self::STRING_MAX, bool $flagCut = true): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (! is_string($value)) {
            return '';
        }
        $text = trim(preg_replace('/\s+/u', ' ', PlainText::strip($value)) ?? '');
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max);
            $this->cut = $this->cut || $flagCut;
        }

        return $text;
    }

    private function listText(mixed $value): string
    {
        $parts = [];
        foreach (is_array($value) ? array_slice($value, 0, 10) : [$value] as $item) {
            $text = $this->scalarText($item);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode(', ', $parts);
    }

    private function minutes(mixed $value): ?int
    {
        if (! is_string($value) || strlen($value) > 40 || preg_match('/^P(?!$)/i', trim($value)) !== 1) {
            return null;
        }
        try {
            $i = new DateInterval(strtoupper(trim($value)));
        } catch (Throwable) {
            return null;
        }
        $minutes = $i->d * 1440 + $i->h * 60 + $i->i + ($i->s >= 30 ? 1 : 0);

        return $minutes > 0 && $minutes <= 525600 ? $minutes : null;
    }

    /**
     * @param  list<string>  $out
     */
    private function collectLines(mixed $value, array &$out, int $depth): void
    {
        if ($depth > 3 || count($out) >= self::MAX_LINES) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->collectLines($item, $out, $depth + 1);
            }

            return;
        }
        $text = $this->scalarText($value, self::INGREDIENT_MAX);
        if ($text !== '') {
            $out[] = $text;
        }
    }

    /**
     * @param  list<string>  $out
     */
    private function collectSteps(mixed $value, array &$out, int $depth): void
    {
        if ($depth > 4 || count($out) >= self::MAX_LINES) {
            return;
        }
        if (is_string($value)) {
            foreach (preg_split('/\R/u', $value) ?: [] as $line) {
                $text = $this->scalarText($line);
                if ($text !== '' && count($out) < self::MAX_LINES) {
                    $out[] = $text;
                }
            }

            return;
        }
        if (! is_array($value)) {
            return;
        }
        if (array_is_list($value)) {
            foreach ($value as $item) {
                $this->collectSteps($item, $out, $depth + 1);
            }

            return;
        }
        if (isset($value['itemListElement'])) {
            $name = $this->scalarText($value['name'] ?? null);
            if ($name !== '') {
                $out[] = 'Section: '.$name;
            }
            $this->collectSteps($value['itemListElement'], $out, $depth + 1);

            return;
        }
        $this->collectSteps($value['text'] ?? $value['name'] ?? null, $out, $depth + 1);
    }

    // ---- DOM cleanup, microdata, readable text ----------------------------------------------------------

    private function stripNoise(DOMDocument $doc): void
    {
        $xp = new DOMXPath($doc);
        $remove = [];
        foreach ($xp->query('//comment() | //processing-instruction()') ?: [] as $n) {
            $remove[] = $n;
        }
        foreach ($xp->query('//*') ?: [] as $el) {
            /** @var DOMElement $el */
            if (in_array(strtolower($el->tagName), self::NOISE, true) || $this->isHidden($el)) {
                $remove[] = $el;
            }
        }
        foreach ($remove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /** True when the node or one of its ancestors is hidden (see the class docblock for what that covers). */
    private function insideHidden(DOMNode $node): bool
    {
        for ($n = $node->parentNode; $n instanceof DOMElement; $n = $n->parentNode) {
            if ($this->isHidden($n)) {
                return true;
            }
        }

        return false;
    }

    private function isHidden(DOMElement $el): bool
    {
        if ($el->hasAttribute('hidden') || strtolower(trim($el->getAttribute('aria-hidden'))) === 'true') {
            return true;
        }
        if ($el->hasAttribute('style')) {
            $style = preg_replace('/\s+/', '', strtolower($el->getAttribute('style'))) ?? '';
            if (preg_match(self::HIDDEN_STYLE, $style) === 1) {
                return true;
            }
        }
        if ($el->hasAttribute('class')) {
            foreach (preg_split('/\s+/', strtolower(trim($el->getAttribute('class')))) ?: [] as $class) {
                if (in_array($class, self::HIDDEN_CLASSES, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function microdata(DOMDocument $doc): ?string
    {
        $xp = new DOMXPath($doc);
        foreach ($xp->query('//*[contains(@itemtype,"Recipe")]') ?: [] as $root) {
            $ingredients = [];
            foreach ($xp->query('.//*[@itemprop="recipeIngredient" or @itemprop="ingredients"]', $root) ?: [] as $el) {
                $text = $this->scalarText($el->textContent);
                if ($text !== '' && count($ingredients) < self::MAX_LINES) {
                    $ingredients[] = $text;
                }
            }
            $steps = [];
            foreach ($xp->query('.//*[@itemprop="recipeInstructions"]', $root) ?: [] as $el) {
                $parts = $xp->query('.//*[@itemprop="text"] | .//li', $el);
                if ($parts !== false && $parts->length > 0) {
                    foreach ($parts as $part) {
                        $this->collectSteps($part->textContent, $steps, 0);
                    }
                } else {
                    $this->collectSteps($this->blockText($el), $steps, 0);
                }
            }
            if ($ingredients === [] && $steps === []) {
                continue;
            }
            $lines = [];
            $first = fn (string $prop): string => ($n = $this->first($xp, './/*[@itemprop="'.$prop.'"]', $root)) === null ? '' : $this->scalarText($n->textContent);
            $title = $first('name');
            if ($title !== '') {
                $lines[] = 'Title: '.$title;
            }
            $yield = $first('recipeYield');
            if ($yield !== '') {
                $lines[] = 'Yield: '.$yield;
            }
            if ($ingredients !== []) {
                $lines[] = 'Ingredients:';
                array_push($lines, ...$ingredients);
            }
            if ($steps !== []) {
                $lines[] = 'Steps:';
                array_push($lines, ...$steps);
            }

            return implode("\n", $lines);
        }

        return null;
    }

    private function readableText(DOMDocument $doc): string
    {
        $xp = new DOMXPath($doc);
        $lower = "translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')";
        $selectors = ['//*[contains(@itemtype,"Recipe")]', '//article', '//main', '//*[contains('.$lower.',"recipe")]', '//body'];

        $text = '';
        foreach ($selectors as $selector) {
            $node = $this->first($xp, $selector);
            if ($node === null) {
                continue;
            }
            $candidate = $this->blockText($node);
            if ($this->letters($candidate) >= 40) {
                $text = $candidate;
                break;
            }
        }
        if ($text === '') {
            throw new PageRejected(PageRejected::EMPTY);
        }

        $head = [];
        $title = $this->scalarText($this->first($xp, '//title')?->textContent);
        if ($title !== '') {
            $head[] = 'Title: '.$title;
        }
        $og = $this->scalarText($this->first($xp, '//meta[translate(@property,"OGTITLE","ogtitle")="og:title"]/@content')?->nodeValue);
        if ($og !== '' && $og !== $title) {
            $head[] = 'Headline: '.$og;
        }
        $desc = $this->scalarText($this->first($xp, '//meta[translate(@name,"DESCRIPTION","description")="description"]/@content')?->nodeValue, self::DESCRIPTION_MAX, false);
        if ($desc !== '') {
            $head[] = 'Description: '.$desc;
        }

        return implode("\n", [...$head, ...($head === [] ? [] : ['']), $text]);
    }

    private function first(DOMXPath $xp, string $query, ?DOMNode $context = null): ?DOMNode
    {
        $list = $context === null ? $xp->query($query) : $xp->query($query, $context);

        return $list === false ? null : $list->item(0);
    }

    private function letters(string $text): int
    {
        return (int) preg_match_all('/\p{L}/u', $text);
    }

    private function blockText(DOMNode $root): string
    {
        $out = '';
        $limit = max(1, (int) config('recipes.import.max_text_chars')) * 4;
        $this->walk($root, $out, $limit, 0);

        return $out;
    }

    private function walk(DOMNode $node, string &$out, int $limit, int $depth): void
    {
        if (strlen($out) > $limit || $depth > 200) {
            $this->cut = true;

            return;
        }
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $out .= preg_replace('/\s+/u', ' ', $child->data) ?? '';
            } elseif ($child instanceof DOMComment) {
                continue;
            } elseif ($child instanceof DOMElement) {
                $block = in_array(strtolower($child->tagName), self::BLOCKS, true);
                if ($block) {
                    $out .= "\n";
                }
                $this->walk($child, $out, $limit, $depth + 1);
                if ($block) {
                    $out .= "\n";
                }
            }
        }
    }

    // ---- sanitising -------------------------------------------------------------------------------------

    private function sanitise(string $text): string
    {
        $normalised = class_exists(Normalizer::class) ? Normalizer::normalize($text, Normalizer::FORM_C) : $text;
        $text = is_string($normalised) ? $normalised : '';

        $text = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}", "\u{85}", "\t", "\u{A0}"], ["\n", "\n", "\n", "\n", "\n", ' ', ' '], $text);
        // Control, format (zero-width, bidi, tag), private-use and unassigned characters; newline is kept.
        $text = preg_replace('/[^\P{C}\n]+/u', '', $text) ?? '';
        $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]+/u', '', $text) ?? '';

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim(preg_replace('/ {2,}/', ' ', $line) ?? '');
            if ($line === '' && ($lines === [] || end($lines) === '')) {
                continue;
            }
            $lines[] = $line;
        }
        $text = rtrim(implode("\n", $lines));

        return str_replace('<<<', '< < <', $text);
    }
}
