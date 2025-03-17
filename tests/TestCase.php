<?php

namespace Cristal\Presentation\Tests;

use PHPUnit\Framework\TestCase as CoreTestCase;

final class TestCase extends CoreTestCase
{
    const TMP_PATH = __DIR__.'/tmp';

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(self::TMP_PATH)) {
            mkdir(self::TMP_PATH, 0775);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $files = array_diff(glob(self::TMP_PATH.'/*'), ['.', '..']);

        foreach ($files as $file) {
            unlink($file);
        }

        rmdir(self::TMP_PATH);
    }
}
