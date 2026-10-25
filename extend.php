<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview;

use Datlechin\LinkPreview\Extend\LinkPreview;
use Datlechin\LinkPreview\Internal\Cards;
use Flarum\Extend;

$settings = new Extend\Settings();

foreach (Settings\Config::DEFAULTS as $key => $value) {
    $settings->default($key, $value);
}

$settings
    ->serializeToForum('datlechin-link-preview.batchRequests', 'datlechin-link-preview.enable_batch_requests', 'boolval')
    ->serializeToForum('datlechin-link-preview.blocklist', 'datlechin-link-preview.blocklist')
    ->serializeToForum('datlechin-link-preview.allowlist', 'datlechin-link-preview.allowlist')
    ->serializeToForum('datlechin-link-preview.googleFaviconFallback', 'datlechin-link-preview.google_favicon_fallback', 'boolval')
    ->serializeToForum('datlechin-link-preview.openLinksInNewTab', 'datlechin-link-preview.open_links_in_new_tab', 'boolval')
    ->serializeToForum('datlechin-link-preview.skipMediaLinks', 'datlechin-link-preview.skip_media_links', 'boolval')
    ->serializeToForum('datlechin-link-preview.previewInternalLinks', 'datlechin-link-preview.preview_internal_links', 'boolval')
    ->serializeToForum(
        'datlechin-link-preview.maxPreviewsPerPost',
        'datlechin-link-preview.max_previews_per_post',
        Settings\Config::previewLimit(...),
    );

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\ServiceProvider())
        ->register(LinkPreviewServiceProvider::class),

    (new Extend\Routes('api'))
        ->post('/datlechin-link-preview', 'datlechin-link-preview', Api\Controller\ShowLinkPreview::class)
        ->post('/datlechin-link-preview/batch', 'datlechin-link-preview.batch', Api\Controller\ShowLinkPreviewBatch::class),

    (new Extend\User())
        ->registerPreference('hideLinkPreviews', 'boolval', false),

    (new Extend\ThrottleApi())
        ->set('datlechinLinkPreview', Api\LinkPreviewThrottler::class),

    (new LinkPreview())
        ->route('default', Cards\ForumCard::class)
        ->route('discussion', Cards\DiscussionCard::class)
        ->route('user', Cards\UserCard::class),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-tags', fn () => [
            (new LinkPreview())->route('tag', Cards\TagCard::class),
        ]),

    $settings,
];
