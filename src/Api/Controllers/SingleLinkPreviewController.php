<?php

namespace Datlechin\LinkPreview\Api\Controllers;

use Datlechin\LinkPreview\Services\LinkPreviewService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

class SingleLinkPreviewController implements RequestHandlerInterface
{
    public function __construct(protected LinkPreviewService $service) {}

    public function handle(Request $request): Response
    {
        $url = $request->getQueryParams()['url'] ?? '';

        if (!$this->service->isValidUrl($url)) {
            return new JsonResponse(
                $this->service->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached'),
            );
        }

        $normalizedUrl = $this->service->normalizeUrl($url);

        if (!$this->service->isUrlAllowed($normalizedUrl)) {
            return new JsonResponse(
                $this->service->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached'),
            );
        }

        $cachedData = $this->service->getCachedData($normalizedUrl);
        if ($cachedData) {
            return new JsonResponse($cachedData);
        }

        try {
            $response = $this->service->getClient()->get($url);
            $html = $response->getBody()->getContents();
            $data = $this->service->parseHtml($html, $url);

            $this->service->cacheData($normalizedUrl, $data);

            return new JsonResponse($data);
        } catch (Throwable $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
            ]);
        }
    }
}
