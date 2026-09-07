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
 * An entry names a host, optionally a path prefix, anchored at both ends; a `*`
 * cannot cross a dot inside the host, so `*.example.com` covers one site's
 * subdomains, not every address that contains it.
 */
final class UrlFilter
{
    /**
     * @param  list<string>  $allowlist
     * @param  list<string>  $blocklist
     */
    public function __construct(private array $allowlist, private array $blocklist)
    {
    }

    public function allows(string $url): bool
    {
        $target = self::split($url);

        if ($this->allowlist !== [] && ! self::matchesAny($target, $this->allowlist)) {
            return false;
        }

        return $this->blocklist === [] || ! self::matchesAny($target, $this->blocklist);
    }

    /**
     * @param  array{host: string, path: string}  $target
     * @param  list<string>  $entries
     */
    private static function matchesAny(array $target, array $entries): bool
    {
        foreach ($entries as $entry) {
            if (self::matches($target, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{host: string, path: string}  $target
     */
    private static function matches(array $target, string $entry): bool
    {
        $rule = self::split($entry);

        if ($rule['host'] === '') {
            return false;
        }

        $bare = $rule['path'] === '';

        // A bare host covers itself and everything under it, so blocking
        // `example.com` does not leave `cdn.example.com` fetchable. A rule with
        // a path is not that shorthand and stops at the host it names.
        $host = ($bare ? '(?:.+\.)?' : '').self::hostPattern($rule['host']);

        if (! preg_match('~^'.$host.'$~', $target['host'])) {
            return false;
        }

        if ($bare) {
            return true;
        }

        // With a path the entry is a prefix, but only at a boundary: `/blog`
        // covers `/blog/post` and `/blog?page=2` and not `/blogger`.
        return (bool) preg_match('~^'.self::pathPattern($rule['path']).'(?:/.*)?$~', $target['path']);
    }

    private static function hostPattern(string $entry): string
    {
        return str_replace('\*', '[^.]*', preg_quote($entry, '~'));
    }

    private static function pathPattern(string $entry): string
    {
        return str_replace('\*', '.*', preg_quote($entry, '~'));
    }

    /**
     * The browser side normalises by these same rules, so a link it drops
     * without asking is one this would have refused anyway.
     *
     * @return array{host: string, path: string}
     */
    private static function split(string $value): array
    {
        $value = trim($value);

        // parse_url only reads an authority when the value has one, and a rule
        // is written as `example.com/blog` with no scheme in front of it.
        $authority = preg_match('~^[a-z][a-z0-9+.-]*://~i', $value) === 1 ? $value : '//'.ltrim($value, '/');

        $parts = parse_url($authority);
        $host = is_array($parts) ? $parts['host'] ?? null : null;

        if (! is_string($host) || $host === '') {
            return ['host' => '', 'path' => ''];
        }

        $host = strtolower(rtrim($host, '.'));
        $path = is_array($parts) ? $parts['path'] ?? null : null;

        return [
            'host' => preg_replace('~^www\.~', '', $host) ?? $host,
            'path' => is_string($path) ? strtolower(rtrim($path, '/')) : '',
        ];
    }
}
