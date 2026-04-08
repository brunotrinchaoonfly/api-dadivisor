<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OnflyAuthService
{
    /**
     * Fluxo completo de autenticação Onfly em 4 passos:
     *
     *  1. POST /oauth/token (password grant) → passport_access_token
     *  2. GET  /auth/token/internal           → internal_token (EdDSA JWT)
     *  3. GET  /employees/me                  → user_id
     *  4. POST gateway /auth/internal/user    → access_token (UserToken) + refresh_token
     */
    public function login(string $email, string $password): array
    {
        // ── Passo 1: Passport password grant ─────────────────────────────
        $passport = $this->passportLogin($email, $password);
        if (! $passport['success']) {
            return $passport;
        }

        // ── Passo 2: Internal token (EdDSA) ──────────────────────────────
        $internal = $this->getInternalToken($passport['access_token']);
        if (! $internal['success']) {
            return $internal;
        }

        // ── Passo 3: Perfil do usuário (user_id) ─────────────────────────
        $profile = $this->getUserProfile($passport['access_token']);
        if (! $profile['success']) {
            return $profile;
        }

        // ── Passo 4: UserToken via gateway ────────────────────────────────
        return $this->getGatewayToken($internal['token'], $profile['user_id']);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Passo 1 — Passport password grant
    // ─────────────────────────────────────────────────────────────────────

    private function passportLogin(string $email, string $password): array
    {
        try {
            $response = Http::timeout(15)
                ->post(config('services.onfly.base_url') . '/oauth/token', [
                    'grant_type'    => 'password',
                    'scope'         => '*',
                    // 'client_id'     => config('services.onfly.client_id'),
                    // 'client_secret' => config('services.onfly.client_secret'),
                    'username'      => $email,
                    'password'      => $password,
                ]);

            if ($response->failed()) {
                Log::warning('OnflyAuthService@passportLogin: falhou', [
                    'status' => $response->status(),
                    'email'  => $email,
                ]);

                return [
                    'success' => false,
                    'status'  => $response->status(),
                    'message' => $response->json('message') ?? 'Credenciais inválidas.',
                ];
            }

            return [
                'success'      => true,
                'access_token' => $response->json('access_token'),
            ];

        } catch (\Throwable $e) {
            Log::error('OnflyAuthService@passportLogin: exception', ['message' => $e->getMessage()]);

            return ['success' => false, 'status' => 503, 'message' => 'Serviço de autenticação indisponível.'];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Passo 2 — Internal token (EdDSA JWT via API Onfly)
    // ─────────────────────────────────────────────────────────────────────

    private function getInternalToken(string $passportToken): array
    {
        try {
            $response = Http::withToken($passportToken)
                ->timeout(10)
                ->withHeaders(['Accept' => 'application/prs.onfly.v1+json'])
                ->get(config('services.onfly.base_url') . '/auth/token/internal');

            if ($response->failed()) {
                Log::warning('OnflyAuthService@getInternalToken: falhou', ['status' => $response->status()]);

                return [
                    'success' => false,
                    'status'  => 502,
                    'message' => 'Não foi possível obter o token interno Onfly.',
                ];
            }

            return [
                'success' => true,
                'token'   => $response->json('token'),
            ];

        } catch (\Throwable $e) {
            Log::error('OnflyAuthService@getInternalToken: exception', ['message' => $e->getMessage()]);

            return ['success' => false, 'status' => 503, 'message' => 'Serviço de autenticação indisponível.'];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Passo 3 — Perfil do usuário (user_id)
    // ─────────────────────────────────────────────────────────────────────

    private function getUserProfile(string $passportToken): array
    {
        try {
            $response = Http::withToken($passportToken)
                ->timeout(10)
                ->get(config('services.onfly.base_url') . '/employees/me');

            if ($response->failed()) {
                Log::warning('OnflyAuthService@getUserProfile: falhou', ['status' => $response->status()]);

                return [
                    'success' => false,
                    'status'  => 502,
                    'message' => 'Não foi possível obter o perfil do usuário.',
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'user_id' => (string) ($data['id'] ?? $data['data']['id'] ?? null),
                'email'   => $data['email'] ?? $data['data']['email'] ?? null,
                'name'    => $data['name'] ?? $data['data']['name'] ?? null,
            ];

        } catch (\Throwable $e) {
            Log::error('OnflyAuthService@getUserProfile: exception', ['message' => $e->getMessage()]);

            return ['success' => false, 'status' => 503, 'message' => 'Serviço de autenticação indisponível.'];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Passo 4 — UserToken via gateway Onfly
    //
    //  POST /auth/internal/user?NO_AUTO_GENERATE_TOKENS&AUTO_GENERATE_TOKENS=true
    // ─────────────────────────────────────────────────────────────────────

    private function getGatewayToken(string $internalToken, string $userId): array
    {
        try {
            $url = config('services.onfly.gateway_url')
                . '/auth/internal/user'
                . '?NO_AUTO_GENERATE_TOKENS&AUTO_GENERATE_TOKENS=true';

            $response = Http::withToken($internalToken)
                ->timeout(15)
                ->post($url, ['userId' => $userId]);

            if ($response->failed()) {
                Log::warning('OnflyAuthService@getGatewayToken: falhou', [
                    'status'  => $response->status(),
                    'user_id' => $userId,
                ]);

                return [
                    'success' => false,
                    'status'  => $response->status(),
                    'message' => 'Não foi possível gerar o token de acesso.',
                ];
            }

            $data = $response->json('data');

            return [
                'success'       => true,
                'access_token'  => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'token_type'    => $data['token_type'] ?? 'Bearer',
                'expires_in'    => $data['expired_in'] ?? 3600,
            ];

        } catch (\Throwable $e) {
            Log::error('OnflyAuthService@getGatewayToken: exception', ['message' => $e->getMessage()]);

            return ['success' => false, 'status' => 503, 'message' => 'Serviço de autenticação indisponível.'];
        }
    }
}
