<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Internal;

use Flarum\User\User;

interface RouteCard
{
    public function card(MatchedRoute $route, User $actor): ?Card;
}
