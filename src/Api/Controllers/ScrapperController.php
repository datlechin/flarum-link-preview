<?php

namespace Datlechin\LinkPreview\Api\Controllers;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use spekulatius\phpscraper;

class ScrapperController implements RequestHandlerInterface
{
    protected $web;

    public function __construct(phpscraper $web)
    {
        $this->web = $web;
    }

    /*
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $url = isset($request->getQueryParams()['url']) ? $request->getQueryParams()['url'] : '';
        $domain = parse_url($url, PHP_URL_HOST);

        if (filter_var($url, FILTER_VALIDATE_URL) === false || gethostbyname($domain) === $domain) {
            return new JsonResponse([
                'error' => 'Invalid URL',
            ]);
        }

        $this->web->go($url);

        return new JsonResponse([
            'site_name' => $this->web->openGraph['og:site_name'] ?? $this->web->twitterCard['twitter:site'] ?? null,
            'title' => $this->web->title ?? $this->web->openGraph['og:title']  ?? $this->web->twitterCard['twitter:title'] ?? null,
            'description' => $this->web->description ?? $this->web->openGraph['og:description'] ?? $this->web->twitterCard['twitter:description'] ?? null,
            'image' => $this->web->image ?? $this->web->openGraph['og:image'] ?? $this->web->twitterCard['twitter:image'] ?? null,
        ]);
    }
}
