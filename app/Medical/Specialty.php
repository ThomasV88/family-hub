<?php

namespace App\Medical;

/**
 * Static, versioned specialty taxonomy. The database stores only the key; labels are lang keys health.specialty.<key>.
 * A later version adds cases and bumps TAXONOMY_VERSION, never renames or removes one.
 */
enum Specialty: string
{
    public const TAXONOMY_VERSION = 1;

    case GeneralPractice = 'general_practice';
    case Pediatrics = 'pediatrics';
    case InternalMedicine = 'internal_medicine';
    case Gastroenterology = 'gastroenterology';
    case Cardiology = 'cardiology';
    case Pulmonology = 'pulmonology';
    case Endocrinology = 'endocrinology';
    case Diabetology = 'diabetology';
    case Nephrology = 'nephrology';
    case Urology = 'urology';
    case Gynecology = 'gynecology';
    case Dermatology = 'dermatology';
    case Ophthalmology = 'ophthalmology';
    case Ent = 'ent';
    case Orthopedics = 'orthopedics';
    case Neurology = 'neurology';
    case Psychiatry = 'psychiatry';
    case Psychology = 'psychology';
    case Rheumatology = 'rheumatology';
    case Oncology = 'oncology';
    case Hematology = 'hematology';
    case Allergology = 'allergology';
    case InfectiousDiseases = 'infectious_diseases';
    case Surgery = 'surgery';
    case Rehabilitation = 'rehabilitation';
    case Dentistry = 'dentistry';
    case Radiology = 'radiology';
    case Laboratory = 'laboratory';
    case Geriatrics = 'geriatrics';
    case Other = 'other';

    /**
     * The lang JSON is nested (it is shared with vue-i18n), which Laravel's flat JSON lookup cannot address, so the
     * labels are read from the locale's file directly and fall back to the translator.
     */
    public function label(?string $locale = null): string
    {
        static $cache = [];
        $locale ??= app()->getLocale();
        $cache[$locale] ??= $this->load($locale);

        return $cache[$locale][$this->value] ?? __('health.specialty.'.$this->value, [], $locale);
    }

    /** @return array<string, string> */
    private function load(string $locale): array
    {
        $file = lang_path(preg_replace('/[^a-z_-]/i', '', $locale).'.json');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $labels = is_array($data) ? ($data['health']['specialty'] ?? []) : [];

        return is_array($labels) ? array_filter($labels, 'is_string') : [];
    }
}
