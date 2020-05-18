# Cristal PPTX

[![Latest Stable Version](https://img.shields.io/packagist/v/cristal/pptx.svg?style=flat-square)](https://packagist.org/packages/cristal/pptx)
[![GitHub issues](https://img.shields.io/github/issues/cristalTeam/pptx.svg?style=flat-square)](https://github.com/cristalTeam/pptx/issues)
[![GitHub license](https://img.shields.io/github/license/cristalTeam/pptx.svg?style=flat-square)](https://github.com/cristalTeam/pptx/blob/master/LICENSE)

Cristal PPTX is a PHP Library that allows you to manipulate slides from a Powerpoint PPTX file. Copy slides inside another pptx and templating it using mustache tags.  

## :rocket: Installation using Composer

```bash
composer require cristal/pptx
```

## :eyes: Quick view 

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
