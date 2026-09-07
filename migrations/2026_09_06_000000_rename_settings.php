<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

$renames = [
    'use_google_favicons' => 'google_favicon_fallback',
    'convert_media_urls' => 'skip_media_links',
    'blacklist' => 'blocklist',
    'whitelist' => 'allowlist',
];

$withdrawn = ['external_api_fallback', 'external_api_url'];

return [
    'up' => function (Builder $schema) use ($renames, $withdrawn): void {
        $connection = $schema->getConnection();

        foreach ($renames as $old => $new) {
            $value = $connection->table('settings')
                ->where('key', 'datlechin-link-preview.'.$old)
                ->value('value');

            if ($value === null) {
                continue;
            }

            $taken = $connection->table('settings')
                ->where('key', 'datlechin-link-preview.'.$new)
                ->exists();

            // Never overwrite: on a second run the new key holds whatever the
            // administrator has since chosen, and the old value is stale.
            if (! $taken) {
                $connection->table('settings')->insert([
                    'key' => 'datlechin-link-preview.'.$new,
                    'value' => $value,
                ]);
            }
        }

        $connection->table('settings')
            ->whereIn('key', array_map(
                fn (string $key): string => 'datlechin-link-preview.'.$key,
                array_merge(array_keys($renames), $withdrawn),
            ))
            ->delete();
    },

    // The withdrawn external API rows do not come back: their values were
    // deleted above and nothing here could invent them.
    'down' => function (Builder $schema) use ($renames): void {
        $connection = $schema->getConnection();

        foreach ($renames as $old => $new) {
            $value = $connection->table('settings')
                ->where('key', 'datlechin-link-preview.'.$new)
                ->value('value');

            if ($value === null) {
                continue;
            }

            $taken = $connection->table('settings')
                ->where('key', 'datlechin-link-preview.'.$old)
                ->exists();

            if (! $taken) {
                $connection->table('settings')->insert([
                    'key' => 'datlechin-link-preview.'.$old,
                    'value' => $value,
                ]);
            }
        }

        $connection->table('settings')
            ->whereIn('key', array_map(
                fn (string $key): string => 'datlechin-link-preview.'.$key,
                array_values($renames),
            ))
            ->delete();
    },
];
