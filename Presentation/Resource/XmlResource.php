<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

use Cristal\Presentation\PPTX;
use Cristal\Presentation\ResourceInterface;
use SimpleXMLElement;

/**
 * XML resource class for handling XML files in a PPTX archive.
 */
class XmlResource extends GenericResource
{
    protected const RELS_XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

    protected const ID_0 = 2147483647;

    protected static int $lastId = self::ID_0;

    /**
     * The parsed XML content.
     */
    protected SimpleXMLElement $content;

    /**
     * Resources linked to this XML.
     *
     * @var array<string, ResourceInterface>
     */
    protected array $resources = [];

    /**
     * Original content for change detection.
     */
    protected ?string $originalContent = null;

    /**
     * XML namespaces.
     *
     * @var array<string, string>
     */
    protected array $namespaces = [];

    /**
     * XmlResource constructor.
     */
    public function __construct(string $target, string $relType, string $contentType, PPTX $document)
    {
        parent::__construct($target, $relType, $contentType, $document);

        $originalContent = $this->document->getArchive()->getFromName($this->getInitialTarget());
        if($originalContent) {
            $this->setContent($originalContent);
            $this->originalContent = $originalContent;
            $this->namespaces = $this->content->getNamespaces(true);
            $this->setHighestId();
        }
    }

    /**
     * Reset an XML content from a string.
     *
     * @param string $content Must be a valid XML.
     */
    public function setContent(string $content): void
    {
        $this->content = new SimpleXMLElement($content, LIBXML_NOWARNING);
    }

    /**
     * Returns a string content from the XML object.
     */
    public function getContent(): string
    {
        return $this->content->asXml();
    }

    /**
     * Get the parsed XML content.
     */
    public function getXmlContent(): SimpleXMLElement
    {
        return $this->content;
    }

    /**
     * Set the parsed XML content.
     */
    public function setXmlContent(SimpleXMLElement $content): void
    {
        $this->content = $content;
    }

    /**
     * Return initial rels path of the XML.
     */
    protected function getInitialRelsName(): string
    {
        $pathInfo = pathinfo($this->getInitialTarget());

        return $pathInfo['dirname'] . '/_rels/' . $pathInfo['basename'] . '.rels';
    }

    /**
     * Return rels path of the XML.
     */
    protected function getRelsName(): string
    {
        $pathInfo = pathinfo($this->getTarget());

        return $pathInfo['dirname'] . '/_rels/' . $pathInfo['basename'] . '.rels';
    }

    /**
     * Explore XML to find its resources.
     */
    protected function mapResources(): void
    {
        if (count($this->resources) !== 0) {
            return;
        }

        $content = $this->initialDocument->getArchive()->getFromName($this->getInitialRelsName());

        if (!$content) {
            return;
        }

        $resources = new SimpleXMLElement($content, LIBXML_NOWARNING);
        $contentType = $this->initialDocument->getContentType();

        foreach ($resources as $resource) {
            if ((string)$resource['TargetMode'] === 'External') {
                $res = $contentType->getResource(
                    (string)$resource['Target'],
                    (string)$resource['Type'],
                    true
                );
            } else {
                $res = $contentType->getResource(
                    dirname($this->target) . '/' . (string)$resource['Target'],
                    (string)$resource['Type']
                );
            }

            $this->resources[(string)$resource['Id']] = $res;
        }
    }

    /**
     * Get all resource links of the XML.
     *
     * @return array<string, ResourceInterface>
     */
    public function getResources(): array
    {
        $this->mapResources();

        return $this->resources;
    }

    /**
     * Get a specific resource from its identifier.
     */
    public function getResource(string $id): ?ResourceInterface
    {
        return $this->getResources()[$id] ?? null;
    }

    /**
     * Add a resource to XML and generate an identifier.
     *
     * The rId must be unique within this XML's .rels file.
     * We check both the mapped resources AND the current .rels file in the archive
     * to avoid collisions when merging presentations.
     *
     * @return string|null Return the identifier.
     */
    public function addResource(ResourceInterface $resource): ?string
    {
        $this->mapResources();

        // Collect all existing rIds from mapped resources
        $existingIds = array_map(static function (string $str): int {
            return (int)str_replace('rId', '', $str);
        }, array_keys($this->resources));

        // Also check the current .rels file in the archive for any rIds not yet mapped
        // This is crucial when adding resources to a presentation that has existing relationships
        $archiveRelsIds = $this->getExistingRelsIds();

        $allIds = array_merge($existingIds, $archiveRelsIds, [0]);
        $nextId = max($allIds) + 1;

        $this->resources['rId' . $nextId] = $resource;

        // Mark as modified so save() will write the changes
        $this->hasChange = true;

        return 'rId' . $nextId;
    }

