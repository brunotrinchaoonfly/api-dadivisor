<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ValidateOnflyToken
{
    /**
     * Valida o Bearer token contra GET /employees/me da API Onfly.
     *
     * Cacheia o resultado por 5 minutos no Redis para respeitar o rate limit
     * da Onfly (200 req/30min) e evitar latência em cada request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(
                ['message' => 'Token de autenticação não fornecido.'],
                401
            );
        }

        // ── Dev shortcut ──────────────────────────────────────────────────
        // Se o token recebido for o TOKEN_DEV, pula a validação remota e
        // injeta um usuário fictício para desenvolvimento local.
        $devToken = config('services.onfly.token_dev');
        if ($devToken && $token === $devToken) {
            $request->merge(['onfly_user' => ['id' => 0, 'name' => 'Dev User', 'email' => 'dev@local']]);
            return $next($request);
        }
        // ─────────────────────────────────────────────────────────────────

        $cacheKey = 'onfly_user_' . md5($token);

        $user = $this->resolveUser($token, $cacheKey);

        if (! $user) {

            return response()->json(
                ['message' => 'Token inválido ou expirado.'],
                401
            );
        }

        // Injeta dados do usuário no request para uso nos controllers
        $request->merge(['onfly_user' => $user]);

        return $next($request);
    }

    /**
     * Resolve o usuário a partir do token — com cache quando disponível.
     * Se o Redis estiver offline, faz a chamada direta sem cache.
     */
    private function resolveUser(string $token, string $cacheKey): ?array
    {
        $fetch = function () use ($token, $cacheKey): ?array {
            try {
                $response = Http::withToken($token)
                    ->timeout(10)
                    ->get(config('services.onfly.base_url') . '/employees/me');

                if ($response->ok()) {
                    return $response->json();
                }

                Log::warning('ValidateOnflyToken: token rejected', [
                    'status' => $response->status(),
                ]);

                return null;

            } catch (\Throwable $e) {
                Log::error('ValidateOnflyToken: HTTP request failed', [
                    'message' => $e->getMessage(),
                ]);
                return null;
            }
        };

        try {
            $user = Cache::remember($cacheKey, now()->addMinutes(5), $fetch);

            // Cache::remember pode persistir null — apaga para não bloquear retries
            if ($user === null) {
                Cache::forget($cacheKey);
            }

            return $user;

        } catch (\Throwable $e) {
            // Redis offline: cai no fetch direto sem cache
            Log::warning('ValidateOnflyToken: cache unavailable, querying directly', [
                'message' => $e->getMessage(),
            ]);
            return $fetch();
        }
    }
}
