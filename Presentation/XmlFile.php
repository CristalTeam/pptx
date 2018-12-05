<?php

namespace Cpro\Presentation;

use ZipArchive;

class XmlFile
{
    /**
     * @var string
     */
    protected $filename;
    /**
     * @var string
     */
    protected $initalFilename;

    /**
     * @var \SimpleXMLElement
     */
    public $content;

    /**
     * @var Resource[]
     */
    public $resources = [];

    /**
     * @var ZipArchive
     */
    protected $zipArchive;

    /**
     * @var ZipArchive
     */
    protected $initalZipArchive;

    public function __construct(ZipArchive $zip, $filename)
    {
        $this->filename = $this->initalFilename = $filename;
        $this->zipArchive = $this->initalZipArchive = $zip;

        $this->content = new \SimpleXMLElement($zip->getFromName($this->filename));
        $this->mapResources();
    }

    /**
     * @return string
     */
    public function getFilename()
    {
        return $this->filename;
    }

    protected function getRelsName()
    {
        $pathInfo = pathinfo($this->filename);
        return $pathInfo['dirname'].'/_rels/'.$pathInfo['basename'].'.rels';
    }

    protected function mapResources()
    {
        $content = $this->initalZipArchive->getFromName($this->getRelsName());
        if (!$content) {
            return false;
        }
        $resources = new \SimpleXMLElement($content);
        foreach ($resources as $resource) {
            $this->resources[(string) $resource['Id']] = Resource::createFromNode($resource, $this->filename, $this->initalZipArchive);
        }
    }

    public function getResources()
    {
        return $this->resources;
    }

    public function getResource($id)
    {
        return $this->getResources()[$id] ?? null;
    }

    public function addResource($target, $type)
    {
        $ids = array_map(function ($str) {
            return str_replace('rId', '', $str);
        }, array_keys($this->resources));

        $this->resources['rId'.(max($ids) + 1)] = new Resource($target, $type);

        return 'rId'.(max($ids) + 1);
    }

    public function save()
    {
        $this->zipArchive->addFromString($this->getFilename(), $this->content->asXml());

        if (!count($this->resources)) {
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

    /**
     * @param $filename
     * @return $this
     * @throws \Exception
     */
    public function rename($filename)
    {
        if (preg_match('#/#', $filename)) {
            throw new \Exception('Filename can not be a a path.');
        }

        $this->filename = dirname($this->initalFilename).'/'.$filename;

        return $this;
    }

    public function setZipArchive(ZipArchive $zipArchive)
    {
        $this->zipArchive = $zipArchive;
        return $this;
    }

    public function __call($method, $parameters)
    {
        return $this->content->{$method}(...$parameters);
    }
}