<?php

namespace App\Enums;

enum DomainType: string
{
    case Primary = 'primary';
    case Addon = 'addon';
    case Subdomain = 'subdomain';
    case Alias = 'alias';
}
