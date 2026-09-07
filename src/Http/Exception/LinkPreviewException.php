<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Http\Exception;

use RuntimeException;

/**
 * Base for every reason a preview could not be fetched.
 *
 * Each subclass maps to exactly one {@see \Datlechin\LinkPreview\Preview\PreviewError}
 * case, so the fetcher decides what went wrong and the previewer only has to
 * decide what to say about it. Messages are for the log and for whoever is
 * reading a stack trace; nothing in them ever reaches a response body, which
 * is what keeps an SSRF probe from learning whether a host resolved, refused
 * the connection or answered with the wrong content type.
 */
abstract class LinkPreviewException extends RuntimeException
{
}
