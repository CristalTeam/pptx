<?php

namespace Cristal\Presentation\Resource;

use Cristal\Presentation\ResourceInterface;

class Presentation extends XmlResource
{
    public function addResource(ResourceInterface $resource): ?string
    {
        if ($resource instanceof NoteMaster) {
            $rId = parent::addResource($resource);
            if (!count($this->content->xpath('p:notesMasterIdLst'))) {
                $this->content->addChild('p:notesMasterIdLst');

                $ref = $this->content->xpath('p:notesMasterIdLst')[0]->addChild('notesMasterId');
                $ref->addAttribute('r:id', $rId, $this->namespaces['r']);
            }

            return $rId;
        }

        if ($resource instanceof Slide) {
            $rId = parent::addResource($resource);

            $currentSlides = $this->content->xpath('p:sldIdLst/p:sldId');

            $ref = $this->content->xpath('p:sldIdLst')[0]->addChild('sldId');
            $ref->addAttribute('id', (int)end($currentSlides)['id'] + 1);
            $ref->addAttribute('r:id', $rId, $this->namespaces['r']);

            return $rId;
        }

        if ($resource instanceof SlideMaster) {
            $rId = parent::addResource($resource);

            $ref = $this->content->xpath('p:sldMasterIdLst')[0]->addChild('sldMasterId');
            $ref->addAttribute('id', self::getUniqueID());
            $ref->addAttribute('r:id', $rId, $this->namespaces['r']);

            return $rId;
        }

        if ($resource instanceof HandoutMaster) {
            $rId = parent::addResource($resource);
            $ref = $this->content->xpath('p:handoutMasterIdLst')[0]->addChild('handoutMasterId');
            $ref->addAttribute('r:id', $rId, $this->namespaces['r']);

            return $rId;
        }

        return null;
    }
}
