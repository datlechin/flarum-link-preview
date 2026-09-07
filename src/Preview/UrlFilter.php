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
 * Decides whether an address is one the forum is willing to fetch.
 *
 * The rules an administrator writes are host rules, so they are matched
 * against the host. The version this replaces built a regular expression from
 * each entry and ran it unanchored over the whole URL, which meant `com`
 * blocked every site on the internet and `evil.com` also blocked
 * `notevil.com.example.org`. Both are the same bug: a rule that was meant to
 * name a site was being asked whether it appeared anywhere in a string.
 *
 * Here an entry names a host, optionally followed by a path prefix, and both
 * ends are anchored. A `*` still stands in for a run of characters, but inside
 * the host it cannot cross a dot, so `*.example.com` covers the subdomains of
 * one site rather than every site whose address happens to contain it.
 *
 * The host comes from `parse_url()` rather than from everything up to the
 * first slash, because the two are not the same string: `x@example.com` and
 * `example.com:8443` and `example.com.` all reach the site an administrator
 * blocked by writing `example.com`, and the old reading let all three through.
 *
 * Nothing in here touches the network or the container: the same list must
 * give the same answer for the same URL every time, or the cached preview and
 * the filter disagree.
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
        // `example.com` does not leave `cdn.example.com` fetchable. A path is
        // only written for the host it names, so a rule carrying one is not
        // the same shorthand and stops at that host.
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
     * Reduce an address and a rule to the same vocabulary.
     *
     * An administrator writes `https://www.example.com/` and means the same
     * thing as `example.com`, and a reader's link says whichever of the two
     * the site they copied it from prefers. The browser side normalises to
     * these same rules, so a link it drops without asking is a link this would
     * have refused anyway.
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
            // A query and a fragment are not part of what a rule names, and
            // paths are compared in one case so that an administrator does not
            // have to guess which one a link will arrive in.
            'path' => is_string($path) ? strtolower(rtrim($path, '/')) : '',
        ];
    }
}
