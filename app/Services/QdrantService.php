<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * QdrantService
 *
 * Handles all communication with the Qdrant vector database.
 *
 * Qdrant stores "points" — each point has:
 *   - id       : unique integer or UUID
 *   - vector   : float[] from the embedding model
 *   - payload  : arbitrary JSON (we store the human-readable text chunk here)
 *
 * Flow:
 *   1. EmbeddingService converts a text chunk → float[]
 *   2. QdrantService.upsert() stores that vector + metadata
 *   3. QdrantService.search() finds the closest vectors to a query vector
 *   4. The payload text is returned to GeminiService as context
 */
class QdrantService
{
    private Client $client;
    private string $collection;
    private string $baseUrl;

    public function __construct()
    {
        $host = config('services.qdrant.host', 'http://localhost');
        $port = config('services.qdrant.port', 6333);
        $this->baseUrl = "{$host}:{$port}";
        $this->collection = config('services.qdrant.collection', 'gedu_knowledge');

        $headers = ['Content-Type' => 'application/json'];
        $apiKey = config('services.qdrant.api_key');
        if ($apiKey) {
            $headers['api-key'] = $apiKey;
        }

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => 30,
            'headers'  => $headers,
        ]);
    }

    // ─── Collection Management ─────────────────────────────────────────────────

    /**
     * Create the Qdrant collection if it doesn't exist.
     * Call once during setup or from the knowledge:build artisan command.
     *
     * @param int $vectorSize  Must match your embedding model output dimension.
     *                         Gemini text-embedding-004 → 768
     *                         OpenAI text-embedding-3-small → 1536
     */
    public function createCollectionIfNotExists(int $vectorSize = 768): bool
    {
        // Check if collection already exists
        try {
            $response = $this->client->get("/collections/{$this->collection}");
            $body = json_decode($response->getBody()->getContents(), true);
            if (isset($body['result']['name'])) {
                Log::info("[Qdrant] Collection '{$this->collection}' already exists.");
                return true;
            }
        } catch (RequestException $e) {
            if ($e->hasResponse() && $e->getResponse()->getStatusCode() === 404) {
                // Does not exist yet — create it
            } else {
                Log::error('[Qdrant] createCollectionIfNotExists check failed', ['error' => $e->getMessage()]);
                throw $e;
            }
        }

        // Create the collection
        try {
            $response = $this->client->put("/collections/{$this->collection}", [
                'json' => [
                    'vectors' => [
                        'size'     => $vectorSize,
                        'distance' => 'Cosine',   // Best for semantic similarity
                    ],
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            Log::info("[Qdrant] Collection '{$this->collection}' created.", $body);
            return true;

        } catch (RequestException $e) {
            Log::error('[Qdrant] Failed to create collection', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Delete and recreate the collection (used when rebuilding from scratch).
     */
    public function recreateCollection(int $vectorSize = 768): bool
    {
        try {
            $this->client->delete("/collections/{$this->collection}");
            Log::info("[Qdrant] Collection '{$this->collection}' deleted.");
        } catch (RequestException $e) {
            // Ignore 404 — collection may not exist yet
            if (!$e->hasResponse() || $e->getResponse()->getStatusCode() !== 404) {
                throw $e;
            }
        }

        return $this->createCollectionIfNotExists($vectorSize);
    }

    // ─── Upsert / Delete ───────────────────────────────────────────────────────

    /**
     * Insert or update a single point (one knowledge chunk).
     *
     * @param  int|string  $id       Unique ID for this chunk
     * @param  float[]     $vector   Embedding vector
     * @param  array       $payload  Metadata (source_type, source_id, text, etc.)
     */
    public function upsertPoint(int|string $id, array $vector, array $payload): bool
    {
        try {
            $response = $this->client->put("/collections/{$this->collection}/points", [
                'json' => [
                    'points' => [[
                        'id'      => $id,
                        'vector'  => $vector,
                        'payload' => $payload,
                    ]],
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            return ($body['status'] ?? '') === 'ok';

        } catch (RequestException $e) {
            Log::error('[Qdrant] upsertPoint failed', [
                'id'    => $id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Batch upsert multiple points in one request (faster for bulk operations).
     *
     * @param  array  $points  Each item: ['id' => ..., 'vector' => [...], 'payload' => [...]]
     */
    public function upsertPoints(array $points): bool
    {
        if (empty($points)) {
            return true;
        }

        try {
            $response = $this->client->put("/collections/{$this->collection}/points", [
                'json' => ['points' => $points],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            return ($body['status'] ?? '') === 'ok';

        } catch (RequestException $e) {
            Log::error('[Qdrant] upsertPoints failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Delete a point by ID (call when a record is deleted from DB).
     */
    public function deletePoint(int|string $id): bool
    {
        try {
            $this->client->post("/collections/{$this->collection}/points/delete", [
                'json' => ['points' => [$id]],
            ]);
            return true;

        } catch (RequestException $e) {
            Log::error('[Qdrant] deletePoint failed', ['id' => $id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Delete all points with a matching payload filter.
     * Useful for deleting all chunks belonging to one university/program.
     *
     * @param  string  $field  Payload field name (e.g. 'source_type')
     * @param  mixed   $value  Value to match (e.g. 'university')
     */
    public function deleteByPayload(string $field, mixed $value): bool
    {
        try {
            $this->client->post("/collections/{$this->collection}/points/delete", [
                'json' => [
                    'filter' => [
                        'must' => [[
                            'key'   => $field,
                            'match' => ['value' => $value],
                        ]],
                    ],
                ],
            ]);
            return true;

        } catch (RequestException $e) {
            Log::error('[Qdrant] deleteByPayload failed', [
                'field' => $field,
                'value' => $value,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ─── Search ────────────────────────────────────────────────────────────────

    /**
     * Find the top-K most similar vectors to the given query vector.
     *
     * Returns array of payloads from the closest matching chunks.
     *
     * @param  float[]  $queryVector   Embedding of the user's question
     * @param  int      $topK          Number of results to return (default 5)
     * @param  float    $scoreThreshold  Minimum similarity score (0–1). Lower = more permissive.
     * @return array    Array of payload arrays from matching chunks
     */
    public function search(array $queryVector, int $topK = 5, float $scoreThreshold = 0.5): array
    {
        try {
            $response = $this->client->post("/collections/{$this->collection}/points/search", [
                'json' => [
                    'vector'           => $queryVector,
                    'limit'            => $topK,
                    'score_threshold'  => $scoreThreshold,
                    'with_payload'     => true,
                    'with_vector'      => false,  // Save bandwidth — we don't need vectors back
                ],
            ]);

            $body   = json_decode($response->getBody()->getContents(), true);
            $result = $body['result'] ?? [];

            // Return just the payloads (the text chunks + metadata)
            return array_map(fn($hit) => array_merge(
                $hit['payload'] ?? [],
                ['_score' => $hit['score'] ?? 0]
            ), $result);

        } catch (RequestException $e) {
            Log::error('[Qdrant] search failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ─── Health Check ──────────────────────────────────────────────────────────

    /**
     * Ping Qdrant and return status info.
     * Used by GET /api/health
     */
    public function healthCheck(): array
    {
        try {
            $response = $this->client->get('/');
            $body = json_decode($response->getBody()->getContents(), true);

            return [
                'status'  => 'connected',
                'version' => $body['version'] ?? 'unknown',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error'  => $e->getMessage(),
            ];
        }
    }

    // ─── Stats ─────────────────────────────────────────────────────────────────

    /**
     * Get the number of points in the collection.
     */
    public function getPointCount(): int
    {
        try {
            $response = $this->client->get("/collections/{$this->collection}");
            $body = json_decode($response->getBody()->getContents(), true);
            return $body['result']['points_count'] ?? 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
