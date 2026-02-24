<?php

namespace Datlechin\LinkPreview\Services;

use DOMDocument;
use DOMXPath;

class HtmlMetaParser
{
    protected array $openGraph = [];

    protected array $twitterCard = [];

    protected ?string $title = null;

    protected ?string $description = null;

    protected ?string $image = null;

    public function parse(string $html): self
    {
        libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $this->parseTitle($xpath, $dom);
        $this->parseMeta($xpath);

        return $this;
    }

    public function getOpenGraph(): array
    {
        return $this->openGraph;
    }

    public function getTwitterCard(): array
    {
        return $this->twitterCard;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    protected function parseTitle(DOMXPath $xpath, DOMDocument $dom): void
    {
        $titleNodes = $dom->getElementsByTagName('title');

        if ($titleNodes->length > 0) {
            $this->title = trim($titleNodes->item(0)->textContent);
        }
    }

    protected function parseMeta(DOMXPath $xpath): void
    {
        $metaNodes = $xpath->query('//meta[@property or @name]');

        foreach ($metaNodes as $node) {
            $property = $node->getAttribute('property');
            $name = $node->getAttribute('name');
            $content = $node->getAttribute('content');

            if (str_starts_with($property, 'og:')) {
                $this->openGraph[$property] = $content;
            }

            if (str_starts_with($name, 'twitter:') || str_starts_with($property, 'twitter:')) {
                $key = $name ?: $property;
                $this->twitterCard[$key] = $content;
            }

            if ($name === 'description') {
                $this->description = $content;
            }
        }

        // Fallback: image from og:image
        $this->image = $this->openGraph['og:image'] ?? null;

        // Fallback: description from og:description
        if ($this->description === null) {
            $this->description = $this->openGraph['og:description'] ?? null;
        }
    }
}
