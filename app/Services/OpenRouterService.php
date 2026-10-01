<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class OpenRouterService
{
    private Client $client;
    private string $apiKey;
    private string $model;
    private int    $maxTokens;
    private float  $temperature;

    public function __construct()
    {
        $this->apiKey      = (string) config('services.openrouter.api_key', '');
        $this->model       = config('services.openrouter.model', 'openai/gpt-4o');
        $this->maxTokens   = (int) config('services.openrouter.max_tokens', 1024);
        $this->temperature = (float) config('services.openrouter.temperature', 0.3);

        // Stay well under PHP's 60s max_execution_time so a slow/unreachable
        // provider returns a friendly error instead of a fatal timeout.
        $this->client = new Client(['timeout' => 45, 'connect_timeout' => 10]);
    }

    // ─── Main Entry Point ──────────────────────────────────────────────────────

    public function generateAnswer(
        string $userMessage,
        array  $contextChunks,
        string $locale = 'ar',
        array  $history = []
    ): string {
        $systemPrompt = $this->buildSystemPrompt($locale);
        $userContent  = $this->buildUserContent($userMessage, $contextChunks, $locale);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($history as $turn) {
            $role = $turn['role'] === 'user' ? 'user' : 'assistant';
            $messages[] = ['role' => $role, 'content' => $turn['text']];
        }

        $messages[] = ['role' => 'user', 'content' => $userContent];

        $maxAttempts   = 3;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->client->post('https://openrouter.ai/api/v1/chat/completions', [
                    'headers' => [
                        'Authorization' => "Bearer {$this->apiKey}",
                        'Content-Type'  => 'application/json',
                        'HTTP-Referer'  => 'https://gedulink.com',
                        'X-Title'       => 'GEDULink Chatbot',
                    ],
                    'json' => [
                        'model'       => $this->model,
                        'messages'    => $messages,
                        'max_tokens'  => $this->maxTokens,
                        'temperature' => $this->temperature,
                    ],
                ]);

                $body = json_decode($response->getBody()->getContents(), true);
                return $body['choices'][0]['message']['content'] ?? '';

            } catch (RequestException $e) {
                $lastException = $e;
                $status        = $e->getResponse()?->getStatusCode();
                $isTransient   = in_array($status, [429, 503], true);

                if (!$isTransient || $attempt === $maxAttempts) {
                    break;
                }

                Log::warning('[OpenRouter] Transient error, retrying', [
                    'attempt' => $attempt,
                    'status'  => $status,
                ]);

                usleep((int) (0.8 * $attempt * 1_000_000));
            }
        }

        Log::error('[OpenRouter] generateAnswer failed', ['error' => $lastException?->getMessage()]);

        return $locale === 'ar'
            ? 'عذراً، حدث خطأ في معالجة طلبك. يرجى المحاولة مرة أخرى.'
            : 'Sorry, an error occurred while processing your request. Please try again.';
    }

    // ─── Prompt Engineering ────────────────────────────────────────────────────

    private function buildSystemPrompt(string $locale): string
    {
        $lang = $locale === 'ar' ? 'Arabic' : 'English';

        return <<<PROMPT
You are GEDULink's Smart Academic Assistant — an AI advisor helping students find universities, 
programs, and study opportunities.

## CRITICAL RULES (follow strictly):

1. **Only use the provided context** — never invent universities, programs, fees, or details 
   not explicitly mentioned in the context below.

2. **Source of truth is the database** — you are the answer WRITER, not the answer SOURCE.

3. **If the context doesn't contain the answer**, say clearly:
   - Arabic: "لم أجد هذه المعلومات في قاعدة بياناتنا. يمكنك التواصل مع فريق الدعم."
   - English: "I couldn't find this information in our database. Please contact our support team."

4. **Language**: Always respond in {$lang}. If the user writes in Arabic, respond in Arabic.
   If they write in English, respond in English.

5. **Format**: Use clean, friendly formatting with emojis where appropriate. Use bullet points 
   for lists. Keep responses concise but complete.

6. **Never hallucinate** — no made-up university names, phone numbers, fees, or dates.

7. You represent GEDULink — be professional, warm, and helpful.
PROMPT;
    }

    private function buildUserContent(string $userMessage, array $contextChunks, string $locale): string
    {
        if (empty($contextChunks)) {
            return $userMessage;
        }

        $contextText = '';
        foreach ($contextChunks as $i => $chunk) {
            $num        = $i + 1;
            $text       = $chunk['text'] ?? '';
            $sourceType = $chunk['source_type'] ?? 'info';
            $contextText .= "[Context {$num} - {$sourceType}]\n{$text}\n\n";
        }

        $contextLabel  = $locale === 'ar' ? 'السياق المُسترجع من قاعدة البيانات' : 'Retrieved context from database';
        $questionLabel = $locale === 'ar' ? 'سؤال المستخدم' : 'User question';

        return <<<CONTENT
--- {$contextLabel} ---
{$contextText}
--- {$questionLabel} ---
{$userMessage}
CONTENT;
    }

    // ─── Health Check ──────────────────────────────────────────────────────────

    public function healthCheck(): array
    {
        try {
            // Short timeouts: the health endpoint must answer fast even when
            // the network is flaky.
            $this->client->get('https://openrouter.ai/api/v1/models', [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                ],
                'timeout'         => 5,
                'connect_timeout' => 3,
            ]);

            return [
                'status' => 'ok',
                'model'  => $this->model,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error'  => $e->getMessage(),
            ];
        }
    }
}