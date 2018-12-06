<?php

namespace Cpro\Presentation\Resource;

use Cpro\Presentation\ContentType;
use ZipArchive;

class XmlResource extends Resource
{
    /**
     * @var \SimpleXMLElement
     */
    public $content;

    /**
     * @var Resource[]
     */
    public $resources = [];

    public function __construct($target, $type, $relativeFile = '', ?ZipArchive $zipArchive = null)
    {
        parent::__construct($target, $type, $relativeFile, $zipArchive);

        $this->content = new \SimpleXMLElement($this->initalZipArchive->getFromName($this->getInitialAbsoluteTarget()));
    }

    public function getContent()
    {
        return $this->content->asXml();
    }

    protected function getInitialRelsName()
    {
        $pathInfo = pathinfo($this->getInitialAbsoluteTarget());
        return $pathInfo['dirname'].'/_rels/'.$pathInfo['basename'].'.rels';
    }

    protected function getRelsName()
    {
        $pathInfo = pathinfo($this->getAbsoluteTarget());
        return $pathInfo['dirname'].'/_rels/'.$pathInfo['basename'].'.rels';
    }

    protected function mapResources()
    {
        if (!count($this->resources)) {
            $content = $this->initalZipArchive->getFromName($this->getInitialRelsName());

            if (!$content) {
                return false;
            }
            $resources = new \SimpleXMLElement($content);
            foreach ($resources as $resource) {
                $this->resources[(string) $resource['Id']] = static::createFromNode($resource, $this->getInitialAbsoluteTarget(), $this->initalZipArchive);
            }
        }
    }

    public function getResources()
    {
        $this->mapResources();

        return $this->resources;
    }

    public function getResource($id)
    {
        return $this->getResources()[$id] ?? null;
    }

    public function addResource(Resource $resource)
    {
        $this->mapResources();

        $ids = array_map(function ($str) {
            return str_replace('rId', '', $str);
        }, array_keys($this->resources));

        $this->resources['rId'.(max($ids) + 1)] = $resource;

        return 'rId'.(max($ids) + 1);
    }

    public function save()
    {
        parent::save();

        if (!count($this->getResources())) {
            return;
        }

        $resourceXML = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>');
        foreach ($this->resources as $id => $resource) {
            $relation = $resourceXML->addChild('Relationship');
            $relation['Id'] = $id;
            $relation['Type'] = $resource->getType();
            $relation['Target'] = $resource->getTarget();
        }

        $this->zipArchive->addFromString($this->getRelsName(), $resourceXML->asXml());
    }
}