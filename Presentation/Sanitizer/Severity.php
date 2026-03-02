<?php

declare(strict_types=1);

namespace Cristal\Presentation\Sanitizer;

enum Severity: string
{
    case CRITICAL = 'CRITICAL';
    case HIGH = 'HIGH';
    case MEDIUM = 'MEDIUM';
    case WARNING = 'WARNING';
}
