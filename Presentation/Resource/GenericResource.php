<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

use Cristal\Presentation\Exception\InvalidFileNameException;
use Cristal\Presentation\PPTX;
use Cristal\Presentation\ResourceInterface;

/**
 * Generic resource class for handling files in a PPTX archive.
 */
class GenericResource implements ResourceInterface
{
    protected string $initialTarget;

    protected string $target;

    protected string $relType;

    protected string $contentType;

    protected PPTX $document;

    protected PPTX $initialDocument;

    protected ?string $customContent = null;

    protected bool $hasChange = false;

    /**
     * Indicates if the content has been loaded.
     */
    protected bool $contentLoaded = false;

    /**
     * Content in lazy loading mode.
     */
    protected ?string $lazyContent = null;

    /**
     * Enable lazy loading.
     */
    protected bool $lazyLoadingEnabled = false;

    /**
     * Resource constructor.
     */
    public function __construct(string $target, string $relType, string $contentType, PPTX $document)
    {
        $this->initialTarget = $this->target = $target;
        $this->relType = $relType;
        $this->document = $this->initialDocument = $document;
        $this->contentType = $contentType;
    }

    /**
     * Get current content file from initial zip archive.
     */
    public function getContent(): string
    {
        // If lazy loading enabled and content not yet loaded
        if ($this->lazyLoadingEnabled && !$this->contentLoaded) {
            $this->lazyContent = $this->loadContent();
            $this->contentLoaded = true;
        }

        return $this->customContent ?? $this->lazyContent ?? $this->initialDocument->getArchive()->getFromName($this->getInitialTarget());
    }

    /**
     * Load content from the archive.
     */
    protected function loadContent(): string
    {
        return $this->initialDocument->getArchive()->getFromName($this->getInitialTarget());
    }

    /**
     * Set the content of this resource.
     */
    public function setContent(string $content): void
    {
        $this->hasChange = true;
        $this->customContent = $content;
        $this->contentLoaded = true;
        $this->lazyContent = $content;
        $this->document->getArchive()->addFromString($this->getTarget(), $content);
    }

    /**
     * Enable lazy loading for this resource.
     */
    public function setLazyLoading(bool $enabled): void
    {
        $this->lazyLoadingEnabled = $enabled;
    }

    /**
     * Check if lazy loading is enabled.
     */
    public function isLazyLoadingEnabled(): bool
    {
        return $this->lazyLoadingEnabled;
    }

    /**
     * Unload content from memory.
     * Useful for freeing memory on large resources.
     */
    public function unloadContent(): void
    {
        if ($this->lazyLoadingEnabled && !$this->hasChange) {
            $this->lazyContent = null;
            $this->contentLoaded = false;
        }
    }

    /**
     * Check if content is currently loaded in memory.
     */
    public function isContentLoaded(): bool
    {
        return $this->contentLoaded || $this->customContent !== null;
    }

    /**
     * Calculate an absolute path from a relative path.
     */
    public static function resolveAbsolutePath(string $path): string
    {
        $parts = array_filter(explode('/', $path), static fn (string $part): bool => $part !== '');
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
     * Example: returns 'ppt/slides/slide{x}.xml' for a filename like ppt/slides/slide1.xml
     */
    public function getPatternPath(): ?string
    {
        return preg_replace('#([^/])\d+?\.(.*?)$#', '$1{x}.$2', $this->getTarget());
    }

    /**
     * Get initial target file.
     */
    public function getInitialTarget(): string
    {
        return $this->initialTarget;
    }

    /**
     * Get current target file path.
     */
    public function getTarget(): string
    {
        return $this->target;
    }

    /**
     * Rename current Resource.
     *
     * @throws InvalidFileNameException
     */
    public function rename(string $filename): self
    {
        if (preg_match('#/#', $filename)) {
            throw new InvalidFileNameException('Filename can not be a path.');
        }

        $this->target = dirname($this->target) . '/' . $filename;

        return $this;
    }

    /**
     * Get the relationship type.
     */
    public function getRelType(): string
    {
        return $this->relType;
    }

    /**
     * Get the content type.
     */
    public function getContentType(): string
    {
        return $this->contentType;
    }

    /**
     * Get the document this resource belongs to.
     */
    public function getDocument(): PPTX
    {
        return $this->document;
    }

    /**
     * Set a new document for this resource.
     */
    public function setDocument(PPTX $document): PPTX
    {
        return $this->document = $document;
    }

    /**
     * Check if the current file has been moved or modified.
     */
    public function isDraft(): bool
    {
        return $this->initialTarget !== $this->target || $this->initialDocument !== $this->document || $this->hasChange;
    }

    /**
     * Reset initials with current values.
     */
    protected function syncInitials(): void
    {
        $this->initialDocument = $this->document;
        $this->initialTarget = $this->target;
    }

    /**
     * Perform the actual save operation.
     */
    protected function performSave(): void
    {
        $this->hasChange = false;
        $this->document->getArchive()->addFromString($this->getTarget(), $this->getContent());
    }

    /**
     * Save current Resource and sync initials.
     */
    public function save(): void
    {
        if ($this->isDraft()) {
            $this->performSave();
            $this->syncInitials();
        }
    }

    /**
     * Get the hash of the file content.
     */
    public function getHashFile(): string
    {
        return md5($this->getContent());
    }

    /**
     * Returns the target path as relative reference from the base path.
     *
     * @see https://github.com/symfony/routing/blob/7da33371d8ecfed6c9d93d87c73749661606f803/Generator/UrlGenerator.php#L336
     */
    public function getRelativeTarget(string $relPath): string
    {
        $basePath = $relPath;
        $targetPath = $this->getTarget();
        if ($basePath === $targetPath) {
            return '';
        }

        $sourceDirs = explode(
            '/',
            isset($basePath[0]) && strpos($basePath, '/') === 0 ? substr($basePath, 1) : $basePath
        );
        $targetDirs = explode(
            '/',
            isset($targetPath[0]) && strpos($targetPath, '/') === 0 ? substr($targetPath, 1) : $targetPath
        );
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

        // A reference to the same base directory or an empty subdirectory must be prefixed with "./".
        // This also applies to a segment with a colon character (e.g., "file:colon") that cannot be used
        // as the first segment of a relative-path reference, as it would be mistaken for a scheme name
        // (see http://tools.ietf.org/html/rfc3986#section-4.2).
        if ($path === '' || str_starts_with($path, '/')) {
            return './' . $path;
        }

        $colonPos = strpos($path, ':');
        $slashPos = strpos($path, '/');
        if ($colonPos !== false && ($slashPos === false || $colonPos < $slashPos)) {
            return './' . $path;
        }

        return $path;
    }
}
