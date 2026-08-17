<?php

namespace App\Enums\Admin;

enum ReportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Ready = 'ready';
    case Failed = 'failed';
    case Expired = 'expired';
}
