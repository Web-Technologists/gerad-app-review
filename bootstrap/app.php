<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'api/webhooks',
            'api/webhooks/*',
        ]);
        
        $middleware->alias([
            'shopify.webhook' => \App\Http\Middleware\VerifyShopifyWebhook::class,
            'shopify.session' => \App\Http\Middleware\VerifyShopifySession::class,
            'admin.auth' => \App\Http\Middleware\VerifyAdminSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
