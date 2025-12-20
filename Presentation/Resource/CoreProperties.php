<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * Core properties resource class for Dublin Core metadata.
 *
 * @see ECMA-376 Part 2, Section 11 - Core Properties
 */
class CoreProperties extends XmlResource
{
    /**
     * Property namespace mappings.
     */
    private const PROPERTY_NAMESPACES = [
        'title' => 'dc',
        'subject' => 'dc',
        'creator' => 'dc',
        'description' => 'dc',
        'keywords' => 'cp',
        'lastModifiedBy' => 'cp',
        'revision' => 'cp',
        'created' => 'dcterms',
        'modified' => 'dcterms',
        'category' => 'cp',
        'contentStatus' => 'cp',
        'version' => 'cp',
    ];

    /**
     * Get all core properties.
     *
     * @return array<string, string|null>
     */
    public function getProperties(): array
    {
        return [
            'title' => $this->getProperty('title'),
            'subject' => $this->getProperty('subject'),
            'creator' => $this->getProperty('creator'),
            'keywords' => $this->getProperty('keywords'),
            'description' => $this->getProperty('description'),
            'lastModifiedBy' => $this->getProperty('lastModifiedBy'),
            'revision' => $this->getProperty('revision'),
            'created' => $this->getProperty('created'),
            'modified' => $this->getProperty('modified'),
            'category' => $this->getProperty('category'),
        ];
    }

    /**
     * Get a specific property.
     *
     * @param string $name Property name (without namespace prefix)
     * @return string|null Property value or null if not found
     */
    public function getProperty(string $name): ?string
    {
        $this->registerXPathNamespaces();

        $prefix = self::PROPERTY_NAMESPACES[$name] ?? 'cp';
        $xpath = "//{$prefix}:{$name}";

        $nodes = $this->content->xpath($xpath);

        return ($nodes !== false && !empty($nodes)) ? (string) $nodes[0] : null;
    }

    /**
     * Set a core property.
     *
     * @param string $name Property name (without namespace prefix)
     * @param string $value Property value
     */
    public function setProperty(string $name, string $value): void
    {
        $this->registerXPathNamespaces();

        $prefix = self::PROPERTY_NAMESPACES[$name] ?? 'cp';
        $xpath = "//{$prefix}:{$name}";

        $nodes = $this->content->xpath($xpath);

        if ($nodes !== false && !empty($nodes)) {
            $domNode = dom_import_simplexml($nodes[0]);
            $domNode->nodeValue = $value;
            $this->save();
        }
    }

    /**
     * Get the document title.
     */
    public function getTitle(): ?string
    {
        return $this->getProperty('title');
    }

    /**
     * Set the document title.
     */
    public function setTitle(string $title): void
    {
        $this->setProperty('title', $title);
    }

    /**
     * Get the document subject.
     */
    public function getSubject(): ?string
    {
        return $this->getProperty('subject');
    }

    /**
     * Set the document subject.
     */
    public function setSubject(string $subject): void
    {
        $this->setProperty('subject', $subject);
    }

    /**
     * Get the document creator (author).
     */
    public function getCreator(): ?string
    {
        return $this->getProperty('creator');
    }

    /**
     * Set the document creator (author).
     */
    public function setCreator(string $creator): void
    {
        $this->setProperty('creator', $creator);
    }

    /**
     * Get the document keywords.
     */
    public function getKeywords(): ?string
    {
        return $this->getProperty('keywords');
    }

    /**
     * Set the document keywords.
     */
    public function setKeywords(string $keywords): void
    {
        $this->setProperty('keywords', $keywords);
    }

    /**
     * Get the document description.
     */
    public function getDescription(): ?string
    {
        return $this->getProperty('description');
    }

    /**
     * Set the document description.
     */
    public function setDescription(string $description): void
    {
        $this->setProperty('description', $description);
    }

    /**
     * Get the last modified by user.
     */
    public function getLastModifiedBy(): ?string
    {
        return $this->getProperty('lastModifiedBy');
    }

    /**
     * Set the last modified by user.
     */
    public function setLastModifiedBy(string $user): void
    {
        $this->setProperty('lastModifiedBy', $user);
    }

    /**
     * Get the document revision number.
     */
    public function getRevision(): ?string
    {
        return $this->getProperty('revision');
    }

    /**
     * Increment the revision number.
     */
    public function incrementRevision(): void
    {
        $current = (int) ($this->getRevision() ?? '0');
        $this->setProperty('revision', (string) ($current + 1));
    }

    /**
     * Get the created date.
     */
    public function getCreated(): ?string
    {
        return $this->getProperty('created');
    }

    /**
     * Get the modified date.
     */
    public function getModified(): ?string
    {
        return $this->getProperty('modified');
    }

    /**
     * Update modification timestamp.
     */
    public function touch(): void
    {
        $this->setProperty('modified', date('c'));
    }

    /**
     * Get the document category.
     */
    public function getCategory(): ?string
    {
        return $this->getProperty('category');
    }

    /**
     * Set the document category.
     */
    public function setCategory(string $category): void
    {
        $this->setProperty('category', $category);
    }

    /**
     * Register XPath namespaces for querying.
     */
    private function registerXPathNamespaces(): void
    {
        $namespaces = $this->getNamespaces();

        foreach ($namespaces as $prefix => $uri) {
            if ($prefix !== '') {
                $this->content->registerXPathNamespace($prefix, $uri);
            }
        }

        // Ensure Dublin Core namespaces are registered
        if (!isset($namespaces['dc'])) {
            $this->content->registerXPathNamespace(
                'dc',
                'http://purl.org/dc/elements/1.1/'
            );
        }
        if (!isset($namespaces['dcterms'])) {
            $this->content->registerXPathNamespace(
                'dcterms',
                'http://purl.org/dc/terms/'
            );
        }
        if (!isset($namespaces['cp'])) {
            $this->content->registerXPathNamespace(
                'cp',
                'http://schemas.openxmlformats.org/package/2006/metadata/core-properties'
            );
        }
    }
}