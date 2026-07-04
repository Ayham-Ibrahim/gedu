<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Middleware\AdminTokenMiddleware;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| GEDU RAG API Routes
|--------------------------------------------------------------------------
|
| Public endpoints (used by the React chatbot):
|   POST  /api/chat          ← Replaces Rasa /webhooks/rest/webhook
|   GET   /api/health        ← Replaces Rasa /healthz
|
| Admin endpoints (protected by ADMIN_API_TOKEN):
|   POST  /api/admin/knowledge/rebuild
|   POST  /api/admin/knowledge/sync/university/{id}
|   POST  /api/admin/knowledge/sync/program/{id}
|   POST  /api/admin/knowledge/sync/course/{id}
|   POST  /api/admin/knowledge/sync/support/{id}
|   GET   /api/admin/knowledge/stats
|
*/

// ── Public Routes ─────────────────────────────────────────────────────────────

Route::post('/chat',   [ChatController::class, 'chat']);
Route::get('/health',  [ChatController::class, 'health']);

// ── Admin Routes (Bearer token required) ──────────────────────────────────────

Route::middleware(AdminTokenMiddleware::class)->prefix('admin')->group(function () {

    Route::prefix('knowledge')->group(function () {
        Route::post('rebuild',               [KnowledgeController::class, 'rebuild']);
        Route::get('stats',                  [KnowledgeController::class, 'stats']);
        Route::post('sync/university/{id}',  [KnowledgeController::class, 'syncUniversity']);
        Route::post('sync/program/{id}',     [KnowledgeController::class, 'syncProgram']);
        Route::post('sync/course/{id}',      [KnowledgeController::class, 'syncCourse']);
        Route::post('sync/support/{id}',     [KnowledgeController::class, 'syncSupport']);
    });

});
