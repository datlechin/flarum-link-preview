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
 * Both address families are looked up: a host carrying only AAAA records must
 * not come back as one that does not resolve.
 */
final class SystemResolver implements Resolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $literal = trim($host, '[]');

        // The brackets an IPv6 literal wears in a URL are not part of the
        // address and would fail every check downstream.
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
