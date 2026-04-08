<?php

namespace Tests\Unit;

use App\Services\OnflyAuthService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnflyAuthServiceTest extends TestCase
{
    private OnflyAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OnflyAuthService();
    }

    // ------------------------------------------------------------------ //
    //  Fluxo completo — happy path                                        //
    // ------------------------------------------------------------------ //

    public function test_login_returns_gateway_user_token_on_success(): void
    {
        Http::fake([
            '*/oauth/token'          => Http::response(['access_token' => 'passport-tok'], 200),
            '*/auth/token/internal'  => Http::response(['token' => 'internal-tok'], 200),
            '*/employees/me'         => Http::response(['id' => 42, 'email' => 'user@test.com', 'name' => 'Test'], 200),
            '*/auth/internal/user*'  => Http::response([
                'data' => [
                    'access_token'  => 'gateway-access',
                    'refresh_token' => 'gateway-refresh',
                    'token_type'    => 'Bearer',
                    'expired_in'    => 3600,
                ],
            ], 200),
        ]);

        $result = $this->service->login('user@test.com', 'senha123');

        $this->assertTrue($result['success']);
        $this->assertEquals('gateway-access', $result['access_token']);
        $this->assertEquals('gateway-refresh', $result['refresh_token']);
        $this->assertEquals('Bearer', $result['token_type']);
        $this->assertEquals(3600, $result['expires_in']);
    }

    public function test_login_sends_correct_payload_to_passport(): void
    {
        Http::fake([
            '*/oauth/token'         => Http::response(['access_token' => 'tok'], 200),
            '*/auth/token/internal' => Http::response(['token' => 'int'], 200),
            '*/employees/me'        => Http::response(['id' => 1], 200),
            '*/auth/internal/user*' => Http::response(['data' => ['access_token' => 'gt', 'refresh_token' => 'rt', 'expired_in' => 3600]], 200),
        ]);

        $this->service->login('user@test.com', 'senha123');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/oauth/token')
                && $request['grant_type']    === 'password'
                && $request['scope']         === '*'
                && $request['username']      === 'user@test.com'
                && $request['password']      === 'senha123'
                && $request['client_id']     === config('services.onfly.client_id')
                && $request['client_secret'] === config('services.onfly.client_secret');
        });
    }

    public function test_login_sends_internal_token_to_gateway_with_user_id(): void
    {
        Http::fake([
            '*/oauth/token'         => Http::response(['access_token' => 'passport-tok'], 200),
            '*/auth/token/internal' => Http::response(['token' => 'internal-tok'], 200),
            '*/employees/me'        => Http::response(['id' => 106], 200),
            '*/auth/internal/user*' => Http::response(['data' => ['access_token' => 'gt', 'refresh_token' => 'rt', 'expired_in' => 3600]], 200),
        ]);

        $this->service->login('user@test.com', 'senha');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/auth/internal/user')
                && $request->hasHeader('Authorization', 'Bearer internal-tok')
                && $request['userId'] === '106';
        });
    }

    // ------------------------------------------------------------------ //
    //  Falha no passo 1 — credenciais inválidas                           //
    // ------------------------------------------------------------------ //

    public function test_login_returns_401_when_passport_rejects_credentials(): void
    {
        Http::fake([
            '*/oauth/token' => Http::response(['message' => 'Credenciais inválidas.'], 401),
        ]);

        $result = $this->service->login('user@test.com', 'errada');

        $this->assertFalse($result['success']);
        $this->assertEquals(401, $result['status']);
    }

    // ------------------------------------------------------------------ //
    //  Falha no passo 2 — internal token indisponível                     //
    // ------------------------------------------------------------------ //

    public function test_login_returns_502_when_internal_token_fails(): void
    {
        Http::fake([
            '*/oauth/token'         => Http::response(['access_token' => 'tok'], 200),
            '*/auth/token/internal' => Http::response([], 500),
        ]);

        $result = $this->service->login('user@test.com', 'senha');

        $this->assertFalse($result['success']);
        $this->assertEquals(502, $result['status']);
    }

    // ------------------------------------------------------------------ //
    //  Falha no passo 3 — perfil indisponível                             //
    // ------------------------------------------------------------------ //

    public function test_login_returns_502_when_user_profile_fails(): void
    {
        Http::fake([
            '*/oauth/token'         => Http::response(['access_token' => 'tok'], 200),
            '*/auth/token/internal' => Http::response(['token' => 'int'], 200),
            '*/employees/me'        => Http::response([], 401),
        ]);

        $result = $this->service->login('user@test.com', 'senha');

        $this->assertFalse($result['success']);
        $this->assertEquals(502, $result['status']);
    }

    // ------------------------------------------------------------------ //
    //  Falha no passo 4 — gateway indisponível                            //
    // ------------------------------------------------------------------ //

    public function test_login_returns_failure_when_gateway_fails(): void
    {
        Http::fake([
            '*/oauth/token'         => Http::response(['access_token' => 'tok'], 200),
            '*/auth/token/internal' => Http::response(['token' => 'int'], 200),
            '*/employees/me'        => Http::response(['id' => 1], 200),
            '*/auth/internal/user*' => Http::response([], 503),
        ]);

        $result = $this->service->login('user@test.com', 'senha');

        $this->assertFalse($result['success']);
        $this->assertEquals(503, $result['status']);
    }

    // ------------------------------------------------------------------ //
    //  Exceção de rede                                                     //
    // ------------------------------------------------------------------ //

    public function test_login_returns_503_on_connection_error(): void
    {
        Http::fake([
            '*/oauth/token' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
            },
        ]);

        $result = $this->service->login('user@test.com', 'senha123');

        $this->assertFalse($result['success']);
        $this->assertEquals(503, $result['status']);
    }
}
