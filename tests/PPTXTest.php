<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests;

use Cristal\Presentation\PPTX;

class PPTXTest extends TestCase
{
    /**
     * Number of slides in the test PowerPoint.
     */
    private const POWERPOINT_SLIDE_COUNT = 2;

    protected PPTX $pptx;

    public function setUp(): void
    {
        parent::setUp();
        $this->pptx = new PPTX(__DIR__ . '/mock/garde.pptx');
    }

    /**
     * @test
     */
    public function it_loads_all_slides(): void
    {
        $this->assertEquals(
            self::POWERPOINT_SLIDE_COUNT,
            count($this->pptx->getSlides())
        );
    }

    /**
     * @test
     */
    public function it_merges_two_pptx(): void
    {
        $nbSourceSlides = count($this->pptx->getSlides());
        $pptxToAppend = new PPTX(__DIR__ . '/mock/pc.pptx');

        $this->pptx->addSlides($pptxToAppend->getSlides());
        $this->pptx->saveAs(self::TMP_PATH . '/merge.pptx');

        $mergedPPTX = new PPTX(self::TMP_PATH . '/merge.pptx');

        $this->assertEquals(
            $nbSourceSlides + count($pptxToAppend->getSlides()),
            count($mergedPPTX->getSlides())
        );
    }

    /**
     * @test
     */
    public function it_returns_optimization_stats(): void
    {
        $stats = $this->pptx->getOptimizationStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('original_size', $stats);
        $this->assertArrayHasKey('optimized_size', $stats);
        $this->assertArrayHasKey('cache_stats', $stats);
    }

    /**
     * @test
     */
    public function it_validates_presentation(): void
    {
        $validation = $this->pptx->validate();

        $this->assertIsArray($validation);
        $this->assertArrayHasKey('valid', $validation);
        $this->assertArrayHasKey('slides', $validation);
        $this->assertArrayHasKey('resources', $validation);
    }

    /**
     * @test
     */
    public function it_returns_config(): void
    {
        $config = $this->pptx->getConfig();

        $this->assertInstanceOf(\Cristal\Presentation\Config\OptimizationConfig::class, $config);
        $this->assertFalse($config->isEnabled('image_compression'));
        $this->assertTrue($config->isEnabled('lazy_loading'));
    }

    /**
     * @test
     */
    public function it_returns_image_cache(): void
    {
        $cache = $this->pptx->getImageCache();

        $this->assertInstanceOf(\Cristal\Presentation\Cache\ImageCache::class, $cache);
        $this->assertEquals(0, $cache->count());
    }
}
