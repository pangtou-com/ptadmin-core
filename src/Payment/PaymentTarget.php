<?php

declare(strict_types=1);

namespace PTAdmin\Payment;

enum PaymentTarget: string
{
    case Pc = 'pc';
    case MobileWeb = 'mobile_web';
    case WechatWebview = 'wechat_webview';
    case AlipayWebview = 'alipay_webview';
    case MiniProgram = 'mini_program';
    case NativeApp = 'native_app';
}
