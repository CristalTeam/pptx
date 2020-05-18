# PPTX Class

## Installation

```bash
composer require cristal/pptx
```

## Basic example

```php
<?php

use Cristal\Presentation\PPTX;

require 'vendor/autoload.php';

$basePPTX = new PPTX(__DIR__.'/source/base.pptx');
$endPPTX = new PPTX(__DIR__.'/source/endslide.pptx');

$basePPTX->addSlides($endPPTX->getSlides());

$basePPTX->template([
    'materiel' => [
        'libelle' => 'Bonjour'
    ]
]);

$basePPTX->saveAs(__DIR__.'/dist/presentation.pptx');
```
