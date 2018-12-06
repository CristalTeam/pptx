<?php

namespace Cpro\Presentation;

use Cpro\Presentation\Resource\Resource;
use Cpro\Presentation\Resource\Slide;
use Cpro\Presentation\Resource\SlideLayout;
use Cpro\Presentation\Resource\XmlResource;

class ContentType
{
    const CLASSES = [
        'application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml' => SlideLayout::class,
        'application/vnd.openxmlformats-officedocument.presentationml.slide+xml' => Slide::class,
        'application/xml' => XmlResource::class,
        '_' => Resource::class,
    ];

    private function __construct()
    {
    }

    static public function getTypeFromFilename($filename)
    {
        if (pathinfo($filename)['extension'] === 'xml') {
            preg_match('/([a-z]+)[0-9]*\.xml$/i', $filename, $fileType);
            return 'application/vnd.openxmlformats-officedocument.presentationml.'.$fileType[1].'+xml';
        }

        return '';
    }

    static public function getResourceClassFromFilename($filename)
    {
        $contentType = static::getTypeFromFilename($filename);
        if (isset(static::CLASSES[$contentType])) {
            return static::CLASSES[$contentType];
        } elseif (pathinfo($filename)['extension'] === 'xml') {
            return static::CLASSES['application/xml'];
        }

        return static::CLASSES['_'];
    }
}