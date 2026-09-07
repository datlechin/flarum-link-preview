<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Tests\unit\Api;

use Datlechin\LinkPreview\Api\LinkPreviewThrottler;
use Flarum\Http\RequestUtil;
use Flarum\Testing\unit\TestCase;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Store;
use Laminas\Diactoros\ServerRequest;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * How the ceiling is counted, and what happens when the counter cannot be
 * trusted.
 *
 * The count is taken under the store's own lock. `increment()` is not atomic
 * on `Illuminate\Cache\FileStore`, the store Flarum binds `cache.store` to, so
 * counting with it left sixty concurrent requests through on a counter reading
 * three. A count that could not be taken must turn the request away, or a
 * cache outage becomes an open proxy.
 */
class CountsEveryPreviewRequestTest extends TestCase
{
    #[Test]
    #[DataProvider('theRoutesThisThrottlerOwns')]
    public function a_reader_gets_the_whole_budget_and_not_one_request_more(string $route): void
    {
        $throttler = new LinkPreviewThrottler(new Repository(new ArrayStore()));
        $request = $this->request($route);

        for ($i = 1; $i <= LinkPreviewThrottler::MAX_REQUESTS_PER_MINUTE; $i++) {
            $this->assertNull($throttler($request), "request $i is inside the limit");
        }

        $this->assertTrue($throttler($request), 'the request past the limit is turned away');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function theRoutesThisThrottlerOwns(): array
    {
        return [
            'one preview' => ['datlechin-link-preview'],
            'a page of them' => ['datlechin-link-preview.batch'],
        ];
    }

    #[Test]
    public function the_count_is_taken_with_the_stores_lock_rather_than_by_incrementing(): void
    {
        // A version that reaches for `increment` again has to fail here rather
        // than be found by a burst in production. The store is real so the
        // lock is a working one; only the reads and writes are seams.
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('getStore')->andReturn(new ArrayStore());
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);
        $cache->shouldNotReceive('increment');

        $this->assertNull((new LinkPreviewThrottler($cache))($this->request()));
    }

    #[Test]
    public function a_lock_nobody_could_take_turns_the_request_away(): void
    {
        // Waiting forever would hand an attacker a way to hold every worker
        // open, so the wait is bounded. A request that ran out of it went
        // uncounted, and uncounted is over the limit.
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->andThrow(new LockTimeoutException());

        $store = Mockery::mock(Store::class, LockProvider::class);
        $store->shouldReceive('lock')->andReturn($lock);

        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('getStore')->andReturn($store);

        $this->assertTrue((new LinkPreviewThrottler($cache))($this->request()));
    }

    #[Test]
    public function a_count_that_could_not_be_written_turns_the_request_away(): void
    {
        // A write the store refused leaves the next request reading the number
        // from before this one, so this request would have been free.
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('getStore')->andReturn(new ArrayStore());
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(false);

        $this->assertTrue((new LinkPreviewThrottler($cache))($this->request()));
    }

    #[Test]
    public function a_cache_that_throws_turns_the_request_away(): void
    {
        // Letting a dead Redis fall through would make an outage into an open
        // proxy; letting it escape would make it a 500 on a page that could
        // have rendered without the card.
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('getStore')->andThrow(new RuntimeException('Connection refused'));

        $this->assertTrue((new LinkPreviewThrottler($cache))($this->request()));
    }

    #[Test]
    public function a_store_with_no_lock_is_still_counted(): void
    {
        // APCu and the storage-backed store provide no lock. Neither is what
        // Flarum installs, and neither is a reason to drop the ceiling.
        $counted = 0;

        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('getStore')->andReturn(Mockery::mock(Store::class));
        $cache->shouldReceive('add')->andReturn(true);
        $cache->shouldReceive('put')->andReturn(true);
        // A full closure rather than an arrow one, which captures by value and
        // would answer 1 to every request.
        $cache->shouldReceive('increment')->andReturnUsing(function () use (&$counted): int {
            return ++$counted;
        });

        $throttler = new LinkPreviewThrottler($cache);
        $request = $this->request();

        for ($i = 1; $i <= LinkPreviewThrottler::MAX_REQUESTS_PER_MINUTE; $i++) {
            $this->assertNull($throttler($request), "request $i is inside the limit");
        }

        $this->assertTrue($throttler($request));
        $this->assertSame(LinkPreviewThrottler::MAX_REQUESTS_PER_MINUTE + 1, $counted);
    }

    #[Test]
    #[DataProvider('answersThatAreNotACount')]
    public function a_store_with_no_lock_that_cannot_count_turns_the_request_away(mixed $answer): void
    {
        // `increment` answers false on a store that cannot do it, and on a key
        // that expired between the add and the increment. Uncounted is not free.
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('getStore')->andReturn(Mockery::mock(Store::class));
        $cache->shouldReceive('add')->andReturn(true);
        $cache->shouldReceive('put')->andReturn(true);
        $cache->shouldReceive('increment')->andReturn($answer);

        $this->assertTrue((new LinkPreviewThrottler($cache))($this->request()));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function answersThatAreNotACount(): array
    {
        return [
            'a refusal' => [false],
            'nothing at all' => [null],
            'a number written as a string' => ['4'],
        ];
    }

    #[Test]
    public function a_broken_cache_does_not_throttle_the_rest_of_the_forum(): void
    {
        // Failing closed is only defensible inside this extension's own routes.
        // The mock has no expectations, so a throttler that touched the cache
        // before reading the route name would fail here.
        $cache = Mockery::mock(Repository::class);

        $throttler = new LinkPreviewThrottler($cache);

        $this->assertNull($throttler($this->request('discussions.index')));
        $this->assertNull($throttler($this->request('users.create')));
        $this->assertNull($throttler($this->request(null)));
    }

    #[Test]
    public function a_member_and_a_guest_are_counted_separately(): void
    {
        $throttler = new LinkPreviewThrottler(new Repository(new ArrayStore()));

        $member = new User();
        $member->id = 7;

        for ($i = 0; $i <= LinkPreviewThrottler::MAX_REQUESTS_PER_MINUTE; $i++) {
            $throttler($this->request('datlechin-link-preview'));
        }

        $this->assertTrue($throttler($this->request('datlechin-link-preview')), 'the guest address is spent');
        $this->assertNull($throttler($this->request('datlechin-link-preview', $member)));
    }

    private function request(?string $route = 'datlechin-link-preview', ?User $actor = null): ServerRequestInterface
    {
        $request = (new ServerRequest([], [], '/api/datlechin-link-preview', 'POST'))
            ->withAttribute('routeName', $route)
            ->withAttribute('ipAddress', '203.0.113.9');

        return RequestUtil::withActor($request, $actor ?? new Guest());
    }
}
