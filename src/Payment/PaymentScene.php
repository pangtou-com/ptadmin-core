<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

enum PaymentScene: string
{
    case Qr = 'qr';
    case Web = 'web';
    case H5 = 'h5';
    case Jsapi = 'jsapi';
    case MiniProgram = 'mini_program';
    case App = 'app';
}
