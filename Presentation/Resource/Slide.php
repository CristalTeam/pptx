<?php

declare(strict_types=1);

namespace Cristal\Presentation\Resource;

use Closure;
use Generator;

/**
 * Slide resource class for handling slides in a PPTX archive.
 */
class Slide extends XmlResource
{
    /**
     * Template separator for nested data access.
     */
    protected const TEMPLATE_SEPARATOR = '.';

    /**
     * Template name for table row replacement.
     */
    protected const TABLE_ROW_TEMPLATE_NAME = 'replaceByNewRow';

    /**
     * Find data recursively using dot notation.
     *
     * @param mixed $key The key to find
     * @param mixed $data The data to search in
     * @param string $default Default value if not found
     * @return string The found value or default
     */
    protected function findDataRecursively(mixed $key, mixed $data, string $default = ''): string
    {
        foreach (explode(self::TEMPLATE_SEPARATOR, (string) $key) as $segment) {
            if (isset($data[$segment])) {
                $data = $data[$segment];
            } else {
                $data = $default;
            }
        }

        return (string) $data;
    }

    /**
     * Fill data to the slide using template placeholders.
     *
     * @param array|Closure $data Data to fill
     */
    public function template(array|Closure $data): void
    {
        if (!$data instanceof Closure) {
            $data = function (array $matches) use ($data): string {
                return $this->findDataRecursively($matches['needle'], $data);
            };
        }

        $xmlString = $this->replaceNeedle($this->getContent(), $data);

        $this->setContent($xmlString);

        $this->save();
    }

    /**
     * Replace template needles with data using a callback.
     *
     * @param string $source Source XML string
     * @param Closure $callback Callback to generate replacement
     * @return string Processed XML string
     */
    protected function replaceNeedle(string $source, Closure $callback): string
    {
        $sanitizer = static function (array $matches) use ($callback): string {
            return htmlspecialchars((string) $callback($matches));
        };

        return preg_replace_callback(
            '/({{)((<(.*?)>)+)?(?P<needle>.*?)((<(.*?)>)+)?(}})/mi',
            $sanitizer,
            $source
        );
    }

    /**
     * Fill table data in the slide.
     *
     * @param Closure $data Data provider closure
     * @param Closure|null $finder Optional finder closure
     */
    public function table(Closure $data, ?Closure $finder = null): void
    {
        if ($finder === null) {
            $finder = function (string $needle, array $row): string {
                return $this->findDataRecursively($needle, $row);
            };
        }

        $tables = $this->content->xpath('//a:tbl/../../../p:nvGraphicFramePr/p:cNvPr');
        foreach ($tables as $table) {
            $tableId = (string) $table->attributes()['name'];

            // Try to select the second table row which must be the rows to be copied & templated.
            // If the row is not found, it means we only have a header to our table, so we can skip it.
            $tableRow = $this->content->xpath("//p:cNvPr[@name='$tableId']/../..//a:tr")[1] ?? null;
            if ($tableRow === null) {
                continue;
            }

            $table = $tableRow->xpath('..')[0];
            $rows = $data($tableId);
            if (!$rows) {
                continue;
            }

            foreach ($rows as $index => $row) {
                $table->addChild(self::TABLE_ROW_TEMPLATE_NAME . $index);
            }

            $xml = preg_replace_callback(
                '/<([^>]+:?' . self::TABLE_ROW_TEMPLATE_NAME . '([\d])+\/>?)/',
                function (array $matches) use ($tableRow, $rows, $finder): string {
                    [, , $rowId] = $matches;

                    return $this->replaceNeedle(
                        $tableRow->asXML(),
                        static function (array $matches) use ($rows, $rowId, $finder): string {
                            return $finder($matches['needle'], $rows[$rowId]);
                        }
                    );
                },
                $this->content->asXML()
            );

            $this->setContent(str_replace($tableRow->asXML(), '', $xml));
        }

        $this->save();
    }

    /**
     * Update the images in the slide.
     *
     * @param array|Closure $data Data provider (key should match descr attribute, value is raw image content)
     */
    public function images(array|Closure $data): void
    {
        if (!$data instanceof Closure) {
            $data = static function (string $key) use ($data): ?string {
                return $data[$key] ?? null;
            };
        }

        foreach ($this->getTemplateImages() as $id => $key) {
            $content = $data($key);
            $resource = $this->getResource($id);
            if ($content !== null && $resource !== null) {
                $resource->setContent($content);
            }
        }
    }

    /**
     * Get the image identifiers capable of being templated.
     *
     * @return Generator<string, string>
     */
    public function getTemplateImages(): Generator
    {
        $nodes = $this->content->xpath('//p:pic');

        foreach ($nodes as $node) {
            $id = (string) $node->xpath('p:blipFill/a:blip/@r:embed')[0]->embed;
            $key = $node->xpath('p:nvPicPr/p:cNvPr/@descr');
            if ($key && isset($key[0]) && !empty($key[0]->descr)) {
                yield $id => (string) $key[0]->descr;
            }
        }
    }

    /**
     * Get the speaker notes for this slide.
     *
     * @return NoteSlide|null The note slide if exists, null otherwise
     */
    public function getNotes(): ?NoteSlide
    {
        foreach ($this->getResources() as $resource) {
            if ($resource instanceof NoteSlide) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * Check if this slide has speaker notes with content.
     */
    public function hasNotes(): bool
    {
        $notes = $this->getNotes();

        return $notes !== null && $notes->hasContent();
    }

    /**
     * Get the speaker notes text content.
     *
     * @return string|null Notes text or null if no notes exist
     */
    public function getNotesText(): ?string
    {
        $notes = $this->getNotes();

        return $notes?->getTextContent();
    }

    /**
     * Set the speaker notes text content.
     *
     * @param string $text Text to set as notes
     */
    public function setNotesText(string $text): void
    {
        $notes = $this->getNotes();

        if ($notes !== null) {
            $notes->setTextContent($text);
        }
    }
}
