<?php

namespace Cpro\Presentation\Resource;

use Closure;

class Slide extends XmlResource
{
    /**
     * @param        $key
     * @param        $data
     * @param string $default
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
}
