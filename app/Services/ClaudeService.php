<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Serviço de análise de datas via Cloudflare Workers AI (Llama 3).
 *
 * Endpoint: POST https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/run/{model}
 * Resposta: { "result": { "response": "<texto>" }, "success": true }
 *
 * Quando CLOUDFLARE_AI_FALLBACK_REGEX=true, tenta extrair JSON do texto
 * da resposta com regex caso o parse direto falhe (Llama pode incluir
 * markdown ou texto antes/depois do JSON).
 */
class ClaudeService
{
    private string $apiUrl;
    private string $apiToken;
    private bool   $fallbackRegex;

    public function __construct()
    {
        $accountId          = config('services.cloudflare_ai.account_id');
        $model              = config('services.cloudflare_ai.model');
        $this->apiUrl       = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/{$model}";
        $this->apiToken     = config('services.cloudflare_ai.api_token');
        $this->fallbackRegex = (bool) config('services.cloudflare_ai.fallback_regex', true);
    }

    /**
     * Analisa cotações/histórico e retorna as 4 melhores combinações de datas.
     *
     * Nunca lança exceção — erros retornam fallback mockado.
     *
     * @param  array<string, mixed>  $params   Parâmetros validados da requisição
     * @param  array<int, array>     $priceData Cotações ou histórico de preços
     * @return array{suggestions: array, insight: string}
     */
    public function analyze(array $params, array $priceData): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiToken}",
                'Content-Type'  => 'application/json',
            ])
            ->timeout(30)
            ->post($this->apiUrl, [
                'max_tokens' => 1024,
                'messages'   => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user',   'content' => $this->buildPrompt($params, $priceData)],
                ],
            ]);

            if ($response->failed()) {
                Log::error('ClaudeService: Cloudflare AI error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return $this->fallback($params);
            }

            $text = $response->json('result.response', '');

            Log::debug('ClaudeService: raw AI response', [
                'length' => strlen($text),
                'preview' => substr($text, 0, 300),
            ]);

            $decoded = $this->parseJson($text);

            if ($decoded === null || empty($decoded['suggestions'])) {
                Log::error('ClaudeService: JSON parse failed', [
                    'text' => substr($text, 0, 500),
                ]);
                return $this->fallback($params);
            }

            return $decoded;

        } catch (\Throwable $e) {
            Log::error('ClaudeService: exception', ['message' => $e->getMessage()]);
            return $this->fallback($params);
        }
    }

    // -------------------------------------------------------------------------
    //  Prompt
    // -------------------------------------------------------------------------

    private function systemPrompt(): string
    {
        return 'Você é um assistente de viagens corporativas da Onfly. '
            . 'Analise os dados de preços e retorne as melhores combinações de datas. '
            . 'Responda APENAS com um objeto JSON válido — NÃO use array na raiz (não comece com [). '
            . 'Sem markdown, sem texto antes ou depois, sem backticks. '
            . 'O JSON deve começar com { e terminar com }.';
    }

    private function buildPrompt(array $params, array $priceData): string
    {
        $priceJson = json_encode(
            array_slice($priceData, 0, 50),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

        $dataLabel = ($params['data_source'] ?? 'history') === 'quote'
            ? 'Cotações reais por combinação de datas em BRL. Campos: price_brl = tarifa base, total_price_brl = total com taxas. Ordene pelo menor total_price_brl:'
            : 'Histórico de reservas para esse trecho em BRL (use para inferir padrões de preço):';

        return <<<PROMPT
Modalidade: {$params['modality']}
Trecho: {$params['origin']} → {$params['destination']}
Data pretendida de ida: {$params['date_from']}
Data pretendida de volta: {$params['date_to']}
Flexibilidade de ida: ±{$params['flexibility_from']} dias
Flexibilidade de volta: ±{$params['flexibility_to']} dias
Viajantes: {$params['travelers']}

{$dataLabel}
{$priceJson}

Retorne SOMENTE este JSON (sem nenhum texto extra):
{"suggestions":[{"date_from":"YYYY-MM-DD","date_to":"YYYY-MM-DD","price":0.00,"savings":0.00,"label":"string"}],"insight":"string"}

Regras:
- Exatamente 4 sugestões no array suggestions
- Ordene por menor price (index 0 = mais barato)
- label[0]="Melhor opção", demais="2ª opção","3ª opção","4ª opção"
- savings = preço da data exata pedida - price (positivo = economia, negativo = mais caro)
- price = campo total_price_brl ou price_brl dos dados, em reais
- insight: máximo 2 frases em português explicando por que essas datas são mais baratas
PROMPT;
    }

    // -------------------------------------------------------------------------
    //  Parse JSON com fallback regex (Llama às vezes inclui markdown)
    // -------------------------------------------------------------------------

    private function parseJson(string $text): ?array
    {
        // Tentativa 1: parse direto
        $decoded = json_decode(trim($text), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // Llama às vezes retorna [{ "suggestions": [...] }] em vez de { "suggestions": [...] }
            if (array_key_exists(0, $decoded) && is_array($decoded[0])) {
                $decoded = $decoded[0];
            }
            return $decoded;
        }

        if (! $this->fallbackRegex) {
            return null;
        }

        // Tentativa 2: extrai bloco JSON entre { } via regex
        if (preg_match('/\{[\s\S]*"suggestions"[\s\S]*\}/u', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                Log::debug('ClaudeService: JSON extraído via regex');
                return $decoded;
            }
        }

        // Tentativa 3: remove markdown code fences e tenta novamente
        $cleaned = preg_replace('/```(?:json)?\s*([\s\S]*?)\s*```/', '$1', $text);
        $decoded  = json_decode(trim($cleaned ?? ''), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            Log::debug('ClaudeService: JSON extraído após remover markdown');
            if (array_key_exists(0, $decoded) && is_array($decoded[0])) {
                return $decoded[0];
            }
            return $decoded;
        }

        // Tentativa 4: Llama às vezes esquece o } antes do ] final
        // Padrão inválido: [{"suggestions":[...],"insight":"..."]  (falta })
        $repaired = rtrim($text);
        if (str_ends_with($repaired, ']')) {
            $candidate = substr($repaired, 0, -1) . '}]';
            $decoded   = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                Log::debug('ClaudeService: JSON reparado (} faltando antes de ])');
                if (array_key_exists(0, $decoded) && is_array($decoded[0])) {
                    return $decoded[0];
                }
                return $decoded;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    //  Fallback mockado
    // -------------------------------------------------------------------------

    /**
     * @return array{suggestions: array, insight: string}
     */
    private function fallback(array $params): array
    {
        $base   = 1200.00;
        $offset = fn (string $date, int $days): string =>
            date('Y-m-d', strtotime("{$date} {$days} days"));

        return [
            'suggestions' => [
                ['date_from' => $params['date_from'], 'date_to' => $params['date_to'],          'price' => $base,                   'savings' => 0.00,              'label' => 'Melhor opção'],
                ['date_from' => $offset($params['date_from'], 1),  'date_to' => $offset($params['date_to'], 1),  'price' => round($base * 1.05, 2), 'savings' => round(-$base * 0.05, 2), 'label' => '2ª opção'],
                ['date_from' => $offset($params['date_from'], -1), 'date_to' => $offset($params['date_to'], -1), 'price' => round($base * 1.10, 2), 'savings' => round(-$base * 0.10, 2), 'label' => '3ª opção'],
                ['date_from' => $offset($params['date_from'], 2),  'date_to' => $offset($params['date_to'], 2),  'price' => round($base * 1.15, 2), 'savings' => round(-$base * 0.15, 2), 'label' => '4ª opção'],
            ],
            'insight' => 'Não foi possível analisar os dados no momento. Exibindo estimativas baseadas na data solicitada.',
        ];
    }
}
