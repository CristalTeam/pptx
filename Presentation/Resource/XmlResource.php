<?php

namespace Cpro\Presentation\Resource;

use ZipArchive;
use SimpleXMLElement;

class XmlResource extends Resource
{
    const RELS_XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

    const ID_0 = 2147483647;

    /**
     * @var \SimpleXMLElement
     */
    public $content;

    /**
     * @var Resource[]
     */
    public $resources = [];

    /**
     * XmlResource constructor.
     *
     * @param                 $target
     * @param                 $type
     * @param string          $relativeFile
     * @param null|ZipArchive $zipArchive
     */
    public function __construct($target, $type, $relativeFile = '', ?ZipArchive $zipArchive = null)
    {
        parent::__construct($target, $type, $relativeFile, $zipArchive);

        $this->setContent($this->initalZipArchive->getFromName($this->getInitialAbsoluteTarget()));
    }

    /**
     * Reset an XML content from a string.
     *
     * @param string $content Must be a valid XML.
     * @return $this
     */
    public function setContent(string $content)
    {
        $this->content = new SimpleXMLElement($content);

        return $this;
    }

    /**
     * Returns a string content from the XML object.
     *
     * @return mixed|string
     */
    public function getContent()
    {
        return $this->content->asXml();
    }

    /**
     * Return initial rels path of the XML.
     *
     * @return string
     */
    protected function getInitialRelsName()
    {
        $pathInfo = pathinfo($this->getInitialAbsoluteTarget());
        return $pathInfo['dirname'].'/_rels/'.$pathInfo['basename'].'.rels';
    }

    /**
     * Return rels path of the XML.
     *
     * @return string
     */
    protected function getRelsName()
    {
        $pathInfo = pathinfo($this->getAbsoluteTarget());
        return $pathInfo['dirname'].'/_rels/'.$pathInfo['basename'].'.rels';
    }

    /**
     * Explore XML to find its resources.
     *
     * @return bool
     */
    protected function mapResources()
    {
        if (!count($this->resources)) {
            $content = $this->initalZipArchive->getFromName($this->getInitialRelsName());

            if (!$content) {
                return false;
            }

            $resources = new SimpleXMLElement($content);

            foreach ($resources as $resource) {
                $this->resources[(string) $resource['Id']] = static::createFromNode(
                    $resource,
                    $this->getInitialAbsoluteTarget(),
                    $this->initalZipArchive
                );
            }
        }
    }

    /**
     * Get all resource links of the XML.
     *
     * @return Resource[]
     */
    public function getResources()
    {
        $this->mapResources();

        return $this->resources;
    }

    /**
     * Get a specific resource from its identifier.
     *
     * @param $id
     * @return null|Resource
     */
    public function getResource($id)
    {
        return $this->getResources()[$id] ?? null;
    }

    /**
     * Add a resource to XML and generate an identifier.
     *
     * @param Resource $resource
     * @return string Return the identifier.
     */
    public function addResource(Resource $resource)
    {
        $this->mapResources();

        $ids = array_merge(
            array_map(function ($str) {
                return (int) str_replace('rId', '', $str);
            }, array_keys($this->resources)),
            [ 0 ]
        );

        $this->resources['rId'.(max($ids) + 1)] = $resource;

        return 'rId'.(max($ids) + 1);
    }

    /**
     * Save XML and resource rels file.
     */
    protected function performSave()
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
            $relation['Target'] = $resource->getTarget();
        }

        $this->zipArchive->addFromString($this->getRelsName(), $resourceXML->asXml());
    }
}
