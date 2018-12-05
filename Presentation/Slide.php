<?php

namespace Cpro\Presentation;

class Slide
{
    /**
     * @var XmlFile
     */
    protected $xmlFile;

    /**
     * Slide constructor.
     * @param XmlFile $xmlFile
     */
    public function __construct(XmlFile $xmlFile)
    {
        $this->xmlFile = $xmlFile;
    }

    /**
     * @return string
     */
    public function getFilename()
    {
        return $this->xmlFile->getFilename();
    }

    /**
     * @return string
     */
    public function getXML()
    {
        return $this->xmlFile;
    }

    /**
     * @return Resource[]
     */
    public function getResource()
    {
        return $this->xmlFile->resources;
    }
}