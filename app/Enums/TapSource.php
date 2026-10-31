<?php

namespace App\Enums;

enum TapSource: string
{
    case Nfc = 'nfc';
    case Qr = 'qr';
    case DynamicQr = 'dynamic_qr';
    case Code = 'code';
    case Link = 'link';
    case App = 'app';
}
