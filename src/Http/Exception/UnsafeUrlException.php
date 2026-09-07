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

/**
 * The URL is one the forum must not connect to at all: a scheme that is not
 * http or https, a host with no address, or a host that only resolves inside
 * the network the forum is running in. Reported as `unsafe_address`.
 */
final class UnsafeUrlException extends LinkPreviewException
{
}
