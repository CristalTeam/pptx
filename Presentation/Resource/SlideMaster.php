<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * Slide master resource class.
 */
class SlideMaster extends XmlResource
{
    /**
     * Get layout types signature for this SlideMaster.
     *
     * Returns a sorted array of layout types (e.g., ['blank', 'obj', 'title', ...])
     * Used for comparing SlideMasters by their layout structure rather than content.
     *
     * @return array<string> Sorted list of layout types
     */
    public function getLayoutTypesSignature(): array
    {
        $types = [];
        
        foreach ($this->getResources() as $resource) {
            if ($resource instanceof SlideLayout) {
                $type = $resource->getLayoutType();
                if ($type !== null) {
                    $types[] = $type;
                }
            }
        }
        
        sort($types);
        return $types;
    }
}
