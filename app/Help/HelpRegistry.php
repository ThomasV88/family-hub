<?php

namespace App\Help;

use RuntimeException;

/**
 * The versioned registry of contextual help topics (the "?" tips). Source of truth for the keys, where each tip sits and
 * the default texts. resources/help/topics.json holds the structured placement (area, screen, field); resources/help/<locale>.json
 * holds the area and screen names, and a title plus the default text per topic. The database holds what an administrator
 * edited on top (see HelpTexts). A topic key never changes meaning: add a new key instead.
 */
class HelpRegistry
{
    public const LOCALES = ['sk', 'pl', 'en'];

    public const MAX_LENGTH = 400;

    /** @var list<array{key: string, area: string, screen: string, field: string}>|null */
    private ?array $topics = null;

    /** @var array<string, array<string, mixed>> */
    private array $files = [];

    public function __construct(private ?string $basePath = null)
    {
        $this->basePath ??= resource_path('help');
    }

    /** @return list<array{key: string, area: string, screen: string, field: string}> */
    public function topics(): array
    {
        if ($this->topics === null) {
            $data = $this->read($this->basePath.'/topics.json');
            $topics = [];
            foreach ((array) ($data['topics'] ?? []) as $topic) {
                $topics[] = [
                    'key' => (string) $topic['key'],
                    'area' => (string) $topic['area'],
                    'screen' => (string) $topic['screen'],
                    'field' => (string) $topic['field'],
                ];
            }
            $this->topics = $topics;
        }

        return $this->topics;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_column($this->topics(), 'key');
    }

    public function has(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }

    /** @return array{key: string, area: string, screen: string, field: string}|null */
    public function topic(string $key): ?array
    {
        foreach ($this->topics() as $topic) {
            if ($topic['key'] === $key) {
                return $topic;
            }
        }

        return null;
    }

    /** Default text of a topic in a locale; falls back to English, never empty for a registered key. */
    public function defaultText(string $key, string $locale): string
    {
        return $this->entry($key, $locale, 'text') ?: $this->entry($key, 'en', 'text');
    }

    /** Short name of the topic (the field or function the tip explains), used as the accessible name and in the admin list. */
    public function title(string $key, string $locale): string
    {
        return $this->entry($key, $locale, 'title') ?: $this->entry($key, 'en', 'title');
    }

    /** @return array<string, string> area id => label */
    public function areas(string $locale): array
    {
        return (array) ($this->file($locale)['areas'] ?? []);
    }

    /** @return array<string, string> screen id => label */
    public function screens(string $locale): array
    {
        return (array) ($this->file($locale)['screens'] ?? []);
    }

    private function entry(string $key, string $locale, string $field): string
    {
        $value = $this->file($locale)['topics'][$key][$field] ?? '';

        return is_string($value) ? $value : '';
    }

    /** @return array<string, mixed> */
    private function file(string $locale): array
    {
        $locale = in_array($locale, self::LOCALES, true) ? $locale : 'en';

        return $this->files[$locale] ??= $this->read($this->basePath."/{$locale}.json");
    }

    /** @return array<string, mixed> */
    private function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Help registry file missing: {$path}");
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
