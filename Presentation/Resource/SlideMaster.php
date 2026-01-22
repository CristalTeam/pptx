<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

use Cristal\Presentation\ResourceInterface;

/**
 * Slide master resource class.
 */
class SlideMaster extends XmlResource
{
    /**
     * Add a resource to the SlideMaster.
     *
     * When adding a SlideLayout, this method also updates the <p:sldLayoutIdLst>
     * element in the SlideMaster's XML content to maintain consistency between
     * the .rels file and the XML content.
     *
     * @param ResourceInterface $resource The resource to add
     * @return string|null The relationship ID assigned to the resource
     */
    public function addResource(ResourceInterface $resource): ?string
    {
        // Call parent to add to .rels
        $rId = parent::addResource($resource);

        // If adding a SlideLayout, also update the XML content's sldLayoutIdLst
        if ($resource instanceof SlideLayout && $rId !== null) {
            $this->addSlideLayoutToXml($rId);
        }

        return $rId;
    }

    /**
     * Add a SlideLayout entry to the <p:sldLayoutIdLst> element in the XML content.
     *
     * @param string $rId The relationship ID for the SlideLayout
     */
    protected function addSlideLayoutToXml(string $rId): void
    {
        $xml = $this->getXmlContent();
        $namespaces = $xml->getNamespaces(true);

        // Register namespaces for XPath
        $pNs = $namespaces['p'] ?? 'http://schemas.openxmlformats.org/presentationml/2006/main';
        $rNs = $namespaces['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        // Find the sldLayoutIdLst element
        $xml->registerXPathNamespace('p', $pNs);
        $sldLayoutIdLst = $xml->xpath('//p:sldLayoutIdLst');

        if (empty($sldLayoutIdLst)) {
            // If sldLayoutIdLst doesn't exist, we need to create it
            // This is rare but possible
            return;
        }

        $layoutIdList = $sldLayoutIdLst[0];

        // Generate a unique ID for the new layout entry
        $uniqueId = self::getUniqueID();

        // Create the new sldLayoutId element using DOM for proper namespace handling
        $dom = dom_import_simplexml($layoutIdList);
        $doc = $dom->ownerDocument;

        // Create the new element with the p namespace
        $newElement = $doc->createElementNS($pNs, 'p:sldLayoutId');
        $newElement->setAttribute('id', (string) $uniqueId);
        $newElement->setAttributeNS($rNs, 'r:id', $rId);

        $dom->appendChild($newElement);

        // Mark as changed
        $this->hasChange = true;
    }

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
