<?php

namespace App\Medical\Export;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/** Download names carry the scope and the date, never a person name: health-export-<all|specialty>-<date>.<json|md>. */
final class Filename
{
    public static function name(string $scope, string $format, ?string $date = null): string
    {
        $slug = Str::slug($scope === 'all' ? 'all' : $scope);
        $extension = $format === 'md' ? 'md' : 'json';
        $date ??= now((string) config('family.timezone'))->toDateString();

        return 'health-export-'.($slug === '' ? 'all' : $slug).'-'.$date.'.'.$extension;
    }

    public static function disposition(string $scope, string $format, ?string $date = null): string
    {
        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, self::name($scope, $format, $date));
    }
}
