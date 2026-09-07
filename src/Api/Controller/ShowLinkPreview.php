<?php

/*
 * This file is part of datlechin/flarum-link-preview.
 *
 * Copyright (c) 2026 Ngo Quoc Dat.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Datlechin\LinkPreview\Api\Controller;

use Datlechin\LinkPreview\Preview\Previewer;
use Flarum\Http\Exception\InvalidParameterException;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * No permission check: a preview says no more than the page already tells any
 * visitor. What keeps the endpoint from being an open proxy is SafeFetcher's
 * address checks, the throttler and the cache.
 */
final class ShowLinkPreview implements RequestHandlerInterface
{
    public function __construct(private Previewer $previewer)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $url = is_array($body) ? ($body['url'] ?? null) : null;

        if (! is_string($url)) {
            throw new InvalidParameterException('url must be a string');
        }

        $preview = $this->previewer->preview($url, RequestUtil::getActor($request));

        return new JsonResponse(['data' => $preview->toArray()]);
    }
}
