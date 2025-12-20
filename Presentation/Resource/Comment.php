<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

/**
 * Comment resource class for handling presentation comments.
 *
 * @see ECMA-376 Part 1, Section 13.3.2 - Comments Part
 */
class Comment extends XmlResource
{
    /**
     * Get all comments.
     *
     * @return array<int, array{authorId: int, text: string, date: string, position: array{x: int, y: int}}>
     */
    public function getComments(): array
    {
        $comments = [];
        $this->registerXPathNamespaces();

        $cmNodes = $this->content->xpath('//p:cm');

        if ($cmNodes === false) {
            return [];
        }

        foreach ($cmNodes as $cm) {
            $textNode = $cm->xpath('p:text');
            $posNode = $cm->xpath('p:pos');

            $comments[] = [
                'authorId' => (int) ($cm['authorId'] ?? 0),
                'text' => $textNode !== false && !empty($textNode) ? (string) $textNode[0] : '',
                'date' => (string) ($cm['dt'] ?? ''),
                'idx' => (int) ($cm['idx'] ?? 0),
                'position' => [
                    'x' => $posNode !== false && !empty($posNode) ? (int) ($posNode[0]['x'] ?? 0) : 0,
                    'y' => $posNode !== false && !empty($posNode) ? (int) ($posNode[0]['y'] ?? 0) : 0,
                ],
            ];
        }

        return $comments;
    }

    /**
     * Get comments count.
     */
    public function count(): int
    {
        return count($this->getComments());
    }

    /**
     * Check if there are any comments.
     */
    public function hasComments(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Get comments by author ID.
     *
     * @param int $authorId The author ID to filter by
     * @return array<int, array{authorId: int, text: string, date: string, position: array{x: int, y: int}}>
     */
    public function getCommentsByAuthor(int $authorId): array
    {
        return array_filter($this->getComments(), static function (array $comment) use ($authorId): bool {
            return $comment['authorId'] === $authorId;
        });
    }

    /**
     * Add a new comment.
     *
     * @param int $authorId Author ID
     * @param string $text Comment text
     * @param int $x X position (in EMUs)
     * @param int $y Y position (in EMUs)
     */
    public function addComment(int $authorId, string $text, int $x = 0, int $y = 0): void
    {
        $this->registerXPathNamespaces();
        $ns = $this->getNamespaces();

        $cmLstNodes = $this->content->xpath('//p:cmLst');
        if ($cmLstNodes === false || empty($cmLstNodes)) {
            return;
        }

        $cmLst = $cmLstNodes[0];
        $newIdx = $this->count() + 1;

        $cm = $cmLst->addChild('cm', '', $ns['p'] ?? 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $cm->addAttribute('authorId', (string) $authorId);
        $cm->addAttribute('dt', date('c'));
        $cm->addAttribute('idx', (string) $newIdx);

        $pos = $cm->addChild('pos', '', $ns['p'] ?? 'http://schemas.openxmlformats.org/presentationml/2006/main');
        $pos->addAttribute('x', (string) $x);
        $pos->addAttribute('y', (string) $y);

        $cm->addChild('text', htmlspecialchars($text), $ns['p'] ?? 'http://schemas.openxmlformats.org/presentationml/2006/main');

        $this->save();
    }

    /**
     * Remove a comment by index.
     *
     * @param int $idx The comment index to remove
     * @return bool True if removed, false if not found
     */
    public function removeComment(int $idx): bool
    {
        $this->registerXPathNamespaces();

        $cmNodes = $this->content->xpath("//p:cm[@idx='$idx']");

        if ($cmNodes === false || empty($cmNodes)) {
            return false;
        }

        $domNode = dom_import_simplexml($cmNodes[0]);
        if ($domNode->parentNode !== null) {
            $domNode->parentNode->removeChild($domNode);
            $this->save();
            return true;
        }

        return false;
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