<?php

namespace Cpro\Presentation\Resource;

use Closure;

class Slide extends XmlResource
{
    /**
     * @param        $key
     * @param        $data
     * @param string $default
     *
     * @return string
     */
    protected function findDataRecursively($key, $data, $default = '')
    {
        foreach (explode('_', $key) as $segment) {
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

        $xmlString = $this->getContent();
        $xmlString = preg_replace_callback(
            '/(\{\{)((\<(.*?)\>)+)?(?P<needle>.*?)((\<(.*?)\>)+)?(\}\})/mi',
            $data,
            $xmlString
        );

        $this->setContent($xmlString);

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
     * Gets the image identifiers capable to being templated
     *
     * @return array
     */
    public function getTemplateImages()
    {
        $nodes = $this->content->xpath("//p:pic");

        foreach ($nodes as $node) {
            $id = (string) $node->xpath('p:blipFill/a:blip/@r:embed')[0]->embed;
            $key = $node->xpath('p:nvPicPr/p:cNvPr/@descr');

            if ($key && isset($key[0]) && $key[0]->descr) {
                yield $id => (string) $key[0]->descr;
            }
        }
    }
}
