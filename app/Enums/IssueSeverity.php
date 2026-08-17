<?php

namespace App\Enums;

enum IssueSeverity: string
{
    case Warning = 'warning';
    case Critical = 'critical';
}
