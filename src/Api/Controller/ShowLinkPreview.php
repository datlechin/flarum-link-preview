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
 * A preview of one link, for a reader looking at a post that contains it.
 *
 * A POST for what is plainly a read: a GET is reachable from any page on the
 * web with nothing more than an `<img src>`, so every visitor to an attacker's
 * page would become an outbound connection from this forum to an address of
 * the attacker's choosing, at their rate. A POST carrying a JSON body needs a
 * preflight this forum does not answer cross site.
 *
 * No permission check. The preview says no more than the page already tells
 * any visitor; SafeFetcher's address checks, the throttler and the cache are
 * what keep the endpoint from being an open proxy.
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

        // A malformed request is the caller's mistake and gets a 400. A URL
        // that simply cannot be previewed is not: that comes back as an
        // ordinary, cacheable answer carrying an error code.
        if (! is_string($url)) {
            throw new InvalidParameterException('url must be a string');
        }

        $preview = $this->previewer->preview($url, RequestUtil::getActor($request));

        return new JsonResponse(['data' => $preview->toArray()]);
    }
}
