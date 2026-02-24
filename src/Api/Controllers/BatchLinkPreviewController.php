<?php

namespace Datlechin\LinkPreview\Api\Controllers;

use Datlechin\LinkPreview\Services\LinkPreviewService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

class BatchLinkPreviewController implements RequestHandlerInterface
{
    public function __construct(protected LinkPreviewService $service) {}

    public function handle(Request $request): Response
    {
        $urls = $request->getParsedBody()['urls'] ?? [];

        if (!is_array($urls) || empty($urls)) {
            return new JsonResponse([]);
        }

        return new JsonResponse($this->service->getPreviewsBatch($urls));
    }
}
