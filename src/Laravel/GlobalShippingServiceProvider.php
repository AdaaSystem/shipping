<?php

declare(strict_types=1);

namespace Adaa\Shipping\Laravel;

use Adaa\Shipping\Drivers\FakeDriver;
use Adaa\Shipping\Laravel\Console\ShippingDiagnoseCommand;
use Adaa\Shipping\Laravel\Console\ShippingDriversCommand;
use Adaa\Shipping\Manager\ShippingManager;
use Adaa\Shipping\Registry\CarrierRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class GlobalShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/shipping.php', 'shipping');

        $this->app->singleton(ShippingManager::class, function (): ShippingManager {
            $manager = new ShippingManager;
            $manager->share('fake', new FakeDriver);

            return $manager;
        });

        $this->app->singleton(CarrierRegistry::class, function (): CarrierRegistry {
            /** @var array<string, array<string, mixed>> $carriers */
            $carriers = config('shipping.carriers', []);

            return new CarrierRegistry($carriers);
        });

        $this->app->alias(ShippingManager::class, 'shipping');
    }

    public function boot(): void
    {
        if ((bool) config('shipping.webhook.enabled', true)) {
            Route::prefix((string) config('shipping.webhook.prefix', 'webhooks/shipping'))
                ->middleware((array) config('shipping.webhook.middleware', ['api']))
                ->group(__DIR__.'/../../routes/webhooks.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                ShippingDriversCommand::class,
                ShippingDiagnoseCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../../config/shipping.php' => config_path('shipping.php'),
            ], 'shipping-config');
        }
    }
}
