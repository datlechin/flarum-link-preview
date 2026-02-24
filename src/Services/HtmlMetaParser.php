<?php

namespace Datlechin\LinkPreview\Services;

use DOMDocument;
use DOMXPath;

class HtmlMetaParser
{
    public function parse(string $html): array
    {
        libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $title = $this->extractTitle($dom);
        $meta = $this->extractMeta($xpath);

        return [
            'title' => $title,
            'og:title' => $meta['og']['og:title'] ?? null,
            'og:description' => $meta['og']['og:description'] ?? null,
            'og:image' => $meta['og']['og:image'] ?? null,
            'og:site_name' => $meta['og']['og:site_name'] ?? null,
            'twitter:title' => $meta['tc']['twitter:title'] ?? null,
            'twitter:description' => $meta['tc']['twitter:description'] ?? null,
            'twitter:image' => $meta['tc']['twitter:image'] ?? null,
            'twitter:site' => $meta['tc']['twitter:site'] ?? null,
            'meta:description' => $meta['description'],
        ];
    }

    protected function extractTitle(DOMDocument $dom): ?string
    {
        $titleNodes = $dom->getElementsByTagName('title');

        if ($titleNodes->length > 0) {
            return trim($titleNodes->item(0)->textContent);
        }

        return null;
    }

    protected function extractMeta(DOMXPath $xpath): array
    {
        $og = [];
        $tc = [];
        $description = null;

        $metaNodes = $xpath->query('//meta[@property or @name]');

        foreach ($metaNodes as $node) {
            $property = $node->getAttribute('property');
            $name = $node->getAttribute('name');
            $content = $node->getAttribute('content');

            if (str_starts_with($property, 'og:')) {
                $og[$property] = $content;
            }

            if (str_starts_with($name, 'twitter:') || str_starts_with($property, 'twitter:')) {
                $key = $name ?: $property;
                $tc[$key] = $content;
            }

            if ($name === 'description') {
                $description = $content;
            }
        }

        return [
            'og' => $og,
            'tc' => $tc,
            'description' => $description,
        ];
    }
}
