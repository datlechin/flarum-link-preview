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

/**
 * Why a preview could not be produced.
 *
 * A code rather than a sentence: the wording is chosen in the reader's browser,
 * and a failure cached with a translation already in it would hand every later
 * reader the first reader's language.
 */
enum PreviewError: string
{
    case InvalidUrl = 'invalid_url';
    case Blocked = 'blocked';
    case UnsafeAddress = 'unsafe_address';
    case Unreachable = 'unreachable';
    case HttpError = 'http_error';
    case NotPreviewable = 'not_previewable';
    case NoMetadata = 'no_metadata';
}
