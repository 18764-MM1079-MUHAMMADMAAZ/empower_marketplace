<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifySsoApiKey;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request): string => route('home'));

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'sso.apikey' => VerifySsoApiKey::class,
        ]);

        // Server-to-server calls from Moodle's OpenID Connect plugin, authenticated by client secret / bearer token.
        $middleware->preventRequestForgery(except: ['oauth/token', 'oauth/userinfo']);

        $middleware->prependToPriorityList(SubstituteBindings::class, AdminMiddleware::class);
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            if ($request->is('api/*')
                || ($request->expectsJson() && ! $request->is('livewire/*'))
                || $request->user()) {
                return null;
            }

            return redirect()->route('home')
                ->with('toast', 'Your session has expired. Please sign in again.')
                ->with('toast_type', 'error');
        });
    })->create();
