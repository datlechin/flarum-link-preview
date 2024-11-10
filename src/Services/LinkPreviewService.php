<?php

namespace Datlechin\LinkPreview\Services;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Store;
use Spekulatius\PHPScraper\PHPScraper;
use Symfony\Contracts\Translation\TranslatorInterface;

class LinkPreviewService
{
    protected array $blacklist = [];

    protected array $whitelist = [];

    protected int $cacheTime;

    protected Client $httpClient;

    public function __construct(
        protected PHPScraper $web,
        protected TranslatorInterface $translator,
        SettingsRepositoryInterface $settings,
        protected Store $cache,
    ) {
        $this->setupCacheTime($settings);
        $this->setupLists($settings);
        $this->setupHttpClient();
    }

    protected function setupCacheTime(SettingsRepositoryInterface $settings): void
    {
        $cacheTime = $settings->get('datlechin-link-preview.cache_time');
        $this->cacheTime = is_numeric($cacheTime) ? intval($cacheTime) : 60;
    }

    protected function setupLists(SettingsRepositoryInterface $settings): void
    {
        $this->blacklist = $this->getMultiDimensionalSetting($settings, 'datlechin-link-preview.blacklist');
        $this->whitelist = $this->getMultiDimensionalSetting($settings, 'datlechin-link-preview.whitelist');
    }

    protected function setupHttpClient(): void
    {
        $this->httpClient = new Client([
            'timeout' => 5,
            'connect_timeout' => 5,
            'verify' => false,
        ]);
    }

    public function getClient(): Client
    {
        return $this->httpClient;
    }

    public function parseHtml(string $html, string $url): array
    {
        $web = clone $this->web;
        $web->setContent($url, $html);

        return [
            'site_name' => $web->openGraph['og:site_name'] ?? $web->twitterCard['twitter:site'] ?? null,
            'title' => $web->title ?? $web->openGraph['og:title'] ?? $web->twitterCard['twitter:title'] ?? null,
            'description' => $web->description ?? $web->openGraph['og:description'] ?? $web->twitterCard['twitter:description'] ?? null,
            'image' => $web->image ?? $web->openGraph['og:image'] ?? $web->twitterCard['twitter:image'] ?? null,
            'accessed' => time(),
        ];
    }

    public function isValidUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $domain = parse_url($url, PHP_URL_HOST);
        return gethostbyname($domain) !== $domain;
    }

    public function normalizeUrl(string $url): string
    {
        return preg_replace('/^https?:\/\/(.+?)\/?$/i', '$1', $url);
    }

    public function isUrlAllowed(string $normalizedUrl): bool
    {
        return (!$this->whitelist || $this->inList($normalizedUrl, $this->whitelist))
            && (!$this->blacklist || !$this->inList($normalizedUrl, $this->blacklist));
    }

    public function getCacheKey(string $normalizedUrl): string
    {
        return 'datlechin-link-preview:' . md5($normalizedUrl);
    }

    public function getCachedData(string $normalizedUrl)
    {
        if (!$this->cacheTime) {
            return null;
        }
        return $this->cache->get($this->getCacheKey($normalizedUrl));
    }

    public function cacheData(string $normalizedUrl, array $data): void
    {
        if (!$this->cacheTime) {
            return;
        }
        $this->cache->put($this->getCacheKey($normalizedUrl), $data, $this->cacheTime * 60);
    }

    public function getErrorResponse(string $message): array
    {
        return [
            'error' => $this->translator->trans($message),
        ];
    }

    protected function getMultiDimensionalSetting(SettingsRepositoryInterface $settings, string $setting): array
    {
        $items = preg_split('/[,\\n]/', $settings->get($setting) ?? '') ?: [];
        return array_filter(array_map('trim', $items));
    }

    protected function inList(string $needle, array $haystack): bool
    {
        if (!$haystack) {
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
