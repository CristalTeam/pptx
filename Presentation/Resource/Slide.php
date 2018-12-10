<?php

namespace Cpro\Presentation\Resource;

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
     * @param array $data
     */
    public function template(array $data)
    {
        $xmlString = $this->getContent();

        $xmlString = preg_replace_callback('/(\{\{)((\<(.*?)\>)+)?(?P<needle>.*?)((\<(.*?)\>)+)?(\}\})/mi', function ($matches) use ($data) {
            return $this->findDataRecursively($matches['needle'], $data);
        }, $xmlString);

        $this->setContent($xmlString);

        $this->save();
    }
}