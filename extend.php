<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2022 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview;

use Flarum\Extend;
use Flarum\Settings\Event\Deserializing;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    new Extend\Locales(__DIR__ . '/locale'),

    (new Extend\Routes('api'))
        ->get('/datlechin-link-preview', 'datlechin-link-preview', Api\Controllers\ScrapperController::class),

    (new Extend\Settings())
        ->serializeToForum('datlechin-link-preview.blacklist', 'datlechin-link-preview.blacklist')
        ->serializeToForum('datlechin-link-preview.whitelist', 'datlechin-link-preview.whitelist')
        ->serializeToForum('datlechin-link-preview.useGoogleFavicons', 'datlechin-link-preview.use_google_favicons', 'boolval')
        ->serializeToForum('datlechin-link-preview.convertMediaURLs', 'datlechin-link-preview.convert_media_urls', 'boolval'),

    (new Extend\Event())
        ->listen(Deserializing::class, function (Deserializing $event) {
            $event->settings = array_merge(
                [
                    'datlechin-link-preview.use_google_favicons' => false,
                    'datlechin-link-preview.convert_media_urls' => false,
                    'datlechin-link-preview.cache_time' => 60,
                ],
                $event->settings
            );
        })
];
