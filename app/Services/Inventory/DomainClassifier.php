<?php

namespace App\Services\Inventory;

use App\Enums\DomainClassification;
use App\Enums\DomainType;
use Illuminate\Support\Str;

final class DomainClassifier
{
    private const array DEVELOPMENT_PREFIXES = [
        'dev',
        'development',
        'staging',
        'stage',
        'test',
        'testing',
        'new',
        'uat',
    ];

    private const array SERVICE_PREFIXES = [
        'mail',
        'webmail',
        'cpanel',
        'webdisk',
    ];

    public function classify(DomainType $type, string $domain): DomainClassification
    {
        return match ($type) {
            DomainType::Primary, DomainType::Addon => DomainClassification::Website,
            DomainType::Alias => DomainClassification::Alias,
            DomainType::Subdomain => $this->classifySubdomain($domain),
        };
    }

    private function classifySubdomain(string $domain): DomainClassification
    {
        $firstLabel = Str::of($domain)->trim()->lower()->before('.')->toString();

        if (in_array($firstLabel, self::DEVELOPMENT_PREFIXES, true)) {
            return DomainClassification::Development;
        }

        if (in_array($firstLabel, self::SERVICE_PREFIXES, true)) {
            return DomainClassification::Service;
        }

        return DomainClassification::Unknown;
    }
}
