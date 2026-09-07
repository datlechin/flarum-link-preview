<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Preview;

use Datlechin\LinkPreview\Http\Exception\LinkPreviewException;

/**
 * A hop the forum's own lists refuse. Reported as `blocked`.
 *
 * A `LinkPreviewException` because that is how a refusal gets attributed to the
 * URL that was asked for, instead of taking the rest of the batch down with it.
 */
final class BlockedUrlException extends LinkPreviewException
{
}
