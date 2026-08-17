<?php

return [
    'http' => [
        'timeout_seconds' => (int) env('ESTATE_HTTP_TIMEOUT', 10),
        'slow_ms' => (int) env('ESTATE_HTTP_SLOW_MS', 2000),
        'failure_debounce' => (int) env('ESTATE_HTTP_FAILURE_DEBOUNCE', 2),
        'recovery_debounce' => (int) env('ESTATE_HTTP_RECOVERY_DEBOUNCE', 2),
        'slow_debounce' => (int) env('ESTATE_HTTP_SLOW_DEBOUNCE', 3),
        'max_redirects' => (int) env('ESTATE_HTTP_MAX_REDIRECTS', 10),
    ],

    'dns' => [
        'failure_debounce' => (int) env('ESTATE_DNS_FAILURE_DEBOUNCE', 2),
        'recovery_debounce' => (int) env('ESTATE_DNS_RECOVERY_DEBOUNCE', 2),
    ],

    'server' => [
        'failure_debounce' => (int) env('ESTATE_SERVER_FAILURE_DEBOUNCE', 2),
        'recovery_debounce' => (int) env('ESTATE_SERVER_RECOVERY_DEBOUNCE', 2),
    ],

    'tls' => [
        'warning_days' => (int) env('ESTATE_SSL_WARNING_DAYS', 30),
        'critical_days' => (int) env('ESTATE_SSL_CRITICAL_DAYS', 7),
        'timeout_seconds' => (int) env('ESTATE_TLS_TIMEOUT', 10),
    ],

    'disk' => [
        'warning_percent' => (int) env('ESTATE_DISK_WARNING_PERCENT', 85),
        'critical_percent' => (int) env('ESTATE_DISK_CRITICAL_PERCENT', 95),
    ],

    'retention' => [
        'check_days' => (int) env('ESTATE_CHECK_RETENTION_DAYS', 90),
    ],

    'inventory' => [
        'stale_hours' => (int) env('ESTATE_INVENTORY_STALE_HOURS', 26),
    ],

    'schedule' => [
        'server_minutes' => (int) env('ESTATE_SERVER_CHECK_MINUTES', 5),
        'http_minutes' => (int) env('ESTATE_HTTP_CHECK_MINUTES', 10),
        'dns_minutes' => (int) env('ESTATE_DNS_CHECK_MINUTES', 360),
        'tls_minutes' => (int) env('ESTATE_TLS_CHECK_MINUTES', 360),
        'inventory_time' => env('ESTATE_INVENTORY_TIME', '03:00'),
        'inventory_timezone' => env('ESTATE_INVENTORY_TIMEZONE', 'Europe/London'),
    ],
];
