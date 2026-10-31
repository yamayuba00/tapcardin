<?php

namespace App\Enums;

enum ReportStatus: string
{
    case Open = 'open';
    case Reviewing = 'reviewing';
    case Actioned = 'actioned';
    case Dismissed = 'dismissed';
}
