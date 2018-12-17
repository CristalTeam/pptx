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
     * @param array $data The key should match the descr attribute, the value is the raw content of the image
     */
    public function images(array $data)
    {
        foreach ($data as $key => $content) {
            $idx = $this->getImagesId($key);
            foreach ($idx as $id) {
                $image = $this->getResource($id);
                $image->setContent($content);
            }
        }
    }

    /**
     * Gets the images identifier.
     *
     * @param string $key
     *
     * @return array
     */
    public function getImagesId(string $key)
    {
        $nodes = $this->content->xpath("//p:cNvPr[@descr='$key']/../../p:blipFill/a:blip/@r:embed");

        return array_map(function ($node) {
            return (string) $node->embed;
        }, $nodes);
    }
}
