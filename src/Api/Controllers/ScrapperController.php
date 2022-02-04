<?php

namespace Datlechin\LinkPreview\Api\Controllers;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

class ScrapperController implements RequestHandlerInterface
{
    protected $web;

    public function __construct(\spekulatius\phpscraper $web)
    {
        $this->web = $web;
    }

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
            'title' => $this->web->title,
            'description' => $this->web->description,
            'image' => $this->web->openGraph['og:image'] ?? null,
        ]);
    }
}
