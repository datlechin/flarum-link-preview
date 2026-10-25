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

final class UrlFilter
{
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

    private static function matchesAny(array $target, array $entries): bool
    {
        foreach ($entries as $entry) {
            if (self::matches($target, $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(array $target, string $entry): bool
    {
        $rule = self::split($entry);

        if ($rule['host'] === '') {
            return false;
        }

        $bare = $rule['path'] === '';

        $host = ($bare ? '(?:.+\.)?' : '').self::hostPattern($rule['host']);

        if (! preg_match('~^'.$host.'$~', $target['host'])) {
            return false;
        }

        if ($bare) {
            return true;
        }

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

    private static function split(string $value): array
    {
        $value = trim($value);

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
