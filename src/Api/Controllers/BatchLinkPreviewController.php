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
        try {
            $urls = $request->getParsedBody()['urls'] ?? [];
            $result = $this->processUrls($urls);

            return new JsonResponse($result);
        } catch (Throwable $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function processUrls(array $urls): array
    {
        $result = [];
        $urlsToFetch = [];

        foreach ($urls as $url) {
            $processResult = $this->preProcessUrl($url);
            if (isset($processResult['data'])) {
                $result[$url] = $processResult['data'];
            } elseif (isset($processResult['fetch'])) {
                $urlsToFetch[$url] = $processResult['fetch'];
            } else {
                $result[$url] = $processResult['error'];
            }
        }

        if (empty($urlsToFetch)) {
            return $result;
        }

        $fetchResults = $this->fetchUrls($urlsToFetch);

        foreach ($urlsToFetch as $originalUrl => $normalizedUrl) {
            $result[$originalUrl] = $fetchResults[$originalUrl] ?? [
                'error' => 'Failed to fetch preview',
            ];
        }

        return $result;
    }

    protected function preProcessUrl(string $url): array
    {
        if (!$this->service->isValidUrl($url)) {
            return [
                'error' => $this->service->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached'),
            ];
        }

        $normalizedUrl = $this->service->normalizeUrl($url);

        if (!$this->service->isUrlAllowed($normalizedUrl)) {
            return [
                'error' => $this->service->getErrorResponse('datlechin-link-preview.forum.site_cannot_be_reached'),
            ];
        }

        $cachedData = $this->service->getCachedData($normalizedUrl);
        if ($cachedData) {
            return ['data' => $cachedData];
        }

        return ['fetch' => $normalizedUrl];
    }

    protected function fetchUrls(array $urlsToFetch): array
    {
        $promises = [];
        $results = [];
        $normalizedUrls = [];

        foreach ($urlsToFetch as $originalUrl => $normalizedUrl) {
            try {
                $promises[$originalUrl] = $this->service->getClient()->getAsync($originalUrl);
                $normalizedUrls[$originalUrl] = $normalizedUrl;
            } catch (Throwable $e) {
                $results[$originalUrl] = [
                    'error' => 'Failed to create request: ' . $e->getMessage(),
                ];
            }
        }

        if (empty($promises)) {
            return $results;
        }

        $responses = Utils::settle($promises)->wait();

        foreach ($promises as $originalUrl => $promise) {
            try {
                $response = $responses[$originalUrl] ?? null;

                if (!$response || $response['state'] !== 'fulfilled') {
                    $results[$originalUrl] = [
                        'error' => $response['reason'] instanceof \Exception ?
                            $response['reason']->getMessage() :
                            'Failed to fetch preview',
                    ];
                    continue;
                }

                $html = $response['value']->getBody()->getContents();
                $data = $this->service->parseHtml($html, $originalUrl);

                if (isset($normalizedUrls[$originalUrl])) {
                    $this->service->cacheData($normalizedUrls[$originalUrl], $data);
                }

                $results[$originalUrl] = $data;
            } catch (Throwable $e) {
                $results[$originalUrl] = [
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}
