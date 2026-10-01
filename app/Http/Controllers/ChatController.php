<?php

namespace App\Http\Controllers;

use App\Services\EmbeddingService;
use App\Services\OpenRouterService;
use App\Services\QdrantService;
use App\Services\BroadQueryDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * ChatController
 *
 * Handles POST /api/chat — the main chatbot endpoint.
 *
 * RAG Pipeline:
 *   1. Validate & extract user message
 *   2. If the question is a "list everything" style question, bypass vector
 *      search and build context directly from the database (BroadQueryDetector)
 *   3. Otherwise: embed the user's message → vector search Qdrant top-K chunks
 *   4. Pass chunks + question to OpenRouter
 *   5. Return the generated answer
 */
class ChatController extends Controller
{
    public function __construct(
        private readonly EmbeddingService   $embedder,
        private readonly QdrantService      $qdrant,
        private readonly OpenRouterService  $openrouter,
        private readonly BroadQueryDetector $broadQuery,
    ) {}

    /**
     * POST /api/chat
     *
     * Request body:
     * {
     *   "sender":  "user_xyz",
     *   "message": "...",
     *   "locale":  "ar" | "en",
     *   "history": [ { "role": "user"|"assistant", "text": "..." } ]
     * }
     *
     * Response (Rasa-compatible):
     * { "replies": [{ "text": "..." }] }
     */
    public function chat(Request $request): JsonResponse
    {
        // ── 1. Validate ────────────────────────────────────────────────────────
        $validator = Validator::make($request->all(), [
            'sender'  => 'required|string|max:100',
            'message' => 'required|string|max:2000',
            'locale'  => 'nullable|string|in:ar,en',
            'history' => 'nullable|array|max:20',
            'history.*.role' => 'required|string|in:user,assistant',
            'history.*.text' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'replies' => [['text' => 'Invalid request format.']],
                'errors'  => $validator->errors(),
            ], 422);
        }

        $message = trim($request->input('message'));
        $locale  = $request->input('locale', 'ar');
        $sender  = $request->input('sender');
        $history = $request->input('history', []);

        Log::info('[Chat] Incoming message', [
            'sender' => $sender,
            'locale' => $locale,
            'message_length' => mb_strlen($message),
        ]);

        try {
            // ── 2. Broad query detection (bypass vector search) ────────────────
            $isBroad = $this->broadQuery->isBroadQuery($message);

            if ($isBroad) {
                $contextChunks = $this->broadQuery->buildFullContext();

                Log::info('[Chat] Broad query detected — using full DB context', [
                    'chunks_count' => count($contextChunks),
                ]);
            } else {
                // ── 3. Embed + search Qdrant for relevant context ──────────────
                $queryVector = $this->embedder->embed($message);

                if (empty($queryVector)) {
                    throw new \RuntimeException('Failed to generate embedding for the message.');
                }

                $contextChunks = $this->qdrant->search(
                    queryVector    : $queryVector,
                    topK           : 5,
                    scoreThreshold : 0.2,
                );

                Log::info('[Chat] Qdrant results', [
                    'count'  => count($contextChunks),
                    'scores' => array_column($contextChunks, '_score'),
                ]);
            }

            // ── 4. Generate answer with OpenRouter ──────────────────────────────
            $answer = $this->openrouter->generateAnswer(
                userMessage    : $message,
                contextChunks  : $contextChunks,
                locale         : $locale,
                history        : $history,
            );

            if (empty(trim($answer))) {
                $answer = $locale === 'ar'
                    ? 'عذراً، لم أتمكن من إيجاد إجابة مناسبة. يرجى إعادة صياغة سؤالك.'
                    : 'Sorry, I could not find a suitable answer. Please try rephrasing your question.';
            }

            // ── 5. Return Rasa-compatible response ─────────────────────────────
            return response()->json([
                'replies' => [['text' => $answer]],
                'meta' => [
                    'chunks_found'  => count($contextChunks),
                    'provider'      => 'rag',
                    'mode'          => $isBroad ? 'full_directory' : 'vector_search',
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('[Chat] Pipeline error', [
                'sender' => $sender,
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
            ]);

            $errorMsg = $locale === 'ar'
                ? 'حدث خطأ تقني. يرجى المحاولة مرة أخرى لاحقاً.'
                : 'A technical error occurred. Please try again later.';

            return response()->json([
                'replies' => [['text' => $errorMsg]],
            ], 500);
        }
    }

    /**
     * GET /api/health
     */
    public function health(): JsonResponse
    {
        $qdrantStatus = $this->qdrant->healthCheck();

        // OpenRouter is a remote API: cache its status briefly so every widget
        // open doesn't make an outbound call, and only cache successes so an
        // outage is re-checked on the next request.
        $openrouterStatus = Cache::get('health:openrouter');
        if ($openrouterStatus === null) {
            $openrouterStatus = $this->openrouter->healthCheck();
            if ($openrouterStatus['status'] === 'ok') {
                Cache::put('health:openrouter', $openrouterStatus, now()->addSeconds(60));
            }
        }

        $qdrantOk     = $qdrantStatus['status'] === 'connected';
        $openrouterOk = $openrouterStatus['status'] === 'ok';

        // 503 only when the local knowledge base is down; a transient
        // OpenRouter hiccup is reported as "degraded" but still 200.
        return response()->json([
            'status'            => $qdrantOk && $openrouterOk ? 'ok' : 'degraded',
            'qdrant'            => $qdrantStatus['status'],
            'openrouter'        => $openrouterStatus['status'],
            'knowledge_points'  => $qdrantOk ? $this->qdrant->getPointCount() : 0,
            'timestamp'         => now()->toISOString(),
        ], $qdrantOk ? 200 : 503);
    }
}