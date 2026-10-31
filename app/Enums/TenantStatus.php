<?php

namespace App\Enums;

enum TenantStatus: string
{
    case Active = 'active';
    case Trialing = 'trialing';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case PendingDeletion = 'pending_deletion';
    case Deleted = 'deleted';
}
