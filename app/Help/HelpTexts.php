<?php

namespace App\Help;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\HelpText;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Runtime side of the contextual help: the texts the app shows (database row, else the registry default), the cached
 * per-locale payload shared with every page, the admin edit and the `help:sync` reconciliation of the table with the registry.
 * Texts are plain text. A missing or empty row falls back to the registry default, so a tip is never empty.
 */
class HelpTexts
{
    private const VERSION_KEY = 'help.version';

    public function __construct(private HelpRegistry $registry, private AuditLogger $audit) {}

    public function registry(): HelpRegistry
    {
        return $this->registry;
    }

    /** Changes whenever any text changes; part of the once-prop key and of the cache keys, so every client reloads after an edit. */
    public function version(): int
    {
        return (int) Cache::rememberForever(self::VERSION_KEY, fn () => (int) (microtime(true) * 1000));
    }

    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, max($this->version() + 1, (int) (microtime(true) * 1000)));
    }

    /**
     * The map every signed-in page receives: for each registered topic its short title and the text, in one locale.
     * Small (a few hundred characters per topic), not user specific and free of secrets.
     *
     * @return array{version: int, locale: string, topics: array<string, array{title: string, text: string}>}
     */
    public function payload(string $locale): array
    {
        $locale = in_array($locale, HelpRegistry::LOCALES, true) ? $locale : 'en';
        $version = $this->version();

        return Cache::remember("help.payload.{$locale}.{$version}", now()->addDays(7), function () use ($locale, $version): array {
            $rows = HelpText::query()->get(['key', "text_{$locale}"])->keyBy('key');
            $topics = [];
            foreach ($this->registry->keys() as $key) {
                $stored = (string) ($rows[$key]->{"text_{$locale}"} ?? '');
                $topics[$key] = [
                    'title' => $this->registry->title($key, $locale),
                    'text' => $stored !== '' ? $stored : $this->registry->defaultText($key, $locale),
                ];
            }

            return ['version' => $version, 'locale' => $locale, 'topics' => $topics];
        });
    }

    /**
     * Every registered topic with its placement, the texts in all locales, the defaults and the edited markers.
     *
     * @return array{topics: list<array<string, mixed>>, orphans: list<array<string, mixed>>}
     */
    public function all(string $labelLocale): array
    {
        $rows = HelpText::query()->get()->keyBy('key');
        $topics = [];
        foreach ($this->registry->topics() as $topic) {
            $topics[] = $this->detail($topic, $rows[$topic['key']] ?? null, $labelLocale);
        }

        $orphans = [];
        foreach ($rows as $key => $row) {
            if (! $this->registry->has($key)) {
                $orphans[] = [
                    'key' => $key,
                    'texts' => $this->textsOf($row),
                    'edited' => $this->editedOf($row),
                ];
            }
        }
        usort($orphans, fn (array $a, array $b) => $a['key'] <=> $b['key']);

        return ['topics' => $topics, 'orphans' => $orphans];
    }

    /** @return array<string, mixed>|null */
    public function find(string $key, string $labelLocale): ?array
    {
        $topic = $this->registry->topic($key);

        return $topic === null ? null : $this->detail($topic, HelpText::query()->find($key), $labelLocale);
    }

    /**
     * Saves edited texts and/or resets locales to the default. Returns the locales that were touched.
     *
     * @param  array<string, string>  $texts  locale => new text (already validated)
     * @param  list<string>  $reset  locales to restore to the registry default
     * @return list<string>
     */
    public function update(User $actor, string $key, array $texts, array $reset): array
    {
        $topic = $this->registry->topic($key) ?? throw new \InvalidArgumentException("Unknown help topic {$key}");

        $touched = DB::transaction(function () use ($actor, $topic, $key, $texts, $reset): array {
            $row = HelpText::query()->lockForUpdate()->find($key) ?? $this->insertDefaults($topic);
            $touched = [];
            foreach ($texts as $locale => $text) {
                $row->{"text_{$locale}"} = $this->normalize($text);
                $row->{"edited_{$locale}"} = true;
                $touched[] = $locale;
            }
            foreach ($reset as $locale) {
                $row->{"text_{$locale}"} = $this->registry->defaultText($key, $locale);
                $row->{"edited_{$locale}"} = false;
                $touched[] = $locale;
            }
            $row->edited_at = now();
            $row->edited_by = $actor->id;
            $row->save();

            return array_values(array_intersect(HelpRegistry::LOCALES, array_unique($touched)));
        });

        $this->flush();
        // Content-free: the key and the locale codes, never the text.
        $this->audit->record(AuditEvent::HelpTextChanged, $actor, ['key' => $key, 'locales' => implode(',', $touched)]);

        return $touched;
    }

    /**
     * Idempotent reconciliation with the registry: inserts missing keys with the defaults, refreshes the default text and the
     * placement of every locale an administrator has NOT edited, keeps edited texts and reports orphans (never deletes them).
     *
     * @return array{inserted: int, refreshed: int, unchanged: int, kept_edited: int, orphans: list<string>}
     */
    public function sync(): array
    {
        $inserted = $refreshed = $unchanged = $keptEdited = 0;

        DB::transaction(function () use (&$inserted, &$refreshed, &$unchanged, &$keptEdited): void {
            $rows = HelpText::query()->lockForUpdate()->get()->keyBy('key');
            foreach ($this->registry->topics() as $topic) {
                $row = $rows[$topic['key']] ?? null;
                if ($row === null) {
                    $this->insertDefaults($topic);
                    $inserted++;

                    continue;
                }
                $row->area = $topic['area'];
                $row->screen = $topic['screen'];
                $row->field = $topic['field'];
                foreach (HelpRegistry::LOCALES as $locale) {
                    if ($row->{"edited_{$locale}"}) {
                        $keptEdited++;
                    } else {
                        $row->{"text_{$locale}"} = $this->registry->defaultText($topic['key'], $locale);
                    }
                }
                if ($row->isDirty()) {
                    $row->save();
                    $refreshed++;
                } else {
                    $unchanged++;
                }
            }
        });

        $this->flush();
        $orphans = HelpText::query()->whereNotIn('key', $this->registry->keys() ?: [''])->orderBy('key')->pluck('key')->all();

        return ['inserted' => $inserted, 'refreshed' => $refreshed, 'unchanged' => $unchanged, 'kept_edited' => $keptEdited, 'orphans' => $orphans];
    }

    /** @param array{key: string, area: string, screen: string, field: string} $topic */
    private function insertDefaults(array $topic): HelpText
    {
        $values = ['key' => $topic['key'], 'area' => $topic['area'], 'screen' => $topic['screen'], 'field' => $topic['field']];
        foreach (HelpRegistry::LOCALES as $locale) {
            $values["text_{$locale}"] = $this->registry->defaultText($topic['key'], $locale);
        }

        return HelpText::query()->create($values)->refresh();
    }

    /**
     * @param  array{key: string, area: string, screen: string, field: string}  $topic
     * @return array<string, mixed>
     */
    private function detail(array $topic, ?HelpText $row, string $labelLocale): array
    {
        $key = $topic['key'];
        $texts = [];
        $defaults = [];
        $titles = [];
        foreach (HelpRegistry::LOCALES as $locale) {
            $default = $this->registry->defaultText($key, $locale);
            $stored = $row ? (string) $row->{"text_{$locale}"} : '';
            $texts[$locale] = $stored !== '' ? $stored : $default;
            $defaults[$locale] = $default;
            $titles[$locale] = $this->registry->title($key, $locale);
        }
        $areas = $this->registry->areas($labelLocale);
        $screens = $this->registry->screens($labelLocale);

        return [
            'key' => $key,
            'area' => $topic['area'],
            'screen' => $topic['screen'],
            'field' => $topic['field'],
            'location' => [
                'area' => $areas[$topic['area']] ?? $topic['area'],
                'screen' => $screens[$topic['screen']] ?? $topic['screen'],
                'field' => $titles[$labelLocale] ?? $key,
            ],
            'titles' => $titles,
            'texts' => $texts,
            'defaults' => $defaults,
            'edited' => $row ? $this->editedOf($row) : ['sk' => false, 'pl' => false, 'en' => false],
            'editedAt' => $row?->edited_at?->toIso8601String(),
        ];
    }

    /** @return array<string, string> */
    private function textsOf(HelpText $row): array
    {
        return ['sk' => (string) $row->text_sk, 'pl' => (string) $row->text_pl, 'en' => (string) $row->text_en];
    }

    /** @return array<string, bool> */
    private function editedOf(HelpText $row): array
    {
        return ['sk' => (bool) $row->edited_sk, 'pl' => (bool) $row->edited_pl, 'en' => (bool) $row->edited_en];
    }

    private function normalize(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }
}
