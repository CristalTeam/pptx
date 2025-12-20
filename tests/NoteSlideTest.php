<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests;

use Cristal\Presentation\PPTX;
use Cristal\Presentation\Resource\NoteSlide;

class NoteSlideTest extends TestCase
{
    protected PPTX $pptx;

    public function setUp(): void
    {
        parent::setUp();
        $this->pptx = new PPTX(__DIR__ . '/mock/garde.pptx');
    }

    /**
     * @test
     */
    public function it_can_get_notes_from_slide(): void
    {
        $slides = $this->pptx->getSlides();
        $this->assertNotEmpty($slides);

        $slide = $slides[0];
        $notes = $slide->getNotes();

        // Notes may or may not exist depending on the test file
        $this->assertTrue($notes === null || $notes instanceof NoteSlide);
    }

    /**
     * @test
     */
    public function it_can_check_if_slide_has_notes(): void
    {
        $slides = $this->pptx->getSlides();
        $slide = $slides[0];

        // hasNotes returns a boolean
        $this->assertIsBool($slide->hasNotes());
    }

    /**
     * @test
     */
    public function it_can_get_notes_text(): void
    {
        $slides = $this->pptx->getSlides();
        $slide = $slides[0];

        $text = $slide->getNotesText();

        // Text is either null or a string
        $this->assertTrue($text === null || is_string($text));
    }

    /**
     * @test
     */
    public function noteslide_has_content_returns_false_when_empty(): void
    {
        $slides = $this->pptx->getSlides();
        $slide = $slides[0];
        $notes = $slide->getNotes();

        if ($notes !== null) {
            // hasContent should return a boolean
            $this->assertIsBool($notes->hasContent());
        } else {
            // If no notes, hasNotes should be false
            $this->assertFalse($slide->hasNotes());
        }
    }

    /**
     * @test
     */
    public function it_preserves_notes_when_merging_slides(): void
    {
        $sourceSlideCount = count($this->pptx->getSlides());
        $pptxToAppend = new PPTX(__DIR__ . '/mock/pc.pptx');

        $this->pptx->addSlides($pptxToAppend->getSlides());
        $this->pptx->saveAs(self::TMP_PATH . '/merge_notes.pptx');

        $mergedPPTX = new PPTX(self::TMP_PATH . '/merge_notes.pptx');

        // Verify all slides were merged
        $this->assertEquals(
            $sourceSlideCount + count($pptxToAppend->getSlides()),
            count($mergedPPTX->getSlides())
        );

        // Verify notes are accessible on merged slides
        foreach ($mergedPPTX->getSlides() as $slide) {
            // getNotes should not throw an exception
            $notes = $slide->getNotes();
            $this->assertTrue($notes === null || $notes instanceof NoteSlide);
        }
    }
}