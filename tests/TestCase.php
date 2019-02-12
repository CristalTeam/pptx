<?php

namespace Cpro\Presentation\Tests;

use PHPUnit\Framework\TestCase as CoreTestCase;

class TestCase extends CoreTestCase
{
    const TMP_PATH = __DIR__.'/tmp';

    public function setUp()
    {
        parent::setUp();

        if (!is_dir(self::TMP_PATH)) {
            mkdir(self::TMP_PATH, 0775);
        }
    }

    public function tearDown()
    {
        parent::tearDown();

        $files = array_diff(glob(self::TMP_PATH.'/*'), ['.', '..']);

        foreach ($files as $file) {
            unlink($file);
        }

        rmdir(self::TMP_PATH);
    }
}
