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
     * Flag to indicate this is a cloned SlideMaster.
     * Used to prevent loading source document's layout references.
     */
    protected bool $isCloned = false;

    /**
     * Original theme target path (preserved during clone for re-linking).
     */
    protected ?string $originalThemeTarget = null;

    /**
     * Clone handler - mark as cloned to prevent cross-master layout conflicts.
     *
     * When a SlideMaster is cloned for merging, mapResources() would read
     * from the SOURCE document's .rels file and load layout references
     * that belong to OTHER masters in the destination.
     *
     * By clearing resources and marking as cloned, we prevent this.
     * New layouts will be added via registerSlideLayoutsWithMaster().
     */
    public function __clone()
    {
        // Before clearing, save the Theme reference path for later re-linking
        foreach ($this->resources as $resource) {
            if ($resource instanceof Theme) {
                $this->originalThemeTarget = $resource->getTarget();
                break;
            }
        }

        // Clear the resources array to prevent source layout references
        $this->resources = [];

        // CRITICAL: Clear the sldLayoutIdLst in the XML content
        // Otherwise the old layout references remain and new ones are duplicated
        $this->clearSldLayoutIdLst();

        // Mark as cloned so mapResources() won't re-read from source
        $this->isCloned = true;

        // Mark as changed so save() will regenerate the .rels file
        $this->hasChange = true;
    }

    /**
     * Get the original Theme target path before cloning.
     * Used by updateSlideMasterThemeReference() to find the cloned Theme.
     */
    public function getOriginalThemeTarget(): ?string
    {
        return $this->originalThemeTarget;
    }

    /**
     * Override mapResources to prevent loading source document's layouts for cloned masters.
     */
    protected function mapResources(): void
    {
        // If this is a cloned SlideMaster and resources were already cleared,
        // don't re-read from the source document's .rels file
        if ($this->isCloned) {
            return;
        }

        parent::mapResources();
    }

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
     * Clear all entries from the <p:sldLayoutIdLst> element in the XML content.
     * Called during clone to prevent duplicate layout references.
     */
    protected function clearSldLayoutIdLst(): void
    {
        $xml = $this->getXmlContent();
        $namespaces = $xml->getNamespaces(true);

        $pNs = $namespaces['p'] ?? 'http://schemas.openxmlformats.org/presentationml/2006/main';
        $xml->registerXPathNamespace('p', $pNs);

        $sldLayoutIdLst = $xml->xpath('//p:sldLayoutIdLst');

        if (empty($sldLayoutIdLst)) {
            return;
        }

        $layoutIdList = $sldLayoutIdLst[0];

        // Remove all child elements (sldLayoutId entries)
        $dom = dom_import_simplexml($layoutIdList);
        while ($dom->firstChild) {
            $dom->removeChild($dom->firstChild);
        }
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
     * Get the SHA256 hash of this master's Theme content.
     *
     * Used to distinguish SlideMasters that have the same layout types
     * but different themes during merge deduplication.
     *
     * @return string|null The theme content hash, or null if no theme found
     */
    public function getThemeHash(): ?string
    {
        foreach ($this->getResources() as $resource) {
            if ($resource instanceof Theme) {
                return $resource->getHashFile();
            }
        }

        return null;
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
