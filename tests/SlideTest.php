<?php

namespace Cristal\Presentation\Tests;

use Cristal\Presentation\PPTX;

class SlideTest extends TestCase
{
    /**
     * @var array
     */
    const TEMPLATE_TEXT = [
        'user.name' => 'John',
        'user.age' => 25
    ];

    const TEMPLATE_IMAGE = [
        'image' => __DIR__.'/mock/image.png'
    ];

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
    public function it_removes_placeholders_after_templating_even_if_there_is_nothing_to_replace_the_placeholder()
    {
        $this->pptx->template(fn($matches) => self::TEMPLATE_TEXT[$matches['needle']] ?? null);

        $this->pptx->saveAs(self::TMP_PATH.'/template.pptx');

        $templatedPPTX = new PPTX(self::TMP_PATH.'/template.pptx');
        foreach($templatedPPTX->getSlides() as $slide) {
            foreach(self::TEMPLATE_TEXT as $key => $value) {
                $this->assertNotContains('{{'.$key.'}}', $slide->getContent());
            }
        }
    }

    public function it_replace_the_placeholders_with_the_right_text()
    {
        $this->pptx->template(fn($matches) => self::TEMPLATE[$matches['needle']] ?? null);

        $this->pptx->saveAs(self::TMP_PATH.'/template.pptx');

        $templatedPPTX = new PPTX(self::TMP_PATH.'/template.pptx');

        foreach(self::TEMPLATE as $value) {
            $this->assertContains($value, $templatedPPTX->getSlides()[1]->getContent());
        }
    }

    /**
     * @test
     */
    public function it_replace_the_image_placeholders()
    {
        $slide = $this->pptx->getSlides()[2];
        $images = $slide->getTemplateImages();

        $slide->images(fn($needle) => file_get_contents(self::TEMPLATE_IMAGE['image']));

        $this->pptx->saveAs(self::TMP_PATH.'/template.pptx');

        $templatedPPTX = new PPTX(self::TMP_PATH.'/template.pptx');
        foreach ($images as $id => $key) {
            $this->assertEquals(
                file_get_contents(self::TEMPLATE_IMAGE['image']),
                $templatedPPTX->getSlides()[2]->getResource($id)->getContent()
            );
        }
    }
}
