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
    /** @var PHPScraper $web */
    protected $web;

    /** @var TranslatorInterface $translator */
    protected $translator;

    /** @var array */
    protected $blacklist = [];

    /** @var array */
    protected $whitelist = [];

    /** @var Store */
    protected $cache;

    /** @var int */
    private $cacheTime;

    public function __construct(
        PHPScraper $web,
        TranslatorInterface $translator,
        SettingsRepositoryInterface $settings,
        Store $cache
    ) {
        $this->web = $web;
        $this->translator = $translator;
        $this->cache = $cache;
        $this->blacklist = array_map(
            'trim',
            explode(',', $settings->get('datlechin-link-preview.blacklist') ?? '')
        );
        $this->whitelist = array_map(
            'trim',
            explode(',', $settings->get('datlechin-link-preview.whitelist') ?? '')
        );
        $cacheTime = $settings->get('datlechin-link-preview.cache_time');
        if (!is_numeric($cacheTime)) {
            $cacheTime = 60;
        }
        $this->cacheTime = intval($cacheTime);
    }

    /*
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $url = $request->getQueryParams()['url'] ?? '';
        $domain = parse_url($url, PHP_URL_HOST);

        if (
            ! filter_var($url, FILTER_VALIDATE_URL)
            || ! in_array($domain, $this->whitelist, true)
            || in_array($domain, $this->blacklist, true)
        ) {
            return new JsonResponse([
                'error' => $this->translator->trans('datlechin-link-preview.forum.site_cannot_be_reached')
            ]);
        }

        if ($this->cacheTime) {
            $cacheKey = 'datlechin-link-preview:' . md5(preg_replace('/^https?:\/\/(.+)\/?$/', '$1', $url));
            $data = $this->cache->get($cacheKey);
            if (null !== $data) {
                return new JsonResponse($data);
            }
        }

        if (gethostbyname($domain) === $domain) {
            return new JsonResponse([
                'error' => $this->translator->trans('datlechin-link-preview.forum.site_cannot_be_reached')
            ]);
        }

        try {
            $this->web->go($url);

            $data = [
                'site_name' => $this->web->openGraph['og:site_name'] ?? $this->web->twitterCard['twitter:site'] ?? null,
                'title' => $this->web->title ?? $this->web->openGraph['og:title'] ?? $this->web->twitterCard['twitter:title'] ?? null,
                'description' => $this->web->description ?? $this->web->openGraph['og:description'] ?? $this->web->twitterCard['twitter:description'] ?? null,
                'image' => $this->web->image ?? $this->web->openGraph['og:image'] ?? $this->web->twitterCard['twitter:image'] ?? null,
                'accessed' => time(),
            ];

            if ($this->cacheTime && isset($cacheKey)) {
                $this->cache->put($cacheKey, $data, $this->cacheTime * 60);
            }

            return new JsonResponse($data);
        } catch (Throwable $th) {
            return new JsonResponse([
                'error' => $th->getMessage(),
            ]);
        }
    }
}
