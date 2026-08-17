<?php

namespace App\Services\Whm\Exceptions;

use RuntimeException;

final class WhmApiException extends RuntimeException
{
    /** @param array<string, int|string|null> $context */
    public function __construct(
        public readonly string $category,
        public readonly array $context,
    ) {
        $hostname = $context['server_hostname'] ?? 'unknown';
        $function = $context['function'] ?? 'unknown';

        parent::__construct("WHM API {$category} for server {$hostname} while calling {$function}.");
    }
}
