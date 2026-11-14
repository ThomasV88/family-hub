<?php

namespace Database\Factories;

use App\Models\MedicalDocument;
use App\Models\MedicalSubject;
use App\Models\MedicalVisit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Rows only: the factory writes no files, so a test that needs bytes puts them on the (fake) medical disk itself.
 *
 * @extends Factory<MedicalDocument>
 */
class MedicalDocumentFactory extends Factory
{
    protected $model = MedicalDocument::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $path = fake()->uuid().'/'.strtoupper((string) Str::ulid()).'.pdf';

        return ['kind' => 'pdf', 'title' => 'Scan', 'mime' => 'application/pdf', 'size_bytes' => 100,
            'sha256' => hash('sha256', $path), 'path' => $path, 'thumb_path' => null, 'width' => null, 'height' => null];
    }

    public function forSubject(MedicalSubject $subject): static
    {
        return $this->state(fn () => ['subject_id' => $subject->id]);
    }

    public function forVisit(MedicalVisit $visit): static
    {
        return $this->state(fn () => ['visit_id' => $visit->id, 'subject_id' => $visit->subject_id]);
    }

    public function pdf(): static
    {
        return $this->state(function () {
            $path = fake()->uuid().'/'.strtoupper((string) Str::ulid()).'.pdf';

            return ['kind' => 'pdf', 'title' => 'Scan', 'mime' => 'application/pdf', 'size_bytes' => 100,
                'sha256' => hash('sha256', $path), 'path' => $path, 'thumb_path' => null, 'width' => null, 'height' => null];
        });
    }

    public function image(): static
    {
        return $this->state(function () {
            $base = fake()->uuid().'/'.strtoupper((string) Str::ulid());

            return ['kind' => 'image', 'title' => 'Photo', 'mime' => 'image/jpeg', 'size_bytes' => 100,
                'sha256' => hash('sha256', $base), 'path' => $base.'-full.jpg', 'thumb_path' => $base.'-thumb.jpg', 'width' => 64, 'height' => 48];
        });
    }

    public function heic(): static
    {
        return $this->state(function () {
            $path = fake()->uuid().'/'.strtoupper((string) Str::ulid()).'.heic';

            return ['kind' => 'heic', 'title' => 'Photo', 'mime' => 'image/heic', 'size_bytes' => 100,
                'sha256' => hash('sha256', $path), 'path' => $path, 'thumb_path' => null, 'width' => null, 'height' => null];
        });
    }
}
