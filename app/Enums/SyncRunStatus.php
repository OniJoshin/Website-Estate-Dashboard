<?php

namespace App\Enums;

enum SyncRunStatus: string
{
    case Running = 'running';
    case Successful = 'successful';
    case Partial = 'partial';
    case Failed = 'failed';
}
