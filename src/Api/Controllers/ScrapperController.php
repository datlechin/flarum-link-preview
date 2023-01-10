<?php

namespace Datlechin\LinkPreview\Api\Controllers;

use Flarum\Settings\SettingsRepositoryInterface;
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

    public function __construct(PHPScraper $web, TranslatorInterface $translator, SettingsRepositoryInterface $settings)
    {
        $this->web = $web;
        $this->translator = $translator;
        $this->blacklist = array_map(
            'trim',
            explode(',', $settings->get('datlechin-link-preview.blacklist') ?? '')
        );
    }

    /*
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $url = $request->getQueryParams()['url'] ?? '';
        $domain = parse_url($url, PHP_URL_HOST);

        if (! filter_var($url, FILTER_VALIDATE_URL) || in_array($domain, $this->blacklist, true) || gethostbyname($domain) === $domain) {
            return new JsonResponse([
                'error' => $this->translator->trans('datlechin-link-preview.forum.site_cannot_be_reached')
            ]);
        }

        try {
            $this->web->go($url);

            return new JsonResponse([
                'site_name' => $this->web->openGraph['og:site_name'] ?? $this->web->twitterCard['twitter:site'] ?? null,
                'title' => $this->web->title ?? $this->web->openGraph['og:title'] ?? $this->web->twitterCard['twitter:title'] ?? null,
                'description' => $this->web->description ?? $this->web->openGraph['og:description'] ?? $this->web->twitterCard['twitter:description'] ?? null,
                'image' => $this->web->image ?? $this->web->openGraph['og:image'] ?? $this->web->twitterCard['twitter:image'] ?? null,
            ]);
        } catch (Throwable $th) {
            return new JsonResponse([
                'error' => $th->getMessage(),
            ]);
        }
    }
}
