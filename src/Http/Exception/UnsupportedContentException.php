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
 * The response arrived and was fine, it just is not a document with metadata
 * in it: a PDF, an image, a JSON API. Reported as `not_previewable`.
 */
final class UnsupportedContentException extends LinkPreviewException
{
}
