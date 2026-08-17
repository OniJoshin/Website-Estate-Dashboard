<?php

namespace Tests\Unit\Inventory;

use App\Enums\DomainClassification;
use App\Enums\DomainType;
use App\Services\Inventory\DomainClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DomainClassifierTest extends TestCase
{
    #[DataProvider('nonSubdomainTypeProvider')]
    public function test_non_subdomain_types_take_precedence_over_the_hostname(
        DomainType $type,
        string $domain,
        DomainClassification $expected,
    ): void {
        $this->assertSame($expected, (new DomainClassifier)->classify($type, $domain));
    }

    /** @return iterable<string, array{DomainType, string, DomainClassification}> */
    public static function nonSubdomainTypeProvider(): iterable
    {
        yield 'primary' => [DomainType::Primary, 'staging.example.invalid', DomainClassification::Website];
        yield 'addon' => [DomainType::Addon, 'mail.example.invalid', DomainClassification::Website];
        yield 'alias' => [DomainType::Alias, 'dev.example.invalid', DomainClassification::Alias];
    }

    #[DataProvider('developmentPrefixProvider')]
    public function test_development_subdomain_prefixes_are_classified_as_development(string $prefix): void
    {
        $classification = (new DomainClassifier)->classify(
            DomainType::Subdomain,
            $prefix.'.example.invalid',
        );

        $this->assertSame(DomainClassification::Development, $classification);
    }

    /** @return iterable<string, array{string}> */
    public static function developmentPrefixProvider(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'development' => ['development'];
        yield 'staging' => ['staging'];
        yield 'stage' => ['stage'];
        yield 'test' => ['test'];
        yield 'testing' => ['testing'];
        yield 'new' => ['new'];
        yield 'uat' => ['uat'];
    }

    #[DataProvider('servicePrefixProvider')]
    public function test_service_subdomain_prefixes_are_classified_as_services(string $prefix): void
    {
        $classification = (new DomainClassifier)->classify(
            DomainType::Subdomain,
            $prefix.'.example.invalid',
        );

        $this->assertSame(DomainClassification::Service, $classification);
    }

    /** @return iterable<string, array{string}> */
    public static function servicePrefixProvider(): iterable
    {
        yield 'mail' => ['mail'];
        yield 'webmail' => ['webmail'];
        yield 'cpanel' => ['cpanel'];
        yield 'webdisk' => ['webdisk'];
    }

    #[DataProvider('unknownSubdomainProvider')]
    public function test_other_subdomains_remain_unknown(string $domain): void
    {
        $classification = (new DomainClassifier)->classify(DomainType::Subdomain, $domain);

        $this->assertSame(DomainClassification::Unknown, $classification);
    }

    /** @return iterable<string, array{string}> */
    public static function unknownSubdomainProvider(): iterable
    {
        yield 'shop' => ['shop.example.invalid'];
        yield 'booking' => ['booking.example.invalid'];
        yield 'portal' => ['portal.example.invalid'];
        yield 'careers' => ['careers.example.invalid'];
        yield 'development substring' => ['devshop.example.invalid'];
        yield 'service substring' => ['mailing.example.invalid'];
        yield 'development label is not first' => ['shop.staging.example.invalid'];
    }

    #[DataProvider('normalizedSubdomainProvider')]
    public function test_subdomain_matching_is_case_insensitive_and_ignores_surrounding_whitespace(
        string $domain,
        DomainClassification $expected,
    ): void {
        $classification = (new DomainClassifier)->classify(DomainType::Subdomain, $domain);

        $this->assertSame($expected, $classification);
    }

    /** @return iterable<string, array{string, DomainClassification}> */
    public static function normalizedSubdomainProvider(): iterable
    {
        yield 'uppercase development' => ['STAGING.Example.Invalid', DomainClassification::Development];
        yield 'mixed case service' => ['WebMail.Example.Invalid', DomainClassification::Service];
        yield 'surrounding whitespace' => ['  STAGING.Example.Invalid  ', DomainClassification::Development];
        yield 'first label only' => ['staging.shop.example.invalid', DomainClassification::Development];
    }
}
