<?php

namespace Cpro\Presentation\Resource;

class SlideLayout extends XmlResource
{
    /**
     * {@inheritdoc}
     */
    protected function performSave()
    {
        if ($this->isDraft()) {
            $slideMaster = new SlideMaster('ppt/slideMasters/slideMaster1.xml', '', 'ppt/', $this->zipArchive);
            $slideMaster->addResource($this);
            $slideMaster->save();
        }

        return parent::performSave();
    }
}
