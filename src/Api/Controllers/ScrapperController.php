<?php

namespace Datlechin\LinkPreview\Api\Controllers;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Store;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Spekulatius\PHPScraper\PHPScraper;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

class ScrapperController implements RequestHandlerInterface
{
    protected PHPScraper $web;

    protected TranslatorInterface $translator;

    protected array $blacklist = [];

    protected array $whitelist = [];

    protected Store $cache;

    protected int $cacheTime;

    protected SettingsRepositoryInterface $settings;

    public function __construct(
        PHPScraper $web,
        TranslatorInterface $translator,
        SettingsRepositoryInterface $settings,
        Store $cache
    ) {
        $this->web = $web;
        $this->translator = $translator;
        $this->cache = $cache;
        $cacheTime = $settings->get('datlechin-link-preview.cache_time');

        if (! is_numeric($cacheTime)) {
            $cacheTime = 60;
        }

        $this->cacheTime = intval($cacheTime);
        $this->settings = $settings;
        $this->blacklist = $this->getMultiDimensionalSetting('datlechin-link-preview.blacklist');
        $this->whitelist = $this->getMultiDimensionalSetting('datlechin-link-preview.whitelist');
    }

    public function handle(Request $request): Response
    {
        $url = $request->getQueryParams()['url'] ?? '';

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return new JsonResponse([
                'error' => $this->translator->trans('datlechin-link-preview.forum.site_cannot_be_reached')
            ]);
        }

        $normalizedUrl = preg_replace('/^https?:\/\/(.+?)\/?$/i', '$1', $url);

        if (
            ($this->whitelist && ! $this->inList($normalizedUrl, $this->whitelist))
            || ($this->blacklist && $this->inList($normalizedUrl, $this->blacklist))
        ) {
            return new JsonResponse([
                'error' => $this->translator->trans('datlechin-link-preview.forum.site_cannot_be_reached')
            ]);
        }

        if ($this->cacheTime) {
            $cacheKey = 'datlechin-link-preview:' . md5($normalizedUrl);
            $data = $this->cache->get($cacheKey);

            if ($data) {
                return new JsonResponse($data);
            }
        }

        $domain = parse_url($url, PHP_URL_HOST);
        if (gethostbyname($domain) === $domain) {
            return new JsonResponse([
                'error' => $this->translator->trans('datlechin-link-preview.forum.site_cannot_be_reached')
            ]);
        }

        try {
            $web = $this->web;
            $web->go($url);

            $data = [
                'site_name' => $web->openGraph['og:site_name'] ?? $web->twitterCard['twitter:site'] ?? null,
                'title' => $web->title ?? $web->openGraph['og:title'] ?? $web->twitterCard['twitter:title'] ?? null,
                'description' => $web->description ?? $web->openGraph['og:description'] ?? $web->twitterCard['twitter:description'] ?? null,
                'image' => $web->image ?? $web->openGraph['og:image'] ?? $web->twitterCard['twitter:image'] ?? null,
                'accessed' => time(),
            ];

            if ($this->cacheTime && isset($cacheKey)) {
                $this->cache->put($cacheKey, $data, $this->cacheTime * 60);
            }

            return new JsonResponse($data);
        } catch (Throwable $throwale) {
            return new JsonResponse([
                'error' => $throwale->getMessage(),
            ]);
        }
    }

    protected function getMultiDimensionalSetting(string $setting): array
    {
        $items = preg_split('/[,\\n]/', $this->settings->get($setting) ?? '') ?: [];

        return array_filter(array_map('trim', $items));
    }

    protected function inList(string $needle, array $haystack): bool
    {
        if (! $haystack) {
            return false;
        }

        if (in_array($needle, $haystack, true)) {
            return true;
        }

        foreach ($haystack as $item) {
            $quoted = strtr(preg_quote($item, '/'), [
                '\\*' => '.*',
                '\\?' => '.',
            ]);

            if (preg_match("/$quoted/i", $needle)) {
                return true;
            }
        }

        return false;
    }
}
