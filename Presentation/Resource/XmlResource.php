<?php

namespace Cpro\Presentation\Resource;

use ZipArchive;
use SimpleXMLElement;

class XmlResource extends GenericResource
{
    protected const RELS_XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

    protected const ID_0 = 2147483647;

    /**
     * @var SimpleXMLElement
     */
    public $content;

    /**
     * @var GenericResource[]
     */
    public $resources = [];

    /**
     * XmlResource constructor.
     */
    public function __construct(string $target, string $type, ZipArchive $zipArchive)
    {
        parent::__construct($target, $type, $zipArchive);

        $this->setContent($this->initialZipArchive->getFromName($this->getInitialTarget()));
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
        return $this->crlfConversion($this->content->asXml());
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
            $content = $this->initialZipArchive->getFromName($this->getInitialRelsName());

            if (!$content) {
                return;
            }

            $resources = new SimpleXMLElement($content);

            foreach ($resources as $resource) {
                $this->resources[(string)$resource['Id']] = static::createFromNode(
                    dirname($this->target) . '/' . $resource['Target'],
                    $resource['Type'],
                    $this->initialZipArchive
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
     *
     * @param $id
     * @return null|GenericResource
     */
    public function getResource($id): ?GenericResource
    {
        return $this->getResources()[$id] ?? null;
    }

    /**
     * Add a resource to XML and generate an identifier.
     *
     * @param GenericResource $resource
     * @return string Return the identifier.
     */
    public function addResource(GenericResource $resource): string
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
        parent::performSave();

        if (!count($this->getResources())) {
            return;
        }

        $resourceXML = new SimpleXMLElement(static::RELS_XML);

        foreach ($this->resources as $id => $resource) {
            $relation = $resourceXML->addChild('Relationship');
            $relation['Id'] = $id;
            $relation['Type'] = $resource->getType();
            $relation['Target'] = $resource->getRelativeTarget($this->getTarget());
        }

        $this->zipArchive->addFromString($this->getRelsName(), $this->crlfConversion($resourceXML->asXml()));
    }

    protected function crlfConversion($content)
    {
        $content = trim($content);
        $content = str_replace(PHP_EOL, "\r\n", $content);
        return $content;
    }
}
