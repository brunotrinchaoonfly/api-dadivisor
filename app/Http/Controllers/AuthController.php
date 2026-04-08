<?php

namespace App\Http\Controllers;

use App\Services\OnflyAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly OnflyAuthService $auth) {}

    /**
     * Autentica o usuário via Onfly OAuth2 (grant_type=password).
     *
     * POST /api/auth/login
     * Body: { "email": "user@empresa.com", "password": "senha" }
     *
     * Sucesso (200):
     *   { access_token, refresh_token, token_type, expires_in }
     *
     * Falha (401): credenciais inválidas
     * Falha (422): dados inválidos
     * Falha (503): Onfly indisponível
     */
    public function login(Request $request): JsonResponse
    {
        // ── Dev shortcut ──────────────────────────────────────────────────
        // Se TOKEN_DEV estiver definido no .env, ignora o fluxo OAuth e
        // devolve o token fixo imediatamente. Nunca deve estar em produção.
        $devToken = config('services.onfly.token_dev');
        if ($devToken) {
            return response()->json([
                'access_token'  => $devToken,
                'refresh_token' => null,
                'token_type'    => 'Bearer',
                'expires_in'    => 3600,
            ]);
        }
        // ─────────────────────────────────────────────────────────────────

        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string|min:1',
        ]);

        $result = $this->auth->login($validated['email'], $validated['password']);

        if (! $result['success']) {
            $httpStatus = match ($result['status']) {
                401, 403 => 401,
                503      => 503,
                default  => 422,
            };

            return response()->json(
                ['message' => $result['message']],
                $httpStatus
            );
        }

        return response()->json([
            'access_token'  => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'token_type'    => $result['token_type'],
            'expires_in'    => $result['expires_in'],
        ]);
    }
}
