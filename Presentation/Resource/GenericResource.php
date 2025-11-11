<?php

namespace Cristal\Presentation\Resource;

use Cristal\Presentation\Exception\InvalidFileNameException;
use Cristal\Presentation\PPTX;
use Cristal\Presentation\ResourceInterface;
use Exception;

class GenericResource implements ResourceInterface
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
    protected $relType;

    /**
     * @var string
     */
    protected $contentType;

    /**
     * @var PPTX
     */
    protected $document;

    /**
     * @var PPTX
     */
    protected $initialDocument;

    /**
     * @var null|string
     */
    protected $customContent;

    protected $hasChange = false;

    /**
     * @var bool Indique si le contenu a été chargé
     */
    protected $contentLoaded = false;

    /**
     * @var string|null Contenu en lazy loading
     */
    protected $lazyContent = null;

    /**
     * @var bool Active le lazy loading
     */
    protected $lazyLoadingEnabled = false;

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
        // Si lazy loading activé et contenu pas encore chargé
        if ($this->lazyLoadingEnabled && !$this->contentLoaded) {
            $this->lazyContent = $this->loadContent();
            $this->contentLoaded = true;
        }

        return $this->customContent ?? $this->lazyContent ?? $this->initialDocument->getArchive()->getFromName($this->getInitialTarget());
    }

    /**
     * Charge le contenu depuis l'archive
     *
     * @return string
     */
    protected function loadContent(): string
    {
        return $this->initialDocument->getArchive()->getFromName($this->getInitialTarget());
    }

    public function setContent(string $content): void
    {
        $this->hasChange = true;
        $this->customContent = $content;
        $this->contentLoaded = true;
        $this->lazyContent = $content;
        $this->document->getArchive()->addFromString($this->getTarget(), $content);
    }

    /**
     * Active le lazy loading pour cette ressource
     *
     * @param bool $enabled
     */
    public function setLazyLoading(bool $enabled): void
    {
        $this->lazyLoadingEnabled = $enabled;
    }

    /**
     * Vérifie si le lazy loading est activé
     *
     * @return bool
     */
    public function isLazyLoadingEnabled(): bool
    {
        return $this->lazyLoadingEnabled;
    }

    /**
     * Décharge le contenu de la mémoire
     * Utile pour libérer de la mémoire sur les grandes ressources
     */
    public function unloadContent(): void
    {
        if ($this->lazyLoadingEnabled && !$this->hasChange) {
            $this->lazyContent = null;
            $this->contentLoaded = false;
        }
    }

    /**
     * Vérifie si le contenu est actuellement chargé en mémoire
     *
     * @return bool
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
        return preg_replace('#([^/])\d+?\.(.*?)$#', '$1{x}.$2', $this->getTarget());
    }

    /**
     * Get initial target file.
     */
    public function getInitialTarget(): string
    {
        return $this->initialTarget;
    }

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

    public function getRelType(): string
    {
        return $this->relType;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getDocument(): PPTX
    {
        return $this->document;
    }

    /**
     * Set a new zip archive for work.
     */
    public function setDocument(PPTX $document): PPTX
    {
        return $this->document = $document;
    }

    /**
     * Check if the current file has been moved.
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
     *  Save current Resource.
     */
    protected function performSave(): void
    {
        $this->hasChange = false;
        $this->document->getArchive()->addFromString($this->getTarget(), $this->getContent());
    }

    /**
     * Save current Resource and syncInitials.
     */
    public function save(): void
    {
        if ($this->isDraft()) {
            $this->performSave();
            $this->syncInitials();
        }
    }

    public function getHashFile(): string
    {
        return md5($this->getContent());
    }

    /**
     * Returns the target path as relative reference from the base path.
     * @see https://github.com/symfony/routing/blob/7da33371d8ecfed6c9d93d87c73749661606f803/Generator/UrlGenerator.php#L336
     */
    public function getRelativeTarget(string $relPath): string
    {
        $basePath = $relPath;
        $targetPath = $this->getTarget();
        if ($basePath === $targetPath) {
            return '';
        }

        $sourceDirs = explode('/',
            isset($basePath[0]) && strpos($basePath, '/') === 0 ? substr($basePath, 1) : $basePath);
        $targetDirs = explode('/',
            isset($targetPath[0]) && strpos($targetPath, '/') === 0 ? substr($targetPath, 1) : $targetPath);
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
        return '' === $path
        || strpos($path, '/') === 0
        || (
            false !== ($colonPos = strpos($path, ':')) &&
            ($colonPos < ($slashPos = strpos($path, '/')) || false === $slashPos)
        ) ? './' . $path : $path;
    }
}
