<?php

namespace Cristal\Presentation\Tests;

use Cristal\Presentation\PPTX;

class PPTXTest extends TestCase
{
    /**
     * @var int
     */
    const POWERPOINT_SLIDE_COUNT = 3;

    /**
     * @var PPTX
     */
    protected $pptx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pptx = new PPTX(__DIR__.'/mock/powerpoint.pptx');
    }

    /**
     * @test
     */
    public function it_loads_all_slides()
    {
        $this->assertEquals(
            self::POWERPOINT_SLIDE_COUNT,
            count($this->pptx->getSlides())
        );
    }

    /**
     * @test
     */
    public function it_merges_two_pptx()
    {
        $nbSourceSlides = count($this->pptx->getSlides());
        $pptxToAppend = new PPTX(__DIR__.'/mock/powerpoint.pptx');

        $this->pptx->addSlides($pptxToAppend->getSlides());
        $this->pptx->saveAs(self::TMP_PATH.'/merge.pptx');

        $mergedPPTX = new PPTX(self::TMP_PATH.'/merge.pptx');

        $this->assertEquals(
            $nbSourceSlides + count($pptxToAppend->getSlides()),
            count($mergedPPTX->getSlides())
        );
    }
}
