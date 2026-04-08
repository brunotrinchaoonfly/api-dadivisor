<?php

namespace Tests\Feature;

use App\Services\OnflyAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    // ------------------------------------------------------------------ //
    //  POST /api/auth/login — validação de input                          //
    // ------------------------------------------------------------------ //

    public function test_login_returns_422_when_email_is_missing(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'password' => 'secret',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
    }

    public function test_login_returns_422_when_email_is_invalid(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'not-an-email',
            'password' => 'secret',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['email']);
    }

    public function test_login_returns_422_when_password_is_missing(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'user@empresa.com',
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['password']);
    }

    // ------------------------------------------------------------------ //
    //  POST /api/auth/login — autenticação bem-sucedida                   //
    // ------------------------------------------------------------------ //

    public function test_login_returns_tokens_on_success(): void
    {
        $this->mock(OnflyAuthService::class, function ($mock) {
            $mock->shouldReceive('login')
                 ->once()
                 ->with('user@empresa.com', 'senha123')
                 ->andReturn([
                     'success'       => true,
                     'access_token'  => 'access-abc',
                     'refresh_token' => 'refresh-xyz',
                     'token_type'    => 'Bearer',
                     'expires_in'    => 3600,
                 ]);
        });

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'user@empresa.com',
            'password' => 'senha123',
        ]);

        $response->assertOk()
                 ->assertJson([
                     'access_token'  => 'access-abc',
                     'refresh_token' => 'refresh-xyz',
                     'token_type'    => 'Bearer',
                     'expires_in'    => 3600,
                 ]);
    }

    // ------------------------------------------------------------------ //
    //  POST /api/auth/login — credenciais inválidas                       //
    // ------------------------------------------------------------------ //

    public function test_login_returns_401_on_invalid_credentials(): void
    {
        $this->mock(OnflyAuthService::class, function ($mock) {
            $mock->shouldReceive('login')
                 ->once()
                 ->andReturn([
                     'success' => false,
                     'status'  => 401,
                     'message' => 'Credenciais inválidas.',
                 ]);
        });

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'user@empresa.com',
            'password' => 'errada',
        ]);

        $response->assertStatus(401)
                 ->assertJson(['message' => 'Credenciais inválidas.']);
    }

    public function test_login_returns_503_when_onfly_is_unavailable(): void
    {
        $this->mock(OnflyAuthService::class, function ($mock) {
            $mock->shouldReceive('login')
                 ->once()
                 ->andReturn([
                     'success' => false,
                     'status'  => 503,
                     'message' => 'Serviço de autenticação indisponível.',
                 ]);
        });

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'user@empresa.com',
            'password' => 'senha123',
        ]);

        $response->assertStatus(503)
                 ->assertJson(['message' => 'Serviço de autenticação indisponível.']);
    }
}
