<?php

namespace Cpro\Presentation\Resource;

use SimpleXMLElement;

/**
 * Class SlideMaster
 * @package Cpro\Presentation\Resource
 */
class SlideMaster extends XmlResource
{
    protected const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * {@inheritDoc}
     */
    public function addResource(GenericResource $resource): string
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
    protected function performSave(): void
    {
        $this->optimizeIds();
        parent::performSave();
    }

    /**
     * @param $rId
     */
    protected function addSlideLayout($rId): void
    {
        $currentLayers = $this->content->xpath('p:sldLayoutIdLst/p:sldLayoutId');

        $sldLayoutId = $this->content->xpath('p:sldLayoutIdLst')[0]->addChild('p:sldLayoutId');
        $sldLayoutId->addAttribute('id', (int)max(static::ID_0, end($currentLayers)['id']) + 1);
        $sldLayoutId->addAttribute('r:id', $rId, static::NS_R);
    }

    /**
     * @return void
     */
    protected function optimizeIds(): void
    {
        $backupResources = $this->getResources();

        // Get slides list from XML.

        $currentLayers = $this->content->xpath('p:sldLayoutIdLst/p:sldLayoutId');
        $ids = [];
        foreach ($currentLayers as $item) {
            $namespaces = $item->getNamespaces();
            $ids[(string)$item['id']] = (string)$item->attributes($namespaces['r'])['id'];
        }

        // Delete resources layouts from _rels file.

        $resources = array_filter($backupResources, static function ($rId) use ($ids) {
            return !in_array($rId, $ids, true);
        }, ARRAY_FILTER_USE_KEY);

        // Recreate resources list.

        $this->clearResources();
        $rIdsOldArray = [];

        foreach ($resources as $rIdOld => $resource) {
            $rIdsOldArray[$rIdOld] = $this->addResource($resource);
        }

        foreach ($ids as $rIdOld) {
            $rIdsOldArray[$rIdOld] = $this->addResource($backupResources[$rIdOld]);
        }
    }

    /**
     * @return void
     */
    protected function clearResources(): void
    {
        $this->resources = [];
        $resourceXML = new SimpleXMLElement(static::RELS_XML);
        $this->zipArchive->addFromString($this->getRelsName(), $resourceXML->asXml());

        foreach ($this->content->xpath('p:sldLayoutIdLst/p:sldLayoutId') as $node) {
            unset($node[0]);
        }
    }
}
