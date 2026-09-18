<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->web(
            append: [
                \App\Http\Middleware\ResolveShopifyApp::class,
                \App\Http\Middleware\SecurityHeaders::class,
                \App\Http\Middleware\SetEmbedFrameHeaders::class,
            ],
            replace: [
                ValidateCsrfToken::class => \App\Http\Middleware\VerifyCsrfToken::class,
            ],
        );

        $middleware->api(
            append: [
                \App\Http\Middleware\ResolveShopifyApp::class,
                \App\Http\Middleware\SecurityHeaders::class,
            ],
        );

        $middleware->alias([
            'shopify.webhook' => \App\Http\Middleware\VerifyShopifyWebhook::class,
            'shopify.request' => \App\Http\Middleware\VerifyShopifyRequest::class,
        ]);

        // Absolute CSRF exceptions only — HMAC webhooks + public pixel API.
        // Embedded admin POSTs must send @csrf OR a valid session JWT
        // (see App\Http\Middleware\VerifyCsrfToken).
        $middleware->validateCsrfTokens(except: [
            'webhooks',
            'webhooks/*',
            'auth/token-exchange',
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
