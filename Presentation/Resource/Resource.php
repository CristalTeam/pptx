<?php

namespace Cpro\Presentation\Resource;

use ZipArchive;

class Resource
{
    /**
     * @var string
     */
    protected $initialTarget;

    /**
     * @var string
     */
    protected $target;

    /**
     * @var string
     */
    protected $type;

    /**
     * @var string
     */
    protected $relativeFile;

    /**
     * @var ZipArchive
     */
    protected $zipArchive;
    /**
     * @var ZipArchive
     */
    protected $initalZipArchive;

    const SLIDE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide';

    public function __construct($target, $type, $relativeFile = '', ?ZipArchive $zipArchive = null)
    {
        $this->initialTarget = $this->target = $target;
        $this->type = $type;
        $this->relativeFile = $relativeFile;
        $this->zipArchive = $this->initalZipArchive = $zipArchive;
    }

    /**
     * @param            $resourceNode
     * @param            $relativeFile
     * @param ZipArchive $zipArchive
     * @return static
     */
    public static function createFromNode($resourceNode, $relativeFile, ZipArchive $zipArchive)
    {
        return new static((string) $resourceNode['Target'], (string) $resourceNode['Type'], $relativeFile, $zipArchive);
    }

    public function getContent()
    {
        return $this->initalZipArchive->getFromName($this->getInitialAbsoluteTarget());
    }

    public function resolveAbsolutePath($path)
    {
        $parts = array_filter(explode('/', $path), 'strlen');
        $absolutes = [];
        foreach ($parts as $part) {
            if ('.' == $part) {
                continue;
            }
            if ('..' == $part) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }
        return implode('/', $absolutes);
    }

    public function getAbsoluteTarget()
    {
        $path = dirname($this->relativeFile).'/'.ltrim($this->getTarget(), '/');
        return $this->resolveAbsolutePath($path);
    }

    public function getInitialAbsoluteTarget()
    {
        $path = dirname($this->relativeFile).'/'.ltrim($this->getInitialTarget(), '/');
        return $this->resolveAbsolutePath($path);
    }

    public function getPatternPath()
    {
        return preg_replace('#([^/])[0-9]+?\.(.*?)$#', '$1{x}.$2', $this->getAbsoluteTarget());
    }

    //public function

    /**
     * @return string
     */
    public function getInitialTarget()
    {
        return $this->initialTarget;
    }

    //public function

    /**
     * @return string
     */
    public function getTarget()
    {
        return $this->target;
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

        $this->target = dirname($this->target).'/'.$filename;

        return $this;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    public function setZipArchive(ZipArchive $zipArchive)
    {
        return $this->zipArchive = $zipArchive;
    }
}