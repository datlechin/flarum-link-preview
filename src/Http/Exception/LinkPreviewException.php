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
 * Messages are for the log only: one in a response body would tell an SSRF
 * probe whether a host resolved, refused the connection or answered with the
 * wrong content type.
 */
abstract class LinkPreviewException extends RuntimeException
{
}
