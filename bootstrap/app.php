<?php

use App\Exceptions\ApiError;
use App\Http\Middleware\EnsureNotBlocked;
use App\Http\Middleware\EnsurePlatformStaff;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UseAdminSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // otorisasi channel privat Reverb: POST /api/broadcasting/auth (sesi app., sama dengan API workspace)
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['web', 'auth']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [UseAdminSession::class], append: [SecurityHeaders::class, EnsureNotBlocked::class]);
        $middleware->alias([
            'workspace' => ResolveWorkspace::class,
            'platform' => EnsurePlatformStaff::class,
        ]);
        // Tenant harus sudah diset sebelum route model binding (global scope tenant).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveWorkspace::class);
        // API murni: tamu mendapat 401 JSON; SPA sendiri yang mengarahkan ke /login.
        $middleware->redirectGuestsTo('/login');
        // Webhook gateway pembayaran (v1) tidak memakai CSRF.
        $middleware->preventRequestForgery(except: ['webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ApiError = error bisnis yang diharapkan (4xx, sudah dijawab ke klien) — bukan bug, jangan masuk log.
        $exceptions->dontReport(ApiError::class);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // BSD §8.1 — format error JSON seragam
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson()) || $e instanceof ApiError) {
                return null;
            }
            [$status, $code, $message, $fields] = match (true) {
                $e instanceof ValidationException => [$e->status, 'validation_failed', $e->validator->errors()->first(), $e->errors()],
                $e instanceof AuthenticationException => [401, 'unauthenticated', 'Silakan masuk terlebih dahulu.', null],
                $e instanceof AuthorizationException => [403, 'forbidden', $e->getMessage() ?: 'Anda tidak punya izin untuk aksi ini.', null],
                $e instanceof TooManyRequestsHttpException => [429, 'throttled', 'Terlalu banyak permintaan. Coba lagi sebentar lagi.', null],
                $e instanceof HttpExceptionInterface => [$e->getStatusCode(), match ($e->getStatusCode()) {
                    404 => 'not_found', 403 => 'forbidden', 423 => 'locked', 419 => 'session_expired', default => 'http_error',
                }, $e->getMessage() ?: 'Permintaan tidak dapat diproses.', null],
                default => [null, null, null, null],
            };
            if ($status === null) {
                return null;
            }

            return response()->json(['error' => array_filter(compact('code', 'message', 'fields'))], $status);
        });
    })->create();
