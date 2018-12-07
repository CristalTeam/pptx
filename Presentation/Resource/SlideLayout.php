<?php

namespace Cpro\Presentation\Resource;

class SlideLayout extends XmlResource
{
    protected function performSave()
    {
        if ($this->isDraft()) {
            $slideMaster = new XmlResource('ppt/slideMasters/slideMaster1.xml', '', 'ppt/', $this->zipArchive);
            $currentLayers = $slideMaster->content->xpath('p:sldLayoutIdLst/p:sldLayoutId');

            $sldLayoutId = $slideMaster->content->xpath('p:sldLayoutIdLst')[0]->addChild('p:sldLayoutId');
            $sldLayoutId['id'] = intval(end($currentLayers)['id']) + 1;
            $sldLayoutId['r:id'] = $slideMaster->addResource($this);
            $slideMaster->save();
        }

        return parent::performSave();
    }
}