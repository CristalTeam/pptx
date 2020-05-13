<?php

namespace Cpro\Presentation\Resource;

class SlideLayout extends XmlResource
{
    /**
     * {@inheritdoc}
     */
    protected function performSave(): void
    {
        if ($this->isDraft()) {
            $slideMaster = new SlideMaster('ppt/slideMasters/slideMaster1.xml', '', $this->zipArchive);
            $slideMaster->addResource($this);
            $slideMaster->save();
        }

        parent::performSave();
    }
}
