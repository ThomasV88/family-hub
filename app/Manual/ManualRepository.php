<?php

namespace App\Manual;

use App\Models\User;

/**
 * Reads the end-user manual from versioned files: resources/manual/<locale>/_categories.json and one <topic>.json per topic.
 * Content is structured data (never HTML); the page renders it with a small whitelist renderer. A topic or section flagged
 * adminOnly is dropped here, on the server, for everyone who is not an administrator.
 */
class ManualRepository
{
    public const LOCALES = ['sk', 'pl', 'en'];

    public function __construct(private ?string $basePath = null)
    {
        $this->basePath ??= resource_path('manual');
    }

    /**
     * @return array{categories: list<array{id: string, title: string}>, topics: list<array<string, mixed>>}
     */
    public function for(User $user, string $locale): array
    {
        $locale = in_array($locale, self::LOCALES, true) ? $locale : 'en';
        $isAdmin = (bool) $user->is_admin;

        $categories = $this->categories($locale);
        $known = array_column($categories, 'id');

        $topics = [];
        foreach ($this->topicIds() as $id) {
            $topic = $this->topic($id, $locale);
            if ($topic === null || ! in_array($topic['category'], $known, true)) {
                continue;
            }
            if (($topic['adminOnly'] ?? false) && ! $isAdmin) {
                continue;
            }
            $topics[] = $this->clean($topic, $isAdmin);
        }

        usort($topics, fn (array $a, array $b) => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);

        $used = array_unique(array_column($topics, 'category'));
        $categories = array_values(array_filter($categories, fn (array $c) => in_array($c['id'], $used, true)));

        return ['categories' => $categories, 'topics' => $topics];
    }

    /** @return list<string> topic ids, taken from the English files (the source language). */
    public function topicIds(): array
    {
        $ids = [];
        foreach (glob($this->basePath.'/en/*.json') ?: [] as $file) {
            $name = basename($file, '.json');
            if (! str_starts_with($name, '_')) {
                $ids[] = $name;
            }
        }
        sort($ids);

        return $ids;
    }

    /** @return list<array{id: string, title: string}> */
    private function categories(string $locale): array
    {
        $data = $this->read($this->basePath.'/'.$locale.'/_categories.json')
            ?? $this->read($this->basePath.'/en/_categories.json');

        return array_values(array_filter(
            array_map(fn ($c) => is_array($c) && is_string($c['id'] ?? null) && is_string($c['title'] ?? null)
                ? ['id' => $c['id'], 'title' => $c['title']] : null, $data['categories'] ?? []),
        ));
    }

    /** @return array<string, mixed>|null the topic in the locale, falling back to English when the file is missing. */
    private function topic(string $id, string $locale): ?array
    {
        $topic = $this->read($this->basePath.'/'.$locale.'/'.$id.'.json');
        if ($topic === null && $locale !== 'en') {
            $topic = $this->read($this->basePath.'/en/'.$id.'.json');
        }

        return $topic !== null && ($topic['id'] ?? null) === $id && is_string($topic['category'] ?? null) ? $topic : null;
    }

    /** @return array<string, mixed>|null */
    private function read(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            report($e);

            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Keeps only what the page renders: drops internal metadata (covers) and admin-only sections for non-admins.
     *
     * @param  array<string, mixed>  $topic
     * @return array<string, mixed>
     */
    private function clean(array $topic, bool $isAdmin): array
    {
        $sections = [];
        foreach ($topic['sections'] ?? [] as $section) {
            if (! is_array($section) || (($section['adminOnly'] ?? false) && ! $isAdmin)) {
                continue;
            }
            $sections[] = [
                'id' => (string) ($section['id'] ?? ''),
                'title' => (string) ($section['title'] ?? ''),
                'adminOnly' => (bool) ($section['adminOnly'] ?? false),
                'blocks' => array_values(is_array($section['blocks'] ?? null) ? $section['blocks'] : []),
            ];
        }

        return [
            'id' => $topic['id'],
            'category' => $topic['category'],
            'order' => (int) ($topic['order'] ?? 100),
            'title' => (string) ($topic['title'] ?? ''),
            'summary' => (string) ($topic['summary'] ?? ''),
            'adminOnly' => (bool) ($topic['adminOnly'] ?? false),
            'sections' => $sections,
        ];
    }
}
