<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * NoteSlide resource class for handling speaker notes.
 *
 * @see ECMA-376 Part 1, Section 13.3.4 - Notes Slide Part
 */
class NoteSlide extends XmlResource
{
    /**
     * Get the speaker notes text content.
     *
     * @return string Plain text content of notes
     */
    public function getTextContent(): string
    {
        $text = [];
        $this->registerXPathNamespaces();

        $paragraphs = $this->content->xpath('//p:txBody//a:t');

        if ($paragraphs === false) {
            return '';
        }

        foreach ($paragraphs as $paragraph) {
            $text[] = (string) $paragraph;
        }

        return implode("\n", $text);
    }

    /**
     * Set the speaker notes text content.
     *
     * @param string $text Plain text to set
     */
    public function setTextContent(string $text): void
    {
        $this->registerXPathNamespaces();
        $textNodes = $this->content->xpath('//p:txBody//a:t');

        if ($textNodes !== false && !empty($textNodes)) {
            $domNode = dom_import_simplexml($textNodes[0]);
            $domNode->nodeValue = $text;
            $this->save();
        }
    }

    /**
     * Check if notes have any content.
     */
    public function hasContent(): bool
    {
        return !empty(trim($this->getTextContent()));
    }

    /**
     * Get all text paragraphs as an array.
     *
     * @return array<int, string> Array of paragraph texts
     */
    public function getParagraphs(): array
    {
        $paragraphs = [];
        $this->registerXPathNamespaces();

        $nodes = $this->content->xpath('//p:txBody/a:p');

        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            $text = '';
            $textElements = $node->xpath('.//a:t');
            if ($textElements !== false) {
                foreach ($textElements as $t) {
                    $text .= (string) $t;
                }
            }
            if (!empty(trim($text))) {
                $paragraphs[] = $text;
            }
        }

        return $paragraphs;
    }

    /**
     * Register XPath namespaces for querying.
     */
    private function registerXPathNamespaces(): void
    {
        $namespaces = $this->getNamespaces();

        foreach ($namespaces as $prefix => $uri) {
            if ($prefix !== '') {
                $this->content->registerXPathNamespace($prefix, $uri);
            }
        }

        // Ensure common namespaces are registered
        if (!isset($namespaces['p'])) {
            $this->content->registerXPathNamespace(
                'p',
                'http://schemas.openxmlformats.org/presentationml/2006/main'
            );
        }
        if (!isset($namespaces['a'])) {
            $this->content->registerXPathNamespace(
                'a',
                'http://schemas.openxmlformats.org/drawingml/2006/main'
            );
        }
    }
}