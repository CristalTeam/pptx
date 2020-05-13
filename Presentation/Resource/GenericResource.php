<?php

namespace Cpro\Presentation\Resource;

use Cpro\Presentation\ContentType;
use Cpro\Presentation\Exception\InvalidFileNameException;
use Exception;
use ZipArchive;

class GenericResource
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
     * @var ZipArchive
     */
    protected $zipArchive;

    /**
     * @var ZipArchive
     */
    protected $initialZipArchive;

    /**
     * @var null|string
     */
    protected $customContent;

    /**
     * Resource constructor.
     */
    public function __construct(string $target, string $type, ZipArchive $zipArchive)
    {
        $this->initialTarget = $this->target = $target;
        $this->type = $type;
        $this->zipArchive = $this->initialZipArchive = $zipArchive;
    }

    /**
     * Create an instance of Resource based on a XML rels node.
     */
    public static function createFromNode(string $target, string $type, ZipArchive $archive): GenericResource
    {
        $target = static::resolveAbsolutePath($target);

        $className = ContentType::getResourceClassFromType($type);
        if ($className === self::class) {
            $className = ContentType::getResourceClassFromFilename($target);
        }

        return new $className($target, $type, $archive);
    }

    /**
     * Get current content file from initial zip archive.
     */
    public function getContent(): string
    {
        return $this->customContent ?? $this->initialZipArchive->getFromName($this->getInitialTarget());
    }

    public function setContent(string $content): void
    {
        $this->customContent = $content;
        $this->zipArchive->addFromString($this->getTarget(), $content);
    }

    /**
     * Calculate an absolute path from a relative path.
     */
    public static function resolveAbsolutePath(string $path): string
    {
        $parts = array_filter(explode('/', $path), 'strlen');
        $absolutes = [];
        foreach ($parts as $part) {
            if ('.' === $part) {
                continue;
            }
            if ('..' === $part) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }
        return implode('/', $absolutes);
    }

    /**
     * Get pattern from filename.
     * Example, it returns 'ppt/slides/slide{x}.xml' for a filename like this ppt/slides/slide1.xml
     *
     * @return null|string|string[]
     */
    public function getPatternPath()
    {
        return preg_replace('#([^/])[0-9]+?\.(.*?)$#', '$1{x}.$2', $this->getTarget());
    }

    /**
     * Get initial target file.
     */
    public function getInitialTarget(): string
    {
        return $this->initialTarget;
    }

    /**
     * Get current target file.
     */
    public function getTarget(): string
    {
        return $this->target;
    }

    /**
     * Rename current Resource.
     * @throws Exception
     */
    public function rename(string $filename): self
    {
        if (preg_match('#/#', $filename)) {
            throw new InvalidFileNameException('Filename can not be a a path.');
        }

        $this->target = dirname($this->target) . '/' . $filename;

        return $this;
    }

    /**
     * Return current ContentType value.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set a new zip archive for work.
     *
     * @param ZipArchive $zipArchive
     * @return ZipArchive
     */
    public function setZipArchive(ZipArchive $zipArchive): ZipArchive
    {
        return $this->zipArchive = $zipArchive;
    }

    /**
     * Check if the current file has been moved.
     *
     * @return bool
     */
    public function isDraft(): bool
    {
        return $this->initialTarget !== $this->target || $this->initialZipArchive !== $this->zipArchive;
    }

    /**
     * Reset initials with current values.
     */
    protected function syncInitials(): void
    {
        $this->initialZipArchive = $this->zipArchive;
        $this->initialTarget = $this->target;
    }

    /**
     *  Save current Resource.
     */
    protected function performSave(): void
    {
        $this->zipArchive->addFromString($this->getTarget(), $this->getContent());
    }

    /**
     * Save current Resource and syncInitials.
     */
    public function save(): void
    {
        $this->performSave();
        $this->syncInitials();
    }

    public function getKey(): string
    {
        return md5($this->initialZipArchive->filename . $this->getContent());
    }

    public function getRelativeTarget(string $relPath): string
    {
        $basePath = $relPath;
        $targetPath = $this->getTarget();
        if ($basePath === $targetPath) {
            return '';
        }

        $sourceDirs = explode('/', isset($basePath[0]) && strpos($basePath, '/') === 0 ? substr($basePath, 1) : $basePath);
        $targetDirs = explode('/', isset($targetPath[0]) && strpos($targetPath, '/') === 0 ? substr($targetPath, 1) : $targetPath);
        array_pop($sourceDirs);
        $targetFile = array_pop($targetDirs);

        foreach ($sourceDirs as $i => $dir) {
            if (isset($targetDirs[$i]) && $dir === $targetDirs[$i]) {
                unset($sourceDirs[$i], $targetDirs[$i]);
            } else {
                break;
            }
        }

        $targetDirs[] = $targetFile;
        $path = str_repeat('../', count($sourceDirs)) . implode('/', $targetDirs);

        return '' === $path ||
        strpos($path, '/') === 0 ||
        (
            false !== ($colonPos = strpos($path, ':')) &&
            ($colonPos < ($slashPos = strpos($path, '/')) || false === $slashPos)
        ) ? './' . $path : $path;
    }
}
