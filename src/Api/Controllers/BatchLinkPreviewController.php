<?php

namespace Datlechin\LinkPreview\Api\Controllers;

use Datlechin\LinkPreview\Services\LinkPreviewService;
use GuzzleHttp\Promise\Utils;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

class BatchLinkPreviewController implements RequestHandlerInterface
{
    public function __construct(protected LinkPreviewService $service) {}

    public function handle(Request $request): Response
    {
        $urls = $request->getParsedBody()['urls'] ?? [];
        $urlsToFetch = [];
        $result = [];

        foreach ($urls as $url) {
            if (!$this->service->isValidUrl($url)) {
                $result[$url] = $this->service->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached');
                continue;
            }

            $normalizedUrl = $this->service->normalizeUrl($url);

            if (!$this->service->isUrlAllowed($normalizedUrl)) {
                $result[$url] = $this->service->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached');
                continue;
            }

            $cachedData = $this->service->getCachedData($normalizedUrl);
            if ($cachedData) {
                $result[$url] = $cachedData;
                continue;
            }

            $urlsToFetch[$url] = $normalizedUrl;
        }

        if (empty($urlsToFetch)) {
            return new JsonResponse($result);
        }

        $promises = $this->preparePromises($urlsToFetch);

        $responses = Utils::settle($promises)->wait();

        foreach ($responses as $originalUrl => $response) {
            try {
                if (!isset($responses[$originalUrl])) {
                    $result[$originalUrl] = [
                        'error' => 'No response received',
                    ];
                    continue;
                }

                if ($response['state'] === 'fulfilled') {
                    $html = $response['value']->getBody()->getContents();
                    $data = $this->service->parseHtml($html, $originalUrl);

                    $this->service->cacheData($urlsToFetch[$originalUrl], $data);
                    $result[$originalUrl] = $data;
                } else {
                    $result[$originalUrl] = [
                        'error' => $response['reason']->getMessage(),
                    ];
                }
            } catch (Throwable $e) {
                $result[$originalUrl] = [
                    'error' => $e->getMessage(),
                ];
            }
        }

        return new JsonResponse($result);
    }

    protected function preparePromises(array $urlsToFetch): array
    {
        $promises = [];

        foreach ($urlsToFetch as $originalUrl) {
            $promises[$originalUrl] = $this->service->getClient()->getAsync($originalUrl);
        }

        return $promises;
    }
}
