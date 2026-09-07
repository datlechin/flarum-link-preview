<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Http;

/**
 * What came back from a page the fetcher was allowed to read.
 *
 * `$effectiveUrl` is the address the body actually came from, which is the
 * last hop of a redirect chain and not necessarily what was asked for. It is
 * the only correct base for resolving a relative `og:image`, and it is the URL
 * the preview links to.
 *
 * `$contentType` is the raw header, charset parameter and all, because the
 * extractor needs that parameter to decode the body before parsing it.
 */
final class FetchResult
{
    public function __construct(
        public readonly string $body,
        public readonly string $effectiveUrl,
        public readonly ?string $contentType,
    ) {
    }
}
