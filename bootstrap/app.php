<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Illuminate\Support\Facades\Route::middleware('web')
                ->group(__DIR__.'/../routes/internal.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        // The release endpoint is called by tooling, not a browser, so it has
        // no CSRF token. It is protected by RELEASE_TOKEN instead.
        $middleware->validateCsrfTokens(except: [
            'internal/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
