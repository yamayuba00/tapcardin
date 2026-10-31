<?php

namespace App\Enums;

enum ActivityType: string
{
    case Note = 'note';
    case Call = 'call';
    case Whatsapp = 'whatsapp';
    case Email = 'email';
    case Meeting = 'meeting';
    case StageChanged = 'stage_changed';
    case Assigned = 'assigned';
    case Tap = 'tap';
    case Merged = 'merged';
    case Created = 'created';
    case System = 'system';
}
