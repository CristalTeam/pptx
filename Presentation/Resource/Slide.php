<?php

namespace Cristal\Presentation\Resource;

use Closure;
use Generator;

class Slide extends XmlResource
{
    /**
     * @var string
     */
    protected const TEMPLATE_SEPARATOR = '.';

    /**
     * @var string
     */
    protected const TABLE_ROW_TEMPLATE_NAME = 'replaceByNewRow';

    /**
     * @param mixed $key
     * @param mixed $data
     * @param string $default
     *
     * @return string
     */
    protected function findDataRecursively($key, $data, $default = ''): string
    {
        foreach (explode(self::TEMPLATE_SEPARATOR, $key) as $segment) {
            if (isset($data[$segment])) {
                $data = $data[$segment];
            } else {
                $data = $default;
            }
        }

        return $data;
    }

    /**
     * Fill data to the slide.
     *
     * @param array|Closure $data
     */
    public function template($data): void
    {
        if (!$data instanceof Closure) {
            $data = function ($matches) use ($data) {
                return $this->findDataRecursively($matches['needle'], $data);
            };
        }

        $xmlString = $this->replaceNeedle($this->getContent(), $data);

        $this->setContent($xmlString);

        $this->save();
    }

    protected function replaceNeedle(string $source, Closure $callback): string
    {
        $sanitizer = static function ($matches) use ($callback) {
            return htmlspecialchars($callback($matches));
        };

        return preg_replace_callback(
            '/({{)((<(.*?)>)+)?(?P<needle>.*?)((<(.*?)>)+)?(}})/mi',
            $sanitizer,
            $source
        );
    }

    public function table(Closure $data, Closure $finder = null): void
    {
        if (!$finder) {
            $finder = function (string $needle, array $row): string {
                return $this->findDataRecursively($needle, $row);
            };
        }

        $tables = $this->content->xpath('//a:tbl/../../../p:nvGraphicFramePr/p:cNvPr');
        foreach ($tables as $table) {
            $tableId = (string)$table->attributes()['name'];
            $tableRow = $this->content->xpath("//p:cNvPr[@name='$tableId']/../..//a:tr")[1];
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
                function ($matches) use ($tableRow, $rows, $finder) {
                    [, , $rowId] = $matches;

                    return $this->replaceNeedle($tableRow->asXML(),
                        static function ($matches) use ($rows, $rowId, $finder) {
                            return $finder($matches['needle'], $rows[$rowId]);
                        });
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
     * @param array|Closure $data
     */
    public function images($data): void
    {
        if (!$data instanceof Closure) {
            $data = static function ($key) use ($data) {
                return $data[$key] ?? null;
            };
        }

        foreach ($this->getTemplateImages() as $id => $key) {
            if (($content = $data($key)) !== null) {
                $this->getResource($id)->setContent($content);
            }
        }
    }

    /**
     * Gets the image identifiers capable to being templated.
     */
    public function getTemplateImages(): Generator
    {
        $nodes = $this->content->xpath('//p:pic');

        foreach ($nodes as $node) {
            $id = (string)$node->xpath('p:blipFill/a:blip/@r:embed')[0]->embed;
            $key = $node->xpath('p:nvPicPr/p:cNvPr/@descr');
            if ($key && isset($key[0]) && !empty($key[0]->descr)) {
                yield $id => (string)$key[0]->descr;
            }
        }
    }

    protected function mapResources(): void
    {
        parent::mapResources();
        // Ignore noteSlide prevent failure because, current library doesnt support that, for moment...
        $this->resources = array_filter($this->resources, static function ($resource) {
            return !$resource instanceof NoteSlide;
        });
    }
}
