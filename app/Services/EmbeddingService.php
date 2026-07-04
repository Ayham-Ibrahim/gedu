<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * EmbeddingService
 *
 * Converts text into a numeric vector (embedding) that captures semantic meaning.
 * Supports Gemini (default) and OpenAI as embedding providers.
 *
 * NOTE (updated): Google deprecated text-embedding-004 on Jan 14, 2026.
 * The current model is gemini-embedding-001, which outputs 3072-dim vectors
 * (not 768 like the old model). Make sure GEMINI_EMBEDDING_MODEL in .env is
 * set to "models/gemini-embedding-001".
 *
 * Dimensions:
 *   - Gemini gemini-embedding-001        → 3072 dimensions
 *   - OpenAI text-embedding-3-small      → 1536 dimensions
 *   Match your Qdrant collection size to whichever you choose.
 */
class EmbeddingService
{
    private string $provider;
    private Client $client;

    // Gemini config
    private string $geminiApiKey;
    private string $geminiModel;

    // OpenAI config
    private string $openAiApiKey;
    private string $openAiModel;

    // OpenRouter config
    private string $openRouterApiKey;
    private string $openRouterModel;

    public function __construct()
    {
        $this->provider = config('services.embedding.provider', 'gemini');

        $this->geminiApiKey = (string) config('services.gemini.api_key', '');
        $this->geminiModel  = config('services.gemini.embedding_model', 'models/gemini-embedding-001');

        $this->openAiApiKey = (string) config('services.openai.api_key', '');
        $this->openAiModel  = config('services.openai.embedding_model', 'text-embedding-3-small');

        $this->openRouterApiKey = (string) config('services.openrouter.api_key', '');
        $this->openRouterModel  = config('services.openrouter.embedding_model', 'openai/text-embedding-3-small');

        $this->client = new Client(['timeout' => 60, 'connect_timeout' => 15]);
    }

    // ─── Public API ────────────────────────────────────────────────────────────

    /**
     * Embed a single text string.
     *
     * @param  string  $text  The text to embed (chunk or user query)
     * @return float[]        The embedding vector
     * @throws \Exception     If the embedding fails
     */
    public function embed(string $text): array
    {
        $text = $this->sanitize($text);

        return match ($this->provider) {
            'openai'     => $this->embedWithOpenAI($text),
            'openrouter' => $this->embedWithOpenRouter($text),
            default      => $this->embedWithGemini($text),
        };
    }

    /**
     * Embed multiple texts in batch (more efficient than calling embed() in a loop).
     * Returns an array of vectors in the same order as the input.
     *
     * @param  string[]  $texts
     * @return float[][]
     */
    public function embedBatch(array $texts): array
    {
        // Gemini supports batch natively; OpenAI is one-at-a-time
        return match ($this->provider) {
            'openai'     => $this->batchOpenAI($texts),
            'openrouter' => $this->batchOpenRouter($texts),
            default      => $this->batchGemini($texts),
        };
    }

    /**
     * Return the vector dimension for the configured model.
     * Must match the Qdrant collection vector size.
     */
    public function getDimension(): int
    {
        return match ($this->provider) {
            'openai'     => 1536,
            'openrouter' => 1536, // openai/text-embedding-3-small via OpenRouter
            default      => 3072, // gemini-embedding-001
        };
    }

    // ─── Gemini ────────────────────────────────────────────────────────────────

    private function embedWithGemini(string $text): array
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/{$this->geminiModel}:embedContent";

        $response = $this->client->post($url, [
            'headers' => ['x-goog-api-key' => $this->geminiApiKey],
            'json' => [
                'model'   => $this->geminiModel,
                'content' => [
                    'parts' => [['text' => $text]],
                ],
                // task_type RETRIEVAL_DOCUMENT for chunks, RETRIEVAL_QUERY for user questions
                // We use SEMANTIC_SIMILARITY as a safe default for both
                'taskType' => 'SEMANTIC_SIMILARITY',
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);
        return $body['embedding']['values'] ?? [];
    }

    private function batchGemini(array $texts): array
    {
        // Gemini batch embed endpoint
        $url = "https://generativelanguage.googleapis.com/v1beta/{$this->geminiModel}:batchEmbedContents";

        $requests = array_map(fn($text) => [
            'model'   => $this->geminiModel,
            'content' => ['parts' => [['text' => $this->sanitize($text)]]],
            'taskType' => 'SEMANTIC_SIMILARITY',
        ], $texts);

        try {
            $response = $this->client->post($url, [
                'headers' => ['x-goog-api-key' => $this->geminiApiKey],
                'json' => ['requests' => $requests],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            return array_map(fn($e) => $e['values'] ?? [], $body['embeddings'] ?? []);

        } catch (RequestException $e) {
            Log::warning('[Embedding] Gemini batch failed, falling back to sequential', [
                'error' => $e->getMessage(),
            ]);
            // Fallback to one-at-a-time
            return array_map(fn($text) => $this->embedWithGemini($text), $texts);
        }
    }

    // ─── OpenAI ────────────────────────────────────────────────────────────────

    private function embedWithOpenAI(string $text): array
    {
        $response = $this->client->post('https://api.openai.com/v1/embeddings', [
            'headers' => [
                'Authorization' => "Bearer {$this->openAiApiKey}",
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model' => $this->openAiModel,
                'input' => $text,
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);
        return $body['data'][0]['embedding'] ?? [];
    }

    private function batchOpenAI(array $texts): array
    {
        // OpenAI accepts an array of strings in one call
        $response = $this->client->post('https://api.openai.com/v1/embeddings', [
            'headers' => [
                'Authorization' => "Bearer {$this->openAiApiKey}",
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model' => $this->openAiModel,
                'input' => array_map([$this, 'sanitize'], $texts),
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);
        // Sort by index to preserve order
        $data = $body['data'] ?? [];
        usort($data, fn($a, $b) => $a['index'] - $b['index']);
        return array_map(fn($d) => $d['embedding'] ?? [], $data);
    }

    // ─── OpenRouter ────────────────────────────────────────────────────────────

    private function embedWithOpenRouter(string $text): array
    {
        $response = $this->client->post('https://openrouter.ai/api/v1/embeddings', [
            'headers' => [
                'Authorization' => "Bearer {$this->openRouterApiKey}",
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => 'https://gedulink.com',
                'X-Title'       => 'GEDULink Chatbot',
            ],
            'json' => [
                'model' => $this->openRouterModel,
                'input' => $text,
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);
        return $body['data'][0]['embedding'] ?? [];
    }

    private function batchOpenRouter(array $texts): array
    {
        // OpenRouter (OpenAI-compatible) accepts an array of strings in one call
        $response = $this->client->post('https://openrouter.ai/api/v1/embeddings', [
            'headers' => [
                'Authorization' => "Bearer {$this->openRouterApiKey}",
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => 'https://gedulink.com',
                'X-Title'       => 'GEDULink Chatbot',
            ],
            'json' => [
                'model' => $this->openRouterModel,
                'input' => array_map([$this, 'sanitize'], $texts),
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);
        // Sort by index to preserve order
        $data = $body['data'] ?? [];
        usort($data, fn($a, $b) => $a['index'] - $b['index']);
        return array_map(fn($d) => $d['embedding'] ?? [], $data);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Clean and truncate text before embedding.
     * Embedding models have token limits.
     */
    private function sanitize(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text);        // Collapse whitespace
        $text = mb_substr($text, 0, 8000);                 // Safety truncate
        return $text;
    }
}