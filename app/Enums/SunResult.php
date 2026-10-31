<?php

namespace App\Enums;

enum SunResult: string
{
    case Verified = 'verified';
    case Replay = 'replay';
    case Invalid = 'invalid';
}
