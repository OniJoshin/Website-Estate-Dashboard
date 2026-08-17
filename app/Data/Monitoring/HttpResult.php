<?php

namespace App\Data\Monitoring;

final readonly class HttpResult
{
    private function __construct(
        public bool $successful,
        public ?int $httpStatus,
        public ?int $responseTimeMs,
        public ?string $finalUrl,
        public ?int $redirectCount,
        public ?string $errorType,
        public ?string $errorMessage,
    ) {}

    public static function response(
        int $httpStatus,
        int $responseTimeMs,
        string $finalUrl,
        int $redirectCount,
    ): self {
        return new self(
            successful: true,
            httpStatus: $httpStatus,
            responseTimeMs: $responseTimeMs,
            finalUrl: $finalUrl,
            redirectCount: $redirectCount,
            errorType: null,
            errorMessage: null,
        );
    }

    public static function failure(string $errorType, string $errorMessage, ?int $responseTimeMs = null): self
    {
        return new self(
            successful: false,
            httpStatus: null,
            responseTimeMs: $responseTimeMs,
            finalUrl: null,
            redirectCount: null,
            errorType: $errorType,
            errorMessage: $errorMessage,
        );
    }
}
