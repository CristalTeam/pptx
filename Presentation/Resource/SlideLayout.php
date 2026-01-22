<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * Slide layout resource class.
 */
class SlideLayout extends XmlResource
{
    /**
     * Get the layout type (e.g., 'title', 'obj', 'blank', etc.).
     *
     * This is used for layout matching during merge operations.
     * PowerPoint identifies layouts by their type attribute.
     */
    public function getLayoutType(): ?string
    {
        $xml = $this->getXmlContent();
        $type = (string)$xml['type'];
        
        return $type !== '' ? $type : null;
    }
    
    /**
     * Get the SlideMaster this layout belongs to.
     *
     * @return SlideMaster|null The parent SlideMaster, or null if not found
     */
    public function getSlideMaster(): ?SlideMaster
    {
        foreach ($this->getResources() as $resource) {
            if ($resource instanceof SlideMaster) {
                return $resource;
            }
        }
        
        return null;
    }
}
