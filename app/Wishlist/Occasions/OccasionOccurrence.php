<?php

namespace App\Wishlist\Occasions;

use Carbon\CarbonImmutable;

/** One concrete upcoming occasion. Names and titles are user content: render as text only. */
final readonly class OccasionOccurrence
{
    public const BIRTHDAY = 'birthday';

    public const NAMEDAY = 'nameday';

    public const CHRISTMAS = 'christmas';

    public const CUSTOM = 'custom';

    public const SUBJECT_BIRTHDAY = 'user_birthday';

    public const SUBJECT_OCCASION = 'wish_occasion';

    public const SUBJECT_CHRISTMAS = 'christmas';

    public function __construct(
        public string $type,
        public ?int $ownerId,
        public ?string $ownerName,
        public ?string $title,
        public ?int $occasionId,
        public CarbonImmutable $date,
        public int $daysAway,
        public string $subjectType,
        public int $subjectId,
    ) {}
}
