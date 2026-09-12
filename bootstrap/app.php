<?php

use Illuminate\Foundation\Application;

/*
 * Console-only application: no HTTP kernel, routes or middleware. Artisan
 * commands are discovered from app/Console/Commands automatically.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withExceptions()
    ->create();
