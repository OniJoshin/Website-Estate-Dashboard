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
}
