<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests;

use Cristal\Presentation\PPTX;

class SlideTest extends TestCase
{
    /**
     * Template text data.
     */
    private const TEMPLATE_TEXT = [
        'user.name' => 'John',
        'user.age' => 25,
    ];

    /**
     * Template image data.
     */
    private const TEMPLATE_IMAGE = [
        'image' => __DIR__ . '/mock/image.png',
    ];

    protected PPTX $pptx;

    public function setUp(): void
    {
        parent::setUp();
        $this->pptx = new PPTX(__DIR__ . '/mock/powerpoint.pptx');
    }

    /**
     * @test
     */
    public function it_removes_placeholders_after_templating_even_if_there_is_nothing_to_replace_the_placeholder(): void
    {
        $this->pptx->template(function (array $matches): ?string {
            $value = self::TEMPLATE_TEXT[$matches['needle']] ?? null;

            return $value !== null ? (string) $value : null;
        });

        $this->pptx->saveAs(self::TMP_PATH . '/template.pptx');

        $templatedPPTX = new PPTX(self::TMP_PATH . '/template.pptx');
        foreach ($templatedPPTX->getSlides() as $slide) {
            foreach (self::TEMPLATE_TEXT as $key => $value) {
                $this->assertStringNotContainsString('{{' . $key . '}}', $slide->getContent());
            }
        }
    }

    /**
     * @test
     */
    public function it_replaces_the_placeholders_with_the_right_text(): void
    {
        $this->pptx->template(function (array $matches): ?string {
            $value = self::TEMPLATE_TEXT[$matches['needle']] ?? null;

            return $value !== null ? (string) $value : null;
        });

        $this->pptx->saveAs(self::TMP_PATH . '/template.pptx');

        $templatedPPTX = new PPTX(self::TMP_PATH . '/template.pptx');

        foreach (self::TEMPLATE_TEXT as $key => $value) {
            $this->assertStringContainsString((string) $value, $templatedPPTX->getSlides()[1]->getContent());
        }
    }

    /**
     * @test
     */
    public function it_replaces_the_image_placeholders(): void
    {
        $slide = $this->pptx->getSlides()[2];
        $images = $slide->getTemplateImages();

        $slide->images(function (string $needle): string {
            return file_get_contents(self::TEMPLATE_IMAGE['image']);
        });

        $this->pptx->saveAs(self::TMP_PATH . '/template.pptx');

        $templatedPPTX = new PPTX(self::TMP_PATH . '/template.pptx');
        foreach ($images as $id => $key) {
            $this->assertEquals(
                file_get_contents(self::TEMPLATE_IMAGE['image']),
                $templatedPPTX->getSlides()[2]->getResource($id)->getContent()
            );
        }
    }

    /**
     * @test
     */
    public function it_returns_template_images(): void
    {
        $slide = $this->pptx->getSlides()[0];
        $images = iterator_to_array($slide->getTemplateImages());

        $this->assertIsArray($images);
    }
}
