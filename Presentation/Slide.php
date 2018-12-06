<?php

namespace Cpro\Presentation;

use Cpro\Presentation\Resource\XmlResource;

class Slide
{
    /**
     * @var Resource
     */
    protected $xmlFile;

    /**
     * Slide constructor.
     * @param XmlResource $xmlFile
     */
    public function __construct(XmlResource $xmlFile)
    {
        $this->xmlFile = $xmlFile;
    }

    /**
     * @return Resource
     */
    public function getResource()
    {
        return $this->xmlFile;
    }
}