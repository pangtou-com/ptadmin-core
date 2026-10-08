<?php

declare(strict_types=1);

namespace PTAdmin\Core\Providers;

use Illuminate\Support\ServiceProvider;
use PTAdmin\Core\ServiceProtocol\AddonAvailability;
use PTAdmin\Payment\{PaymentRegistry, PaymentGateway};
use PTAdmin\SocialAuth\{SocialAuthRegistry, SocialAuthGateway};

final class ServiceProtocolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AddonAvailability::class);
        $this->app->singleton(PaymentRegistry::class);
        $this->app->singleton(PaymentGateway::class);
        $this->app->singleton(SocialAuthRegistry::class);
        $this->app->singleton(SocialAuthGateway::class);
    }
}
