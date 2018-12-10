<?php

namespace Cpro\Presentation;

use Cpro\Presentation\Resource\Resource;
use Cpro\Presentation\Resource\Slide;
use Cpro\Presentation\Resource\SlideLayout;
use Cpro\Presentation\Resource\XmlResource;

class ContentType
{
    /**
     * Classes mapping
     */
    const CLASSES = [
        'application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml' => SlideLayout::class,
        'application/vnd.openxmlformats-officedocument.presentationml.slide+xml' => Slide::class,
        'application/xml' => XmlResource::class,
        '_' => Resource::class,
    ];

    /**
     * ContentType constructor.
     */
    private function __construct()
    {
    }

    /**
     * Get the content type of the file based on its filename.
     *
     * @param $filename
     * @return string
     */
    static public function getTypeFromFilename($filename)
    {
        if (pathinfo($filename)['extension'] === 'xml') {
            preg_match('/([a-z]+)[0-9]*\.xml$/i', $filename, $fileType);
            return 'application/vnd.openxmlformats-officedocument.presentationml.'.$fileType[1].'+xml';
        }

        return '';
    }

    /**
     * Get resource class from its contentType.
     *
     * @param $contentType
     * @return mixed
     */
    static public function getResourceClassFromType($contentType)
    {
        if (isset(static::CLASSES[$contentType])) {
            return static::CLASSES[$contentType];
        }

        return static::CLASSES['_'];
    }

    /**
     * Get resource class from its filename.
     *
     * @param $filename
     * @return mixed
     */
    static public function getResourceClassFromFilename($filename)
    {
        $contentType = static::getTypeFromFilename($filename);

        if (empty($contentType) && pathinfo($filename)['extension'] === 'xml') {
            $contentType = 'application/xml';
        }

        return static::getResourceClassFromType($contentType);
    }
}