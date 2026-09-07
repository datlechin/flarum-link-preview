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

/**
 * A ceiling on how often one reader may ask this forum to fetch remote pages.
 *
 * The endpoint is open to guests and every request it accepts can become an
 * outbound connection. The cache absorbs the ordinary case, so anyone reaching
 * this limit is asking for URLs nobody has asked for before, which is what
 * using the forum as a scanner looks like. Set well above what reading costs:
 * one page view is one batch request, and an office shares an address.
 */
final class LinkPreviewThrottler
{
    public const MAX_REQUESTS_PER_MINUTE = 30;

    /** @var list<string> */
    private const ROUTES = ['datlechin-link-preview', 'datlechin-link-preview.batch'];

    private const WINDOW_SECONDS = 60;

    private const CACHE_PREFIX = 'datlechin-link-preview:throttle:';

    private const LOCK_PREFIX = 'datlechin-link-preview:throttle-lock:';

    /**
     * A bound on how long a worker killed mid-count can shut its own address
     * out, not a budget: two cache operations need a fraction of this.
     */
    private const LOCK_SECONDS = 5;

    /**
     * How long a request waits its turn before being turned away uncounted.
     */
    private const LOCK_WAIT_SECONDS = 2;

    public function __construct(private Repository $cache)
    {
    }

    public function __invoke(ServerRequestInterface $request): ?bool
    {
        if (! in_array($request->getAttribute('routeName'), self::ROUTES, true)) {
            // Null rather than false: false would exempt other routes from
            // everybody else's throttles.
            return null;
        }

        return $this->exceeded($this->identify($request)) ? true : null;
    }

    /**
     * Count this request and say whether it is one too many.
     *
     * The read and the write have to be one step, or twenty requests landing
     * together all read the same number and cost one request out of the budget
     * instead of twenty. `increment` is not that step on the store Flarum
     * installs: `FileStore::increment()` is a `getPayload()` then a `put()`
     * with nothing held in between, and sixty concurrent requests left that
     * counter reading between three and six and let all sixty through. Its
     * `lock()` is built on `add()`, which does take an exclusive `flock`, so
     * the read and the write run under that.
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
            // A cache that is not answering cannot say how much this reader has
            // already asked for. An endpoint that makes outbound connections
            // and has lost its only ceiling is worse than a card that does not
            // load, so an unknown count is one too many.
            return true;
        }

        // `block()` answers whatever the callback answered, so anything that is
        // not a boolean means the callback never ran.
        return ! is_bool($counted) || $counted;
    }

    /**
     * The count itself, run with the reader's key held.
     *
     * The window is carried in the entry rather than left to the driver's own
     * expiry: writing the count back resets that lifetime, so a reader who kept
     * asking would push the window ahead of itself and never get through again.
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

        // A count that was not written did not happen, and the next request
        // would read the number from before it.
        if (! $this->cache->put($key, ['count' => $count, 'resets' => $resets], $resets - $now)) {
            return true;
        }

        return $count > self::MAX_REQUESTS_PER_MINUTE;
    }

    /**
     * The best a store that provides no lock can do.
     *
     * `add` opens the window and `increment` answers the number this request
     * is, which is the only number worth comparing: one read separately is
     * stale by the time it is compared. Whether those two are one step is the
     * driver's business, so this undercounts a simultaneous burst where
     * `increment` is not atomic. It is right on APCu, whose `apcu_inc` is.
     */
    private function countWithoutALock(string $key): bool
    {
        $started = $this->cache->add($key, 0, self::WINDOW_SECONDS);
        $count = $this->cache->increment($key);

        // `add` also fails when the window expired between these two calls, and
        // the key `increment` then created has no lifetime of its own. Left
        // alone it would count forever and shut the reader out for good.
        if (! $started && $count === 1) {
            $this->cache->put($key, 1, self::WINDOW_SECONDS);
        }

        // A driver that answers something other than a number is not counting,
        // so it is not to be trusted with this.
        return ! is_int($count) || $count > self::MAX_REQUESTS_PER_MINUTE;
    }

    /**
     * An account is counted as itself wherever it reads from. Only a guest is
     * counted by address, where a shared one makes neighbours share a budget.
     */
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
