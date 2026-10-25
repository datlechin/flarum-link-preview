<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class LinkPreviewThrottler
{
    public const MAX_REQUESTS_PER_MINUTE = 30;

    private const ROUTES = ['datlechin-link-preview', 'datlechin-link-preview.batch'];

    private const WINDOW_SECONDS = 60;

    private const CACHE_PREFIX = 'datlechin-link-preview:throttle:';

    private const LOCK_PREFIX = 'datlechin-link-preview:throttle-lock:';

    private const LOCK_SECONDS = 5;

    private const LOCK_WAIT_SECONDS = 2;

    public function __construct(private Repository $cache)
    {
    }

    public function __invoke(ServerRequestInterface $request): ?bool
    {
        if (! in_array($request->getAttribute('routeName'), self::ROUTES, true)) {
            return null;
        }

        return $this->exceeded($this->identify($request)) ? true : null;
    }

    private function exceeded(string $id): bool
    {
        $key = self::CACHE_PREFIX.$id;

        try {
            $store = $this->cache->getStore();

            if (! $store instanceof LockProvider) {
                return $this->countWithoutALock($key);
            }

            $counted = $store->lock(self::LOCK_PREFIX.$id, self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, fn (): bool => $this->count($key));
        } catch (Throwable) {
            return true;
        }

        return ! is_bool($counted) || $counted;
    }

    private function count(string $key): bool
    {
        $now = time();
        $entry = $this->cache->get($key);
        $entry = is_array($entry) ? $entry : [];

        $stored = $entry['count'] ?? null;
        $expires = $entry['resets'] ?? null;

        $count = 1;
        $resets = $now + self::WINDOW_SECONDS;

        if (is_int($stored) && is_int($expires) && $expires > $now) {
            $count = $stored + 1;
            $resets = $expires;
        }

        if (! $this->cache->put($key, ['count' => $count, 'resets' => $resets], $resets - $now)) {
            return true;
        }

        return $count > self::MAX_REQUESTS_PER_MINUTE;
    }

    private function countWithoutALock(string $key): bool
    {
        $started = $this->cache->add($key, 0, self::WINDOW_SECONDS);
        $count = $this->cache->increment($key);

        if (! $started && $count === 1) {
            $this->cache->put($key, 1, self::WINDOW_SECONDS);
        }

        return ! is_int($count) || $count > self::MAX_REQUESTS_PER_MINUTE;
    }

    private function identify(ServerRequestInterface $request): string
    {
        $actor = RequestUtil::getActor($request);

        if (! $actor->isGuest()) {
            return (string) $actor->id;
        }

        $ip = $request->getAttribute('ipAddress');

        return is_string($ip) ? $ip : 'unknown';
    }
}
