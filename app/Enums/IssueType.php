<?php

namespace App\Enums;

enum IssueType: string
{
    case HttpUnavailable = 'http_unavailable';
    case HttpClientError = 'http_client_error';
    case HttpSlow = 'http_slow';
    case DnsUnresolved = 'dns_unresolved';
    case TlsInvalid = 'tls_invalid';
    case TlsUnavailable = 'tls_unavailable';
    case TlsExpiring = 'tls_expiring';
    case ServerHealthUnavailable = 'server_health_unavailable';
    case DiskUsage = 'disk_usage';
    case AccountSuspended = 'account_suspended';

    public function label(): string
    {
        return match ($this) {
            self::HttpUnavailable => 'HTTP unavailable',
            self::HttpClientError => 'HTTP client error',
            self::HttpSlow => 'Slow HTTP response',
            self::DnsUnresolved => 'DNS unresolved',
            self::TlsInvalid => 'TLS certificate invalid',
            self::TlsUnavailable => 'TLS inspection unavailable',
            self::TlsExpiring => 'TLS certificate expiring',
            self::ServerHealthUnavailable => 'WHM health unavailable',
            self::DiskUsage => 'Disk usage',
            self::AccountSuspended => 'Account suspended',
        };
    }
}
