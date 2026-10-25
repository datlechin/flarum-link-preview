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
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('getStore')->andReturn(new ArrayStore());
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(false);

        $this->assertTrue((new LinkPreviewThrottler($cache))($this->request()));
    }

    #[Test]
    public function a_cache_that_throws_turns_the_request_away(): void
    {
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('getStore')->andThrow(new RuntimeException('Connection refused'));

        $this->assertTrue((new LinkPreviewThrottler($cache))($this->request()));
    }

    #[Test]
    public function a_store_with_no_lock_is_still_counted(): void
    {
        $counted = 0;

        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('getStore')->andReturn(Mockery::mock(Store::class));
        $cache->shouldReceive('add')->andReturn(true);
        $cache->shouldReceive('put')->andReturn(true);
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
        $cache = Mockery::mock(Repository::class);
        $cache->shouldReceive('getStore')->andReturn(Mockery::mock(Store::class));
        $cache->shouldReceive('add')->andReturn(true);
        $cache->shouldReceive('put')->andReturn(true);
        $cache->shouldReceive('increment')->andReturn($answer);

        $this->assertTrue((new LinkPreviewThrottler($cache))($this->request()));
    }

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
