<?php

use App\Http\Middleware\CheckSubscriptionActive;
use App\Http\Middleware\CheckUserActive;
use App\Http\Middleware\EnsureApiOnlyRole;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));

        $middleware->alias([
            'user.active' => CheckUserActive::class,
            'tenant.active' => EnsureTenantIsActive::class,
            'subscription.active' => CheckSubscriptionActive::class,
            'merchandiser.mobile' => EnsureApiOnlyRole::class,
            'api.only' => EnsureApiOnlyRole::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        /*
         |----------------------------------------------------------------------
         | Laravel Shield (WAF) — регистрация
         |----------------------------------------------------------------------
         | В Laravel 11+/13 нет app/Http/Kernel.php. Глобальный HTTP-стек
         | настраивается ЗДЕСЬ, в bootstrap/app.php → withMiddleware().
         |
         | firewall.all — middleware group пакета ozankurt/laravel-security
         | (XSS/SQLi/LFI/bots/…). Вешается на web + api «сверху», без изменения
         | auth:sanctum / guest / permission / subscription.
         | Health /up остаётся вне этих групп.
         |
         | В APP_ENV=testing / FIREWALL_ENABLED=false слой не вешаем — package
         | требует lookup-таблицы; PHPUnit smoke не должен зависеть от WAF DB.
         |----------------------------------------------------------------------
         */
        $firewallEnabled = filter_var(env('FIREWALL_ENABLED', true), FILTER_VALIDATE_BOOLEAN)
            && env('APP_ENV') !== 'testing';

        if ($firewallEnabled) {
            $middleware->appendToGroup('web', 'firewall.all');
            $middleware->appendToGroup('api', 'firewall.all');
        }

        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            $pages = [
                403 => 'Errors/Forbidden',
                404 => 'Errors/NotFound',
                405 => 'Errors/MethodNotAllowed',
                419 => 'Errors/PageExpired',
                429 => 'Errors/TooManyRequests',
                500 => 'Errors/ServerError',
                503 => 'Errors/ServiceUnavailable',
            ];

            $status = $response->getStatusCode();

            if (! isset($pages[$status])) {
                return $response;
            }

            return Inertia::render($pages[$status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
