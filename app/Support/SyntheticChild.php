<?php

namespace App\Support;

/**
 * What counts as a synthetic (staging demo) child, for the staging seeder's purge and for staging:audit.
 *
 * A child has no e-mail, so the demo family's `@example.test` rule cannot mark it. The name suffix " (demo)" alone is
 * free text anyone can type, so it is only one half of the marker. The other half is structural and cannot be typed
 * into a form: the child must be managed by at least one wish-list manager and every manager must be a synthetic
 * `@example.test` member. A real child (or a real child renamed "Anna (demo)") is managed by a real parent, so it is
 * never purged and always flagged by the audit; a child nobody manages is not synthetic either.
 */
final class SyntheticChild
{
    /**
     * SQL boolean for a `users` row aliased as $alias.
     * Safe to embed: $alias is a code constant, never user input.
     */
    public static function condition(string $alias = 'c'): string
    {
        return "({$alias}.email is null and {$alias}.name like '% (demo)'"
            ." and exists (select 1 from wishlist_managers m where m.owner_id = {$alias}.id)"
            .' and not exists (select 1 from wishlist_managers m join users mu on mu.id = m.manager_id'
            ." where m.owner_id = {$alias}.id and (mu.email is null or mu.email not like '%@example.test')))";
    }
}
