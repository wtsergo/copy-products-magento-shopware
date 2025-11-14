<?php

namespace Wtsergo\CopyProductsMagentoShopware\Providers;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Nwidart\Modules\Traits\PathNamespace;
use Wtsergo\CopyProductsMagentoShopware\Console\Commands\CopyProductsMagentoShopware as CopyProductsMagentoShopwareCmd;

class ServiceProvider extends BaseServiceProvider
{
    use PathNamespace;

    protected string $name = 'CopyProductsMagentoShopware';

    protected string $nameLower = 'copyproductsmagentoshopware';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([CopyProductsMagentoShopwareCmd::class]);
    }

}
