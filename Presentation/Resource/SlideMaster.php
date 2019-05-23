<?php

namespace Cpro\Presentation\Resource;

use SimpleXMLElement;

/**
 * Class SlideMaster
 * @package Cpro\Presentation\Resource
 */
class SlideMaster extends XmlResource
{
    const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * {@inheritDoc}
     */
    public function addResource(Resource $resource)
    {
        $rId = parent::addResource($resource);

        if ($resource instanceof SlideLayout) {
            $this->addSlideLayout($rId);
        }

        return $rId;
    }

    /**
     * {@inheritDoc}
     */
    protected function performSave()
    {
        $this->optimizeIds();
        return parent::performSave();
    }

    /**
     * @param $rId
     */
    protected function addSlideLayout($rId)
    {
        $currentLayers = $this->content->xpath('p:sldLayoutIdLst/p:sldLayoutId');

        $sldLayoutId = $this->content->xpath('p:sldLayoutIdLst')[0]->addChild('p:sldLayoutId');
        $sldLayoutId->addAttribute('id', intval(max(static::ID_0, end($currentLayers)['id'])) + 1);
        $sldLayoutId->addAttribute('r:id', $rId, static::NS_R);
    }

    /**
     * @return void
     */
    protected function optimizeIds()
    {
        $backupResources = $this->getResources();

        // Get slides liste from XML.

        $currentLayers = $this->content->xpath('p:sldLayoutIdLst/p:sldLayoutId');
        $ids = [];
        foreach ($currentLayers as $item) {
            $namespaces = $item->getNamespaces();
            $ids[strval($item['id'])] = strval($item->attributes($namespaces['r'])['id']);
        }

        // Delete resources layoutes from _rels file.

        $resources = array_filter($backupResources, function ($rId) use ($ids){
            return !in_array($rId, $ids);
        }, ARRAY_FILTER_USE_KEY);

        // Recreate resources list.

        $this->clearResources();
        $rIdsOldArray = [];

        foreach($resources as $rIdOld => $resource){
            $rIdsOldArray[$rIdOld] = $this->addResource($resource);
        }

        foreach($ids as $rIdOld){
            $rIdsOldArray[$rIdOld] = $this->addResource($backupResources[$rIdOld]);
        }
    }

    /**
     * @return void
     */
    protected function clearResources()
    {
        $this->resources = [];
        $resourceXML = new SimpleXMLElement(static::RELS_XML);
        $this->zipArchive->addFromString($this->getRelsName(), $resourceXML->asXml());

        foreach($this->content->xpath('p:sldLayoutIdLst/p:sldLayoutId') as $node){
            unset($node[0]);
        }
    }
}
