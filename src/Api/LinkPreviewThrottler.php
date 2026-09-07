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
 * The endpoint is open to guests, and every request it accepts can become an
 * outbound connection. The cache absorbs the ordinary case, so anyone reaching
 * this limit is asking for URLs nobody has asked for before, which is what an
 * attempt to use the forum as a scanner looks like.
 *
 * Set well above what reading costs: one page view is one batch request, and a
 * whole household or office can share an address.
 */
final class LinkPreviewThrottler
{
    public const MAX_REQUESTS_PER_MINUTE = 30;

    /**
     * @var list<string>
     */
    private const ROUTES = ['datlechin-link-preview', 'datlechin-link-preview.batch'];

    private const WINDOW_SECONDS = 60;

    private const CACHE_PREFIX = 'datlechin-link-preview:throttle:';

    private const LOCK_PREFIX = 'datlechin-link-preview:throttle-lock:';

    /**
     * How long the holder of the counting lock may keep it.
     *
     * Two cache operations need a fraction of this. It is a bound on how long
     * a worker killed mid-count can shut its own address out, not a budget.
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
            // Null rather than false: this throttler has no opinion about any
            // other route, and false would exempt them from everybody else's
            // throttles.
            return null;
        }

        return $this->exceeded($this->identify($request)) ? true : null;
    }

    /**
     * Count this request and say whether it is one too many.
     *
     * Reading the count and writing it back have to happen as one step, or the
     * limit is one per arrival rather than one per minute: twenty requests
     * landing together all read the same number and all write it back plus one,
     * spending one request out of the budget instead of twenty, which is
     * exactly the shape of traffic the limit exists for.
     *
     * `increment` is not that one step on the store Flarum installs.
     * `Flarum\Foundation\InstalledSite::registerCache()` binds `cache.store` to
     * `Illuminate\Cache\FileStore` unconditionally, and that class's
     * `increment()` is a `getPayload()` followed by a `put()` with nothing held
     * in between. Sixty processes asking at once against that store left the
     * counter reading between three and six and let all sixty through, so the
     * ceiling was not a ceiling. What FileStore does have is `add()`, which
     * takes an exclusive `flock` on the entry, and `lock()`, which is built on
     * `add()`. So the read and the write are done with that lock held, and the
     * same sixty now cost exactly the thirty they should.
     *
     * A store that provides no lock is counted by {@see self::countWithoutALock()},
     * where the count is exact only if that driver's own `increment` is atomic.
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
            // already asked for, and a lock nobody could take inside the wait
            // means this request went uncounted. An endpoint that makes
            // outbound connections and has lost its only ceiling is worse than
            // a card that does not load, so an unknown count is one too many.
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
     * expiry. Writing the count back resets whatever lifetime the driver was
     * keeping, so a reader who kept asking would otherwise push the window
     * ahead of itself and never be let through again.
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
     * is, which is the only number worth comparing: a value read separately is
     * already stale by the time the comparison happens. Whether those two are
     * one step is the driver's business and not something this class can make
     * true, so on a driver whose `increment` is not atomic this undercounts a
     * simultaneous burst. It is right on APCu, whose `apcu_inc` is.
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

        // Any driver that answers something other than a number is in the same
        // position: it is not counting, so it is not to be trusted with this.
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
