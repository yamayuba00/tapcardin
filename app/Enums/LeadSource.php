<?php

namespace App\Enums;

enum LeadSource: string
{
    case ExchangeForm = 'exchange_form';
    case ScanTapcard = 'scan_tapcard';
    case ScanExternal = 'scan_external';
    case Manual = 'manual';
    case Import = 'import';
    case EventCheckin = 'event_checkin';
    case SavedFromProfile = 'saved_from_profile';
    case BioForm = 'bio_form';
}
