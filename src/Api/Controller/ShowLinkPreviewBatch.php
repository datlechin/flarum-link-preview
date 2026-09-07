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

use Datlechin\LinkPreview\Preview\Preview;
use Datlechin\LinkPreview\Preview\Previewer;
use Datlechin\LinkPreview\Settings\Config;
use Flarum\Http\Exception\InvalidParameterException;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Every preview one page needs, in one request.
 *
 * A post with five links would otherwise open five connections from the
 * browser and five rounds of fetching on the server. Asking together lets
 * SafeFetcher run them concurrently, and lets the throttler count a page view
 * as the one request it really is.
 */
final class ShowLinkPreviewBatch implements RequestHandlerInterface
{
    public function __construct(private Previewer $previewer)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $urls = is_array($body) ? ($body['urls'] ?? null) : null;

        if (! is_array($urls) || $urls === []) {
            throw new InvalidParameterException('urls must be a non-empty array');
        }

        $previews = $this->previewer->previewMany(
            $this->requested($urls),
            RequestUtil::getActor($request),
        );

        $data = array_map(
            fn (Preview $preview): array => $preview->toArray(),
            $previews,
        );

        // Cast, because an empty map encodes as `[]` and the client looks
        // every preview up by the URL it asked for.
        return new JsonResponse(['data' => (object) $data]);
    }

    /**
     * Anything past the cap is dropped rather than refused: a long post is a
     * reason to preview less of it, not a reason to show the reader an error.
     *
     * @param  array<mixed>  $urls
     * @return list<string>
     */
    private function requested(array $urls): array
    {
        $requested = [];

        foreach (array_slice($urls, 0, Config::MAX_BATCH_SIZE) as $url) {
            if (is_string($url)) {
                $requested[] = $url;
            }
        }

        return $requested;
    }
}
