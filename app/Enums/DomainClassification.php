<?php

namespace App\Enums;

enum DomainClassification: string
{
    case Website = 'website';
    case Development = 'development';
    case Alias = 'alias';
    case Service = 'service';
    case Unknown = 'unknown';
    case Ignored = 'ignored';
}
