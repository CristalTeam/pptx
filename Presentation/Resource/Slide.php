<?php

namespace Cpro\Presentation\Resource;

use Closure;

class Slide extends XmlResource
{
    /**
     * @var string
     */
    const TEMPLATE_SEPARATOR = '.';

    /**
     * @var string
     */
    const TABLE_ROW_TEMPLATE_NAME = 'replaceByNewRow';

    /**
     * @param        $key
     * @param        $data
     * @param string $default
     *
     * @return string
     */
    protected function findDataRecursively($key, $data, $default = '')
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
     * @param array|\Closure $data
     */
    public function template($data)
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

    /**
     * @param string  $source   The source
     * @param Closure $callback The callback
     *
     * @return string
     */
    protected function replaceNeedle(string $source, Closure $callback): string
    {
        $sanitizer = function ($matches) use ($callback) {
            return htmlentities($callback($matches));
        };

        return preg_replace_callback(
            '/(\{\{)((\<(.*?)\>)+)?(?P<needle>.*?)((\<(.*?)\>)+)?(\}\})/mi',
            $sanitizer,
            $source
        );
    }

    /**
     * @param Closure $data
     */
    public function table(Closure $data, Closure $finder = null)
    {
        if (!$finder) {
            $finder = function (string $needle, array $row) : string {
                return $this->findDataRecursively($needle, $row);
            };
        }

        $tables = $this->content->xpath('//a:tbl/../../../p:nvGraphicFramePr/p:cNvPr');
        foreach ($tables as $table) {
            $tableId = (string) $table->attributes()['name'];
            $tableRow = $this->content->xpath("//p:cNvPr[@name='$tableId']/../..//a:tr")[1];
            $table = $tableRow->xpath('..')[0];
            $rows = $data($tableId);

            foreach ($rows as $index => $row) {
                $table->addChild(self::TABLE_ROW_TEMPLATE_NAME.$index);
            }

            $xml = preg_replace_callback(
                '/<([^>]+:?'.self::TABLE_ROW_TEMPLATE_NAME.'([\d])+\/>?)/',
                function ($matches) use ($tableRow, $rows, $finder) {
                    [,,$rowId] = $matches;

                    return $this->replaceNeedle($tableRow->asXML(), function ($matches) use ($rows, $rowId, $finder) {
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
    public function images($data)
    {
        if (!$data instanceof Closure) {
            $data = function ($key) use ($data) {
                return $data[$key] ?? null;
            };
        }

        foreach ($this->getTemplateImages() as $id => $key) {
            if ($data($key) !== null) {
                $this->getResource($id)->setContent($data($key));
            }
        }
    }

    /**
     * Gets the image identifiers capable to being templated.
     *
     * @return array
     */
    public function getTemplateImages()
    {
        $nodes = $this->content->xpath('//p:pic');

        foreach ($nodes as $node) {
            $id = (string) $node->xpath('p:blipFill/a:blip/@r:embed')[0]->embed;
            $key = $node->xpath('p:nvPicPr/p:cNvPr/@descr');

            if ($key && isset($key[0]) && $key[0]->descr) {
                yield $id => (string) $key[0]->descr;
            }
        }
    }
}
