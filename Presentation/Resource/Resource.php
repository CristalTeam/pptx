<?php

namespace Cpro\Presentation\Resource;

use Cpro\Presentation\ContentType;
use Cpro\Presentation\Exception\InvalidFileNameException;
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

    /**
     * Resource constructor.
     *
     * @param                 $target
     * @param                 $type
     * @param string          $relativeFile
     * @param null|ZipArchive $zipArchive
     */
    public function __construct($target, $type, $relativeFile = '', ?ZipArchive $zipArchive = null)
    {
        $this->initialTarget = $this->target = $target;
        $this->type = $type;
        $this->relativeFile = $relativeFile;
        $this->zipArchive = $this->initalZipArchive = $zipArchive;
    }

    /**
     * Create an instance of Resource based on a XML rels node.
     *
     * @param            $resourceNode
     * @param            $relativeFile
     * @param ZipArchive $zipArchive
     * @return static
     */
    public static function createFromNode($resourceNode, $relativeFile, ZipArchive $zipArchive)
    {
        $className = ContentType::getResourceClassFromFilename((string) $resourceNode['Target']);
        return new $className((string) $resourceNode['Target'], (string) $resourceNode['Type'], $relativeFile, $zipArchive);
    }

    /**
     * Get current content file from initial zip archive.
     *
     * @return string
     */
    public function getContent()
    {
        return $this->initalZipArchive->getFromName($this->getInitialAbsoluteTarget());
    }

    /**
     * @param string $content
     *
     * @return self
     */
    public function setContent(string $content)
    {
        $this->zipArchive->addFromString($this->getAbsoluteTarget(), $content);

        return $this;
    }

    /**
     * Calculate an absolute path from a relative path.
     *
     * @param $path
     * @return string
     */
    public static function resolveAbsolutePath($path)
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

    /**
     * Get absolute target, calculate on current target.
     *
     * @return string
     */
    public function getAbsoluteTarget()
    {
        $path = dirname($this->relativeFile).'/'.ltrim($this->getTarget(), '/');
        return static::resolveAbsolutePath($path);
    }

    /**
     * Get initial absolute target, calculate on current target.
     *
     * @return string
     */
    public function getInitialAbsoluteTarget()
    {
        $path = dirname($this->relativeFile).'/'.ltrim($this->getInitialTarget(), '/');
        return static::resolveAbsolutePath($path);
    }

    /**
     * Get pattern from filename.
     * Example, it returns 'ppt/slides/slide{x}.xml' for a filename like this ppt/slides/slide1.xml
     *
     * @return null|string|string[]
     */
    public function getPatternPath()
    {
        return preg_replace('#([^/])[0-9]+?\.(.*?)$#', '$1{x}.$2', $this->getAbsoluteTarget());
    }

    /**
     * Get initial target file.
     *
     * @return string
     */
    public function getInitialTarget()
    {
        return $this->initialTarget;
    }

    /**
     * Get current target file.
     *
     * @return string
     */
    public function getTarget()
    {
        return $this->target;
    }

    /**
     * Rename current Resource.
     *
     * @param $filename
     * @return $this
     * @throws \Exception
     */
    public function rename($filename)
    {
        if (preg_match('#/#', $filename)) {
            throw new InvalidFileNameException('Filename can not be a a path.');
        }

        $this->target = dirname($this->target).'/'.$filename;

        return $this;
    }

    /**
     * Return current ContentType value.
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Set a new zip archive for work.
     *
     * @param ZipArchive $zipArchive
     * @return ZipArchive
     */
    public function setZipArchive(ZipArchive $zipArchive)
    {
        return $this->zipArchive = $zipArchive;
    }

    /**
     * Check if the current file has been moved.
     *
     * @return bool
     */
    public function isDraft()
    {
        return $this->initialTarget !== $this->target || $this->initalZipArchive !== $this->zipArchive;
    }

    /**
     * Reset initials with current values.
     */
    protected function syncInitials()
    {
        $this->initalZipArchive = $this->zipArchive;
        $this->initialTarget = $this->target;
    }

    /**
     *  Save current Resource.
     */
    protected function performSave()
    {
        $this->zipArchive->addFromString($this->getAbsoluteTarget(), $this->getContent());
    }

    /**
     * Save current Resource and syncInitials.
     */
    public function save()
    {
        $this->performSave();
        $this->syncInitials();
    }
}
