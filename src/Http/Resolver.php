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
 * An interface so a test can hand back 127.0.0.1 for a public-looking hostname,
 * the DNS rebinding case {@see SafeFetcher} must reject and the one case that
 * cannot be arranged against the real resolver.
 */
interface Resolver
{
    /**
     * @return list<string> IP addresses, empty when the host does not resolve
     */
    public function resolve(string $host): array;
}
