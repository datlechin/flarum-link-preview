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
 * The real resolver, asking the host it is running on.
 *
 * Both address families are looked up. The code this replaces called
 * `gethostbyname()`, which only knows about A records and, worse, returns the
 * hostname it was given when the lookup fails, so on an IPv6-only host every
 * preview in the forum failed with no way to tell that DNS was the reason.
 * That is one of the two suspected causes of issue #39.
 */
final class SystemResolver implements Resolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $literal = trim($host, '[]');

        // A URL may name an address directly, in which case there is nothing
        // to look up. The brackets an IPv6 literal wears in a URL are not part
        // of the address and would fail every check downstream.
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        $addresses = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }
}
