<?php

namespace Datlechin\LinkPreview\Services;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use Illuminate\Contracts\Cache\Store;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

class LinkPreviewService
{
    const MAX_BATCH_SIZE = 20;

    protected array $blacklist = [];

    protected array $whitelist = [];

    protected int $cacheTime;

    protected Client $httpClient;

    protected bool $externalApiFallback;

    protected string $externalApiUrl;

    public function __construct(
        protected HtmlMetaParser $parser,
        protected TranslatorInterface $translator,
        SettingsRepositoryInterface $settings,
        protected Store $cache,
    ) {
        $this->setupCacheTime($settings);
        $this->setupLists($settings);
        $this->setupHttpClient();
        $this->externalApiFallback = (bool) $settings->get('datlechin-link-preview.external_api_fallback');
        $this->externalApiUrl = $settings->get('datlechin-link-preview.external_api_url') ?: 'https://open.iframe.ly/api/oembed?origin=flarum';
    }

    public function getPreview(string $url): array
    {
        if (!$this->isValidUrl($url)) {
            return $this->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached');
        }

        $normalizedUrl = $this->normalizeUrl($url);

        if (!$this->isUrlAllowed($normalizedUrl)) {
            return $this->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached');
        }

        $cachedData = $this->getCachedData($normalizedUrl);
        if ($cachedData && ($this->hasRichData($cachedData) || !$this->externalApiFallback)) {
            return $cachedData;
        }

        $data = $this->fetchAndParse($url);

        if (!$data || !$this->hasRichData($data)) {
            $apiData = $this->fetchFromExternalApi($url);
            if ($apiData) {
                $data = $apiData;
            }
        }

        if (!$data) {
            return $this->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached');
        }

        $this->cacheData($normalizedUrl, $data);

        return $data;
    }

    public function getPreviewsBatch(array $urls): array
    {
        $urls = array_slice($urls, 0, self::MAX_BATCH_SIZE);

        $result = [];
        $urlsToFetch = [];

        foreach ($urls as $url) {
            if (!$this->isValidUrl($url)) {
                $result[$url] = $this->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached');
                continue;
            }

            $normalizedUrl = $this->normalizeUrl($url);

            if (!$this->isUrlAllowed($normalizedUrl)) {
                $result[$url] = $this->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached');
                continue;
            }

            $cachedData = $this->getCachedData($normalizedUrl);
            if ($cachedData && ($this->hasRichData($cachedData) || !$this->externalApiFallback)) {
                $result[$url] = $cachedData;
                continue;
            }

            $urlsToFetch[$url] = $normalizedUrl;
        }

        if (empty($urlsToFetch)) {
            return $result;
        }

        $promises = [];

        foreach ($urlsToFetch as $originalUrl => $normalizedUrl) {
            try {
                $promises[$originalUrl] = $this->httpClient->getAsync($originalUrl);
            } catch (Throwable $e) {
                $result[$originalUrl] = $this->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached');
                unset($urlsToFetch[$originalUrl]);
            }
        }

        if (!empty($promises)) {
            $responses = Utils::settle($promises)->wait();

            foreach ($promises as $originalUrl => $promise) {
                try {
                    $data = null;
                    $response = $responses[$originalUrl] ?? null;

                    if ($response && $response['state'] === 'fulfilled') {
                        $html = $response['value']->getBody()->getContents();

                        if (!empty($html)) {
                            $data = $this->parseHtml($html, $originalUrl);

                            if (!$this->hasUsefulData($data)) {
                                $data = null;
                            }
                        }
                    }

                    if (!$data || !$this->hasRichData($data)) {
                        $apiData = $this->fetchFromExternalApi($originalUrl);
                        if ($apiData) {
                            $data = $apiData;
                        }
                    }

                    if (!$data) {
                        $result[$originalUrl] = $this->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached');
                        continue;
                    }

                    $this->cacheData($urlsToFetch[$originalUrl], $data);
                    $result[$originalUrl] = $data;
                } catch (Throwable $e) {
                    $result[$originalUrl] = $this->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached');
                }
            }
        }

        return $result;
    }

    private function fetchAndParse(string $url): ?array
    {
        try {
            $response = $this->httpClient->get($url);
            $html = $response->getBody()->getContents();

            if (empty($html)) {
                return null;
            }

            $data = $this->parseHtml($html, $url);

            return $this->hasUsefulData($data) ? $data : null;
        } catch (Throwable $e) {
            return null;
        }
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
            'timeout' => 15,
            'connect_timeout' => 10,
            'verify' => false,
            'http_errors' => false,
            'allow_redirects' => [
                'max' => 10,
                'strict' => false,
                'referer' => true,
                'track_redirects' => true,
            ],
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Accept-Encoding' => 'gzip, deflate, br',
                'DNT' => '1',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
                'Cache-Control' => 'max-age=0',
            ],
            'cookies' => true,
        ]);
    }

    public function parseHtml(string $html, string $url): array
    {
        $parsed = $this->parser->parse($html);

        return [
            'site_name' => $parsed['og:site_name'] ?? $parsed['twitter:site'] ?? null,
            'title' => $parsed['og:title'] ?? $parsed['twitter:title'] ?? $parsed['title'] ?? null,
            'description' => $parsed['og:description'] ?? $parsed['twitter:description'] ?? $parsed['meta:description'] ?? null,
            'image' => $parsed['og:image'] ?? $parsed['twitter:image'] ?? null,
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

    public function hasUsefulData(array $data): bool
    {
        return !empty($data['title']) || !empty($data['description']) || !empty($data['image']);
    }

    public function hasRichData(array $data): bool
    {
        return !empty($data['description']) || !empty($data['image']);
    }

    public function fetchFromExternalApi(string $url): ?array
    {
        if (!$this->externalApiFallback) {
            return null;
        }

        try {
            $separator = str_contains($this->externalApiUrl, '?') ? '&' : '?';
            $apiUrl = $this->externalApiUrl . $separator . 'url=' . urlencode($url);

            $response = $this->httpClient->get($apiUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'timeout' => 10,
            ]);

            $body = $response->getBody()->getContents();

            if (empty($body)) {
                return null;
            }

            $json = json_decode($body, true);

            if (!is_array($json) || (!isset($json['title']) && !isset($json['description']))) {
                return null;
            }

            $data = [
                'site_name' => $json['provider_name'] ?? null,
                'title' => $json['title'] ?? null,
                'description' => $json['description'] ?? null,
                'image' => $json['thumbnail_url'] ?? null,
                'accessed' => time(),
            ];

            return $this->hasUsefulData($data) ? $data : null;
        } catch (Throwable $e) {
            return null;
        }
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
