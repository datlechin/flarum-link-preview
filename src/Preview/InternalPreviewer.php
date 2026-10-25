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

use Datlechin\LinkPreview\Internal\Card;
use Datlechin\LinkPreview\Internal\MatchedRoute;
use Datlechin\LinkPreview\Internal\RouteCard;
use Datlechin\LinkPreview\Internal\RouteCards;
use FastRoute\Dispatcher;
use Flarum\Http\RouteCollection;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Filesystem\Factory;
use Psr\Log\LoggerInterface;
use Throwable;

final class InternalPreviewer
{
    private ?array $origin = null;

    private string|false|null $favicon = false;

    private ?Dispatcher $dispatcher = null;

    public function __construct(
        private UrlGenerator $url,
        private SettingsRepositoryInterface $settings,
        private RouteCollection $routes,
        private RouteCards $cards,
        private Factory $filesystem,
        private LoggerInterface $log,
    ) {
    }

    public function isInternal(string $url): bool
    {
        return $this->forumPath($url) !== null;
    }

    public function preview(string $url, User $actor): Preview
    {
        $route = $this->match($url);
        $card = $route === null ? null : $this->cards->for($route->name);

        if ($route === null || $card === null) {
            return Preview::error($url, PreviewError::NotPreviewable);
        }

        $answer = $this->ask($card, $route, $actor);

        if ($answer === null) {
            return Preview::error($url, PreviewError::NoMetadata);
        }

        return Preview::internal(
            url: $url,
            type: $route->name,
            card: $answer,
            siteName: self::text($this->settings->get('forum_title')),
            favicon: $this->faviconUrl(),
        );
    }

    private function ask(RouteCard $card, MatchedRoute $route, User $actor): ?Card
    {
        try {
            return $card->card($route, $actor);
        } catch (Throwable $exception) {
            $this->log->error($exception);

            return null;
        }
    }

    private function match(string $url): ?MatchedRoute
    {
        $path = $this->forumPath($url);

        if ($path === null) {
            return null;
        }

        $match = $this->dispatcher()->dispatch('GET', $path);

        if ($match[0] !== Dispatcher::FOUND) {
            return null;
        }

        return new MatchedRoute($match[1]['name'], $match[2], $url);
    }

    private function dispatcher(): Dispatcher
    {
        return $this->dispatcher ??= new Dispatcher\GroupCountBased($this->routes->getRouteData());
    }

    private function forumPath(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        $origin = $this->origin();
        $port = $parts['port'] ?? null;

        if (self::host($parts['host']) !== $origin['host'] || ($port !== null && $port !== $origin['port'])) {
            return null;
        }

        $path = $parts['path'] ?? '';
        $base = $origin['path'];

        if ($base !== '') {
            if ($path !== $base && ! str_starts_with($path, $base.'/')) {
                return null;
            }

            $path = substr($path, strlen($base));
        }

        return '/'.trim($path, '/');
    }

    private function faviconUrl(): ?string
    {
        if ($this->favicon === false) {
            $path = $this->settings->get('favicon_path');

            if (is_string($path) && $path !== '') {
                $this->favicon = $this->filesystem->disk('flarum-assets')->url($path);
            } else {
                $this->favicon = null;
            }
        }

        return $this->favicon;
    }

    private function origin(): array
    {
        if ($this->origin !== null) {
            return $this->origin;
        }

        $base = parse_url($this->url->to('forum')->base()) ?: [];
        $scheme = strtolower($base['scheme'] ?? 'https');

        return $this->origin = [
            'host' => self::host($base['host'] ?? ''),
            'port' => $base['port'] ?? ($scheme === 'https' ? 443 : 80),
            'path' => rtrim($base['path'] ?? '', '/'),
        ];
    }

    private static function host(string $host): string
    {
        return trim(strtolower(rtrim(trim($host), '.')), '[]');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
