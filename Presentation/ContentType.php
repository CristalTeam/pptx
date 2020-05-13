<?php

namespace Cpro\Presentation;

use Cpro\Presentation\Resource\Image;
use Cpro\Presentation\Resource\NoteMaster;
use Cpro\Presentation\Resource\Presentation;
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
        'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml' => Presentation::class,
        'application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml' => SlideLayout::class,
        'application/vnd.openxmlformats-officedocument.presentationml.slide+xml' => Slide::class,
        'application/vnd.openxmlformats-officedocument.presentationml.notesMaster+xml' => NoteMaster::class,
        'application/vnd.openxmlformats-officedocument.presentationml.handoutMaster+xml' => XmlResource::class,
        'application/vnd.openxmlformats-officedocument.presentationml.tableStyles+xml' => XmlResource::class,
        'application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml' => XmlResource::class,
        'application/vnd.openxmlformats-officedocument.presentationml.theme+xml' => XmlResource::class,
        'application/vnd.openxmlformats-officedocument.presentationml.viewProps+xml' => XmlResource::class,
        'application/vnd.openxmlformats-officedocument.presentationml.presProps+xml' => XmlResource::class,
        'application/vnd.openxmlformats-officedocument.presentationml.notesSlide+xml' => XmlResource::class,
        'application/vnd.openxmlformats-officedocument.theme+xml' => XmlResource::class,
        'application/vnd.openxmlformats-package.core-properties+xml' => XmlResource::class,
        'application/vnd.openxmlformats-officedocument.extended-properties+xml' => XmlResource::class,
        'application/xml' => XmlResource::class,
        'application/image' => Image::class,
        'image/vnd.ms-photo' => Image::class,
        '_' => Resource::class,
    ];

    /**
     * ContentType constructor.
     */
    private function __construct()
    {
        //
    }

    /**
     * Get the content type of the file based on its filename.
     *
     * @param $filename
     * @return string
     */
    public static function getTypeFromFilename($filename)
    {
        $extension = pathinfo($filename)['extension'];
        if ($extension === 'xml') {
            preg_match('/([a-z]+)[0-9]*\.xml$/i', $filename, $fileType);
            return 'application/vnd.openxmlformats-officedocument.presentationml.' . $fileType[1] . '+xml';
        }

        if (in_array($extension, ['png', 'gif', 'jpg', 'jpeg'])) {
            return 'application/image';
        }

        if (in_array($extension, ['wdp', 'jxr', 'hdp'])) {
            return 'image/vnd.ms-photo';
        }

        return '';
    }

    /**
     * Get resource class from its contentType.
     *
     * @param $contentType
     * @return mixed
     */
    public static function getResourceClassFromType($contentType)
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
    public static function getResourceClassFromFilename($filename)
    {
        $contentType = static::getTypeFromFilename($filename);

        if (empty($contentType) && pathinfo($filename)['extension'] === 'xml') {
            $contentType = 'application/xml';
        }

        return static::getResourceClassFromType($contentType);
    }
}
