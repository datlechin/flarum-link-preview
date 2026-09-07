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
    /**
     * Set well above what reading costs: one page view is one batch request,
     * and an office shares an address.
     */
    public const MAX_REQUESTS_PER_MINUTE = 30;

    /** @var list<string> */
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
            // Null, not false: false would exempt the route from every other
            // throttle.
            return null;
        }

        return $this->exceeded($this->identify($request)) ? true : null;
    }

    /**
     * `FileStore::increment()` is a `getPayload()` then a `put()` with nothing
     * held in between: sixty concurrent requests left that counter reading
     * between three and six and let all sixty through. Its `lock()` is built
     * on `add()`, which does take an exclusive `flock`.
     */
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
            // A cache that cannot say how much this reader has asked for
            // already makes an unknown count one too many.
            return true;
        }

        // `block()` answers whatever the callback answered, so anything that
        // is not a boolean means the callback never ran.
        return ! is_bool($counted) || $counted;
    }

    /**
     * The window is carried in the entry rather than left to the driver's own
     * expiry, which writing the count back would reset: a reader who kept
     * asking would push the window ahead of itself for ever.
     */
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

        // A count that was not written did not happen.
        if (! $this->cache->put($key, ['count' => $count, 'resets' => $resets], $resets - $now)) {
            return true;
        }

        return $count > self::MAX_REQUESTS_PER_MINUTE;
    }

    /**
     * Whether `add` and `increment` are one step is the driver's business, so
     * this undercounts a simultaneous burst where `increment` is not atomic.
     * It is right on APCu, whose `apcu_inc` is.
     */
    private function countWithoutALock(string $key): bool
    {
        $started = $this->cache->add($key, 0, self::WINDOW_SECONDS);
        $count = $this->cache->increment($key);

        // `add` also fails when the window expired between these two calls,
        // and the key `increment` then created would live for ever.
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
