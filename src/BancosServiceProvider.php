<?php

declare(strict_types=1);

namespace DanielBBarcelos\Bancos;

use DanielBBarcelos\Bancos\Console\PingCommand;
use Illuminate\Support\ServiceProvider;

class BancosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bancos.php', 'bancos');

        $this->app->singleton(BancoManager::class, fn ($app) => new BancoManager($app));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/bancos.php' => $this->app->configPath('bancos.php'),
            ], 'bancos-config');

            $this->commands([PingCommand::class]);
        }
    }
}
