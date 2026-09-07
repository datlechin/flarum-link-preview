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
 * `$effectiveUrl` is the last hop of the redirect chain rather than what was
 * asked for, so it is the base a relative `og:image` resolves against.
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
