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
 * Nothing renders these: a link whose preview fails keeps the plain link it
 * already was.
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
