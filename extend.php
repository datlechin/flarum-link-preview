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

use Flarum\Extend;

$settings = new Extend\Settings();

// Declared from the one place that also reads them back, so a key cannot be
// added there and left with no default on a forum that has never opened the
// settings page.
foreach (Settings\Config::DEFAULTS as $key => $value) {
    $settings->default($key, $value);
}

$settings
    ->serializeToForum('datlechin-link-preview.batchRequests', 'datlechin-link-preview.enable_batch_requests', 'boolval')
    // The lists are serialized so the browser can drop a blocked link
    // before it costs a request. The server filters again regardless.
    ->serializeToForum('datlechin-link-preview.blocklist', 'datlechin-link-preview.blocklist')
    ->serializeToForum('datlechin-link-preview.allowlist', 'datlechin-link-preview.allowlist')
    ->serializeToForum('datlechin-link-preview.googleFaviconFallback', 'datlechin-link-preview.google_favicon_fallback', 'boolval')
    ->serializeToForum('datlechin-link-preview.openLinksInNewTab', 'datlechin-link-preview.open_links_in_new_tab', 'boolval')
    ->serializeToForum('datlechin-link-preview.skipMediaLinks', 'datlechin-link-preview.skip_media_links', 'boolval')
    ->serializeToForum('datlechin-link-preview.previewInternalLinks', 'datlechin-link-preview.preview_internal_links', 'boolval')
    // Read through the same clamp the server reads it through, so the number
    // the browser caps a post by is the number the server would enforce. Sent
    // raw, a stored 0 turns every preview off in the browser while the server
    // treats it as one, and a row that is not a number reaches the client as a
    // cap of nothing rather than as the default.
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

    // Both are POST, including the one that only reads: a GET is reachable
    // cross site from an `<img src>` and would let any page on the web aim
    // this forum's outbound connections.
    (new Extend\Routes('api'))
        ->post('/datlechin-link-preview', 'datlechin-link-preview', Api\Controller\ShowLinkPreview::class)
        ->post('/datlechin-link-preview/batch', 'datlechin-link-preview.batch', Api\Controller\ShowLinkPreviewBatch::class),

    // A preference rather than a setting: reading choice, not forum policy.
    (new Extend\User())
        ->registerPreference('hideLinkPreviews', 'boolval', false),

    (new Extend\ThrottleApi())
        ->set('datlechinLinkPreview', Api\LinkPreviewThrottler::class),

    $settings,
];
