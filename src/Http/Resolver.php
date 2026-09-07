<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Http;

/**
 * Turns a host into the addresses it points at.
 *
 * This is a seam rather than a static call so that the address checks in
 * {@see SafeFetcher} can be tested without a network: a test resolver can
 * hand back 127.0.0.1 for a public-looking hostname, which is precisely the
 * DNS rebinding case that must be rejected and precisely the case that is
 * impossible to arrange against the real resolver.
 */
interface Resolver
{
    /**
     * @return list<string> IP addresses, empty when the host does not resolve
     */
    public function resolve(string $host): array;
}
