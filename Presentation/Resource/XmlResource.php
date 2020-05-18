<?php

namespace Cristal\Presentation\Resource;

use Cristal\Presentation\PPTX;
use SimpleXMLElement;

class XmlResource extends GenericResource
{
    protected const RELS_XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

    protected const ID_0 = 2147483647;

    protected static $lastId = self::ID_0;

    /**
     * @var SimpleXMLElement
     */
    public $content;

    /**
     * @var GenericResource[]
     */
    public $resources = [];

    /**
     * @var mixed
     */
    protected $originalContent;

    /**
     * @var array
     */
    protected $namespaces;

    /**
     * XmlResource constructor.
     */
    public function __construct(string $target, string $relType, string $contentType, PPTX $document)
    {
        parent::__construct($target, $relType, $contentType, $document);

        $originalContent = $this->document->getArchive()->getFromName($this->getInitialTarget());
        $this->setContent($originalContent);
        $this->originalContent = $originalContent;
        $this->namespaces = $this->content->getNamespaces(true);
        $this->setHighestId();
    }

    /**
     * Reset an XML content from a string.
     *
     * @param string $content Must be a valid XML.
     */
    public function setContent(string $content): void
    {
        $this->content = new SimpleXMLElement($content);
    }

    /**
     * Returns a string content from the XML object.
     */
    public function getContent(): string
    {
        return $this->content->asXml();
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
        if (!count($this->resources)) {
            $content = $this->initialDocument->getArchive()->getFromName($this->getInitialRelsName());

            if (!$content) {
                return;
            }

            $resources = new SimpleXMLElement($content);

            foreach ($resources as $resource) {
                $this->resources[(string)$resource['Id']] = $this->initialDocument->getContentType()->getResource(
                    self::resolveAbsolutePath(dirname($this->target) . '/' . $resource['Target']),
                    $resource['Type']
                );
            }
        }
    }

    /**
     * Get all resource links of the XML.
     *
     * @return GenericResource[]
     */
    public function getResources(): array
    {
        $this->mapResources();
        return $this->resources;
    }

    /**
     * Get a specific resource from its identifier.
     */
    public function getResource(string $id): ?GenericResource
    {
        return $this->getResources()[$id] ?? null;
    }

    /**
     * Add a resource to XML and generate an identifier.
     *
     * @return string Return the identifier.
     */
    public function addResource(GenericResource $resource): ?string
    {
        $this->mapResources();

        $ids = array_merge(
            array_map(static function ($str) {
                return (int)str_replace('rId', '', $str);
            }, array_keys($this->resources)),
            [0]
        );

        $this->resources['rId' . (max($ids) + 1)] = $resource;

        return 'rId' . (max($ids) + 1);
    }

    /**
     * Save XML and resource rels file.
     */
    protected function performSave(): void
    {
        $this->resetIds();
        parent::performSave();

        if (!count($this->getResources())) {
            return;
        }

        $resourceXML = new SimpleXMLElement(static::RELS_XML);

        foreach ($this->resources as $id => $resource) {
            $relation = $resourceXML->addChild('Relationship');
            $relation->addAttribute('Id', $id);
            $relation->addAttribute('Type', $resource->getRelType());
            $relation->addAttribute('Target', $resource->getRelativeTarget($this->getTarget()));
        }

        $this->document->getArchive()->addFromString($this->getRelsName(), $resourceXML->asXml());
    }

    public function isDraft(): bool
    {
        return $this->originalContent !== $this->content->asXML() || parent::isDraft();
    }

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
     * Resetting IDs, prevents errors when you a add the same SlideMaster several times.
     */
    protected function resetIds(): void
    {
        if (!isset($this->namespaces['r'])) {
            return;
        }

        foreach ($this->content->xpath('//@id/..') as $node) {
            $id = (int)$node['id'];

            if ($id > self::ID_0 && $node->attributes($this->namespaces['r'])->id) {
                $node['id'] = self::getUniqueID();
            }
        }
    }

    public static function getUniqueID(): int
    {
        return ++self::$lastId;
    }
}
