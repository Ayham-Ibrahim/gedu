<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Qdrant Vector Database
    |--------------------------------------------------------------------------
    */
    'qdrant' => [
        'host'       => env('QDRANT_HOST', 'http://localhost'),
        'port'       => env('QDRANT_PORT', 6333),
        'collection' => env('QDRANT_COLLECTION', 'gedu_knowledge'),
        'api_key'    => env('QDRANT_API_KEY'),  // Optional, for secured Qdrant
    ],

    /*
    |--------------------------------------------------------------------------
    | Gemini (Google AI) — Answer generation + Embeddings
    |--------------------------------------------------------------------------
    */
    'gemini' => [
        'api_key'         => env('GEMINI_API_KEY'),
        'model'           => env('GEMINI_MODEL', 'gemini-2.5-flash-lite'),
        'max_tokens'      => env('GEMINI_MAX_TOKENS', 1024),
        'temperature'     => env('GEMINI_TEMPERATURE', 0.3),
        'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'models/text-embedding-004'),
    ],
    'deepseek' => [
        'api_key'     => env('DEEPSEEK_API_KEY'),
        'model'       => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        'max_tokens'  => env('DEEPSEEK_MAX_TOKENS', 1024),
        'temperature' => env('DEEPSEEK_TEMPERATURE', 0.3),
    ],
  'openrouter' => [
    'api_key'         => env('OPENROUTER_API_KEY'),
    'model'           => env('OPENROUTER_MODEL', 'openai/gpt-4o'),
    'max_tokens'      => env('OPENROUTER_MAX_TOKENS', 1024),
    'temperature'     => env('OPENROUTER_TEMPERATURE', 0.3),
    'embedding_model' => env('OPENROUTER_EMBEDDING_MODEL', 'openai/text-embedding-3-small'),
],
    /*
    |--------------------------------------------------------------------------
    | OpenAI — Alternative embedding provider
    |--------------------------------------------------------------------------
    */
    'openai' => [
        'api_key'         => env('OPENAI_API_KEY'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding Provider
    |--------------------------------------------------------------------------
    | Options: 'gemini' | 'openai'
    | Make sure the vector dimension matches your Qdrant collection!
    |   gemini  → 768 dimensions
    |   openai  → 1536 dimensions
    */
    'embedding' => [
        'provider' => env('EMBEDDING_PROVIDER', 'gemini'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin API Token
    |--------------------------------------------------------------------------
    */
    'admin' => [
        'api_token' => env('ADMIN_API_TOKEN'),
    ],

];
