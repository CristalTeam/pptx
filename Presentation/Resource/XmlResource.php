<?php

namespace Cpro\Presentation\Resource;

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

    /**
     * @param            $resourceNode
     * @param            $relativeFile
     * @param ZipArchive $zipArchive
     * @return static
     */
    public static function createFromNode($resourceNode, $relativeFile, ZipArchive $zipArchive)
    {
        if (pathinfo($resourceNode['Target'])['extension'] === 'xml') {
            $className = static::class;
        } else {
            $className = parent::class;
        }

        return new $className((string) $resourceNode['Target'], (string) $resourceNode['Type'], $relativeFile, $zipArchive);
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
        $this->zipArchive->addFromString($this->getAbsoluteTarget(), $this->content->asXml());

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

        /*if($this->getAbsoluteTarget() == 'ppt/slides/slide2.xml'){
            dd($this->getRelsName());
            dump($this->getResources());
        }*/

        $this->zipArchive->addFromString($this->getRelsName(), $resourceXML->asXml());
    }
}