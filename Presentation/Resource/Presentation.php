<?php

namespace Cpro\Presentation\Resource;

class Presentation extends XmlResource
{
    public function addResource(Resource $resource)
    {
        $rId = parent::addResource($resource);

        if ($resource instanceof NoteMaster) {
            if (!count($this->content->xpath('p:notesMasterId'))) {
                $this->content->addChild('p:notesMasterId');
            }

            $ref = $this->content->xpath('p:notesMasterId')[0]->addChild('notesMasterId');
            $ref['r:id'] = $rId;
        }

        if ($resource instanceof Slide) {
            $currentSlides = $this->content->xpath('p:sldIdLst/p:sldId');

            $ref = $this->content->xpath('p:sldIdLst')[0]->addChild('sldId');
            $ref['id'] = intval(end($currentSlides)['id']) + 1;
            $ref['r:id'] = $rId;
        }

        return $rId;
    }
}
