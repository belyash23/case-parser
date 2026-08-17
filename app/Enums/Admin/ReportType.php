<?php

namespace App\Enums\Admin;

enum ReportType: string
{
    case Availability = 'availability';
    case Dataset = 'dataset';
    case CaseInspection = 'case_inspection';
}
