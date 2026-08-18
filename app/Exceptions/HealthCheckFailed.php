<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class HealthCheckFailed extends RuntimeException implements ShouldntReport {}
