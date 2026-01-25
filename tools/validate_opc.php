<?php
require __DIR__ . '/../vendor/autoload.php';

use Cristal\Presentation\Validator\OPCValidator;

$file = $argv[1] ?? 'tests/tmp/merge.pptx';

echo "Validating: $file\n\n";

$validator = new OPCValidator();
$result = $validator->validate($file);

if ($result['valid'] ?? empty($result['errors'])) {
    echo "✅ File is valid!\n";
} else {
    echo "❌ Issues found:\n";
    foreach ($result['errors'] ?? [] as $error) {
        $severity = $error['severity'] ?? 'UNKNOWN';
        $message = $error['message'] ?? 'Unknown error';
        echo "  [$severity] $message\n";
    }
}

echo "\nFull result:\n";
print_r($result);
