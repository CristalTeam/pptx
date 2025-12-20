<?php

declare(strict_types=1);

namespace Cristal\Presentation\Tests;

use PHPUnit\Framework\TestCase as CoreTestCase;

/**
 * Base test case for PPTX tests.
 */
class TestCase extends CoreTestCase
{
    protected const TMP_PATH = __DIR__ . '/tmp';

    public function setUp(): void
    {
        parent::setUp();

        if (!is_dir(self::TMP_PATH)) {
            mkdir(self::TMP_PATH, 0775);
        }
    }

    public function tearDown(): void
    {
        // Uncomment to clean up after tests
        // parent::tearDown();
        //
        // $files = array_diff(glob(self::TMP_PATH . '/*'), ['.', '..']);
        //
        // foreach ($files as $file) {
        //     unlink($file);
        // }
        //
        // rmdir(self::TMP_PATH);
    }
}
