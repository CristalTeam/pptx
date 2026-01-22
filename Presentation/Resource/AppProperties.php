<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

use Cristal\Presentation\PPTX;
use SimpleXMLElement;

/**
 * App properties resource class for handling docProps/app.xml.
 * This file contains metadata about the presentation like slide count, notes count, etc.
 */
class AppProperties extends XmlResource
{
    protected SimpleXMLElement $content;

    public function __construct(string $target, string $relType, string $contentType, PPTX $document)
    {
        parent::__construct($target, $relType, $contentType, $document);
        $this->content = new SimpleXMLElement($this->getContent());
    }

    /**
     * Get the XML content.
     */
    public function getXmlContent(): SimpleXMLElement
    {
        return $this->content;
    }

    /**
     * Update slide count in app.xml.
     */
    public function updateSlideCount(int $count): void
    {
        if (!isset($this->content->Slides)) {
            $this->content->addChild('Slides', (string)$count);
        } else {
            $this->content->Slides = (string)$count;
        }
        $this->hasChange = true;
    }

    /**
     * Update notes count in app.xml.
     */
    public function updateNotesCount(int $count): void
    {
        if (!isset($this->content->Notes)) {
            $this->content->addChild('Notes', (string)$count);
        } else {
            $this->content->Notes = (string)$count;
        }
        $this->hasChange = true;
    }

    /**
     * Get current slide count.
     */
    public function getSlideCount(): int
    {
        return isset($this->content->Slides) ? (int)(string)$this->content->Slides : 0;
    }

    /**
     * Get current notes count.
     */
    public function getNotesCount(): int
    {
        return isset($this->content->Notes) ? (int)(string)$this->content->Notes : 0;
    }

    /**
     * Save the current resource.
     */
    protected function performSave(): void
    {
        $this->document->getArchive()->addFromString($this->getTarget(), $this->content->asXML());
    }
}