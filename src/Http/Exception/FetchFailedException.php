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
 * The exception code carries the HTTP status when a site answered, and stays
 * at zero when nothing did.
 */
final class FetchFailedException extends LinkPreviewException
{
}
