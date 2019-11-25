<?php

namespace App\Exceptions;

use Illuminate\Exception\ExceptionServiceProvider;

class ServiceProvider extends ExceptionServiceProvider
{
    /**
     * Register the "pretty" Whoops handler.
     *
     * @return void
     */
    protected function registerPrettyWhoopsHandler()
    {
        $this->app['whoops.handler'] = $this->app->share(function()
        {
            with($handler = new WhoopsPrettyPageHandler)->setEditor('sublime');

            return $handler;
        });
    }
}
