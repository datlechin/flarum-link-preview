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
 * The request was allowed to happen but did not produce a page: a refused
 * connection, a timeout, a redirect loop, a body that stopped arriving, or any
 * status other than 200.
 *
 * When the site answered with a refusal the status rides on the exception code
 * and is reported as `http_error`. Every failure that never got an answer
 * leaves the code at zero and is reported as `unreachable`.
 */
final class FetchFailedException extends LinkPreviewException
{
}
