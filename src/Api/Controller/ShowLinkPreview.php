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
 * A preview of one link, for a reader who is looking at a post that contains it.
 *
 * A plain PSR-15 handler rather than an `Extend\ApiResource`: there is no model
 * behind this and no id to address, so a JSON:API resource would have to invent
 * both. What comes back is a computed document about a remote page.
 *
 * A POST for what is plainly a read, which is the one surprise here. A GET is
 * reachable from any page on the web with nothing more than an `<img src>`, so
 * every visitor to an attacker's page would become an outbound connection from
 * this forum to an address of the attacker's choosing, at their rate rather
 * than at ours. A POST carrying a JSON body needs a preflight that this forum
 * does not answer cross site, which closes that off.
 *
 * No permission check. Anyone who can read the post can read the link in it,
 * and the preview says no more than the page it points at already tells any
 * visitor. What keeps the endpoint from being an open proxy is elsewhere: the
 * address checks in SafeFetcher, the throttler and the cache.
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
        // that simply cannot be previewed is not: that is an ordinary answer
        // carrying an error code, and it is cacheable.
        if (! is_string($url)) {
            throw new InvalidParameterException('url must be a string');
        }

        $preview = $this->previewer->preview($url, RequestUtil::getActor($request));

        return new JsonResponse(['data' => $preview->toArray()]);
    }
}