    /**
     * Get all rId values from the current .rels file in the archive.
     * This ensures we don't create collisions with existing relationships.
     *
     * @return int[] Array of existing rId numbers
     */
    protected function getExistingRelsIds(): array
    {
        $relsPath = $this->getRelsName();
        $content = $this->document->getArchive()->getFromName($relsPath);
        
        if (!$content) {
            return [];
        }
        
        $ids = [];
        try {
            $xml = new SimpleXMLElement($content, LIBXML_NOWARNING);
            foreach ($xml->children('http://schemas.openxmlformats.org/package/2006/relationships') as $rel) {
                $id = (string) $rel['Id'];
                if (preg_match('/^rId(\d+)$/', $id, $matches)) {
                    $ids[] = (int) $matches[1];
                }
            }
        } catch (\Exception $e) {
            // If parsing fails, return empty array
        }
        
        return $ids;
    }

    /**
     * Sort resources to ensure proper order in .rels files.
     * For presentation.xml.rels: Slides first, then masters, then system resources (props, themes).
     * This fixes the corruption issue where system resources were inserted between slides.
     *
     * @param array<string, ResourceInterface> $resources
     * @return array<string, ResourceInterface>
     */
    protected function sortResourcesByPriority(array $resources): array
    {
        // Only sort for presentation.xml (not for other XML files)
        if (!$this instanceof Presentation) {
            return $resources;
        }

        $slides = [];
        $masters = [];
        $systemResources = [];

        foreach ($resources as $id => $resource) {
            if ($resource instanceof Slide) {
                $slides[$id] = $resource;
            } elseif ($resource instanceof SlideMaster || $resource instanceof HandoutMaster) {
                $masters[$id] = $resource;
            } else {
                // NoteMaster, presProps, viewProps, theme, tableStyles, etc.
                $systemResources[$id] = $resource;
            }
        }

        // Order: Slides first, then masters, then system resources
        return array_merge($slides, $masters, $systemResources);
    }

    /**
     * Set a resource by its ID.
     *
     * @param string $id The resource ID (e.g., 'rId1')
     * @param ResourceInterface $resource The resource to set
     */
    public function setResource(string $id, ResourceInterface $resource): void
    {
        $this->resources[$id] = $resource;
    }

    /**
     * Save XML and resource rels file.
     */
    protected function performSave(): void
    {
        // CRITICAL: Load resources BEFORE resetIds() to ensure they're preserved
        $this->mapResources();

        $this->resetIds();
        parent::performSave();

        if (count($this->resources) === 0) {
            return;
        }

        $resourceXML = new SimpleXMLElement(static::RELS_XML, LIBXML_NOWARNING);

        // Sort resources to ensure proper order (slides first, system resources last)
        $sortedResources = $this->sortResourcesByPriority($this->resources);

        foreach ($sortedResources as $id => $resource) {
            $relation = $resourceXML->addChild('Relationship');
            $relation->addAttribute('Id', $id);
            $relation->addAttribute('Type', $resource->getRelType());
            $relation->addAttribute('Target', $resource->getRelativeTarget($this->getTarget()));

            if ($resource instanceof External) {
                $relation->addAttribute('TargetMode', 'External');
            }
        }

        $this->document->getArchive()->addFromString($this->getRelsName(), $resourceXML->asXml());
    }

    /**
     * Check if the resource has been modified.
     */
    public function isDraft(): bool
    {
        return $this->originalContent !== $this->content->asXML() || parent::isDraft();
    }

    /**
     * Set the highest ID from the XML content.
     */
    protected function setHighestId(): void
    {
        if (!isset($this->namespaces['r'])) {
            return;
        }

        foreach ($this->content->xpath('//@id/..') as $node) {
            $id = (int)$node['id'];

            if (self::$lastId < $id && $node->attributes($this->namespaces['r'])->id) {
                self::$lastId = $id;
            }
        }
    }

    /**
     * Resetting IDs, prevents errors when you add the same SlideMaster several times.
     */
    protected function resetIds(): void
    {
        if (!isset($this->namespaces['r'])) {
            return;
        }

        foreach ($this->content->xpath('//@id/..') as $node) {
            $id = (int)$node['id'];

            if ($id > self::ID_0 && $node->attributes($this->namespaces['r'])->id) {
                $node['id'] = (string) self::getUniqueID();
            }
        }
    }

    /**
     * Generate a unique ID.
     */
    public static function getUniqueID(): int
    {
        return ++self::$lastId;
    }

    /**
     * Get the XML namespaces.
     *
     * @return array<string, string>
     */
    public function getNamespaces(): array
    {
        return $this->namespaces;
    }
}
