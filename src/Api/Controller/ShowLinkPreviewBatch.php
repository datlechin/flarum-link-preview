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

        return new JsonResponse(['data' => (object) $data]);
    }

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
