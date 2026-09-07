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

/**
 * Four settings changed name and two were withdrawn.
 *
 * `blacklist` and `whitelist` are gone for the reason everybody drops them;
 * `use_google_favicons` and `convert_media_urls` are renamed because neither
 * name said what the code did with them.
 *
 * The external API fallback is withdrawn rather than renamed. It sent every URL
 * a member posted to a third party the administrator configured once and forgot,
 * and there is nowhere honest to put that value now.
 *
 * Written as data rather than as a key rename in place because the new keys have
 * defaults registered in extend.php: a forum that never touched the old settings
 * has no rows at all, and this has to be a no-op there.
 */
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

    // The four renames reverse. The external API rows do not: their values were
    // deleted above and nothing here could invent them back.
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
