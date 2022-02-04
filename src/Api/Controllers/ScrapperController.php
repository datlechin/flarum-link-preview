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

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return new JsonResponse([
                'error' => 'Invalid URL',
            ], 400);
        }

        $this->web->go($url);

        return new JsonResponse([
            'site_name' => $this->web->openGraph['og:site_name'] ?? null,
            'title' => $this->web->title ?? $this->web->openGraph['og:title'] ?? null,
            'description' => $this->web->description ?? $this->web->openGraph['og:description'] ?? null,
            'image' => $this->web->image ?? $this->web->openGraph['og:image'] ?? null,
        ]);
    }
}
