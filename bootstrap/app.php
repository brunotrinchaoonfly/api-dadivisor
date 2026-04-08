<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // CORS aplicado antes de qualquer middleware de rota
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        // Alias para o middleware de autenticação Onfly
        $middleware->alias([
            'onfly.auth' => \App\Http\Middleware\ValidateOnflyToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Validação retorna 422 com erros estruturados
        $exceptions->render(function (
            \Illuminate\Validation\ValidationException $e,
            \Illuminate\Http\Request $request
        ) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Dados inválidos.',
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        // Autenticação retorna 401 padronizado
        $exceptions->render(function (
            \Illuminate\Auth\AuthenticationException $e,
            \Illuminate\Http\Request $request
        ) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Não autenticado.'], 401);
            }
        });
    })->create();
