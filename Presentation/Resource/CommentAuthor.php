<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * CommentAuthor resource class for handling comment authors.
 *
 * @see ECMA-376 Part 1, Section 13.3.1 - Comment Authors Part
 */
class CommentAuthor extends XmlResource
{
    /**
     * Get all authors.
     *
     * @return array<int, array{id: int, name: string, initials: string, lastIdx: int, clrIdx: int}>
     */
    public function getAuthors(): array
    {
        $authors = [];
        $this->registerXPathNamespaces();

        $authorNodes = $this->content->xpath('//p:cmAuthor');

        if ($authorNodes === false) {
            return [];
        }

        foreach ($authorNodes as $author) {
            $authors[] = [
                'id' => (int) ($author['id'] ?? 0),
                'name' => (string) ($author['name'] ?? ''),
                'initials' => (string) ($author['initials'] ?? ''),
                'lastIdx' => (int) ($author['lastIdx'] ?? 0),
                'clrIdx' => (int) ($author['clrIdx'] ?? 0),
            ];
        }

        return $authors;
    }

    /**
     * Get author by ID.
     *
     * @param int $id Author ID
     * @return array{id: int, name: string, initials: string, lastIdx: int, clrIdx: int}|null
     */
    public function getAuthorById(int $id): ?array
    {
        foreach ($this->getAuthors() as $author) {
            if ($author['id'] === $id) {
                return $author;
            }
        }

        return null;
    }

    /**
     * Get author by name.
     *
     * @param string $name Author name
     * @return array{id: int, name: string, initials: string, lastIdx: int, clrIdx: int}|null
     */
    public function getAuthorByName(string $name): ?array
    {
        foreach ($this->getAuthors() as $author) {
            if ($author['name'] === $name) {
                return $author;
            }
        }

        return null;
    }

    /**
     * Get authors count.
     */
    public function count(): int
    {
        return count($this->getAuthors());
    }

    /**
     * Add or get an author.
     * If an author with the same name exists, returns their ID.
     * Otherwise, creates a new author and returns the new ID.
     *
     * @param string $name Author name
     * @param string $initials Author initials (auto-generated if empty)
     * @return int Author ID
     */
    public function addAuthor(string $name, string $initials = ''): int
    {
        // Check if author exists
        $existingAuthor = $this->getAuthorByName($name);
        if ($existingAuthor !== null) {
            return $existingAuthor['id'];
        }

        // Generate initials if not provided
        if ($initials === '') {
            $initials = $this->generateInitials($name);
        }

        $this->registerXPathNamespaces();
        $ns = $this->getNamespaces();

        $cmAuthorLstNodes = $this->content->xpath('//p:cmAuthorLst');
        if ($cmAuthorLstNodes === false || empty($cmAuthorLstNodes)) {
            return -1;
        }

        $cmAuthorLst = $cmAuthorLstNodes[0];
        $newId = $this->count();

        $author = $cmAuthorLst->addChild(
            'cmAuthor',
            '',
            $ns['p'] ?? 'http://schemas.openxmlformats.org/presentationml/2006/main'
        );
        $author->addAttribute('id', (string) $newId);
        $author->addAttribute('name', $name);
        $author->addAttribute('initials', $initials);
        $author->addAttribute('lastIdx', '1');
        $author->addAttribute('clrIdx', (string) $newId);

        $this->save();

        return $newId;
    }

    /**
     * Update author's last comment index.
     *
     * @param int $authorId Author ID
     * @param int $lastIdx New last index
     */
    public function updateLastIdx(int $authorId, int $lastIdx): void
    {
        $this->registerXPathNamespaces();

        $authorNodes = $this->content->xpath("//p:cmAuthor[@id='$authorId']");

        if ($authorNodes !== false && !empty($authorNodes)) {
            $domNode = dom_import_simplexml($authorNodes[0]);
            $domNode->setAttribute('lastIdx', (string) $lastIdx);
            $this->save();
        }
    }

    /**
     * Generate initials from a name.
     *
     * @param string $name Full name
     * @return string Initials (max 3 characters)
     */
    private function generateInitials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));

        if ($parts === false || empty($parts)) {
            return 'XX';
        }

        $initials = '';
        foreach ($parts as $part) {
            if (!empty($part)) {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
        }

        return mb_substr($initials, 0, 3);
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

        // Ensure common namespaces are registered
        if (!isset($namespaces['p'])) {
            $this->content->registerXPathNamespace(
                'p',
                'http://schemas.openxmlformats.org/presentationml/2006/main'
            );
        }
    }
}