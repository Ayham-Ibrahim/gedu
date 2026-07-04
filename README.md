# GEDU RAG Backend – Laravel API

A fully structured RAG (Retrieval-Augmented Generation) backend for the GEDULink chatbot.
Replaces Rasa with a smarter, data-driven pipeline.

## Architecture

```
React (Frontend)
     ↓
Laravel API (this backend)
     ├── /api/chat          ← chatbot endpoint
     ├── /api/health        ← health check (replaces Rasa /healthz)
     ├── /api/admin/*       ← knowledge base management
     └── /api/knowledge/*   ← rebuild embeddings
          ↓
    EmbeddingService  →  Qdrant (vector search)
          ↓
    GeminiService     →  Final Arabic/English answer
```

## Stack

- **Laravel 11** – API layer
- **MySQL** – structured knowledge source of truth
- **Qdrant** – vector similarity search
- **Gemini API** – answer generation
- **PHP `ext-http` / Guzzle** – HTTP calls to Qdrant & Gemini

---

## Quick Start

### 1. Requirements

- PHP 8.2+
- Composer
- MySQL
- Qdrant (Docker recommended)
- Gemini API key

### 2. Install

```bash
composer install
cp .env.example .env
php artisan key:generate
```

### 3. Configure `.env`

```
DB_DATABASE=gedu_rag
DB_USERNAME=root
DB_PASSWORD=secret

GEMINI_API_KEY=***************
GEMINI_MODEL=gemini-2.5-flash-lite

QDRANT_HOST=http://localhost
QDRANT_PORT=6333
QDRANT_COLLECTION=gedu_knowledge

EMBEDDING_PROVIDER=gemini   # or openai
OPENAI_API_KEY=              # only if using openai embeddings
```

### 4. Run Qdrant via Docker

```bash
docker run -p 6333:6333 -p 6334:6334 \
  -v $(pwd)/qdrant_storage:/qdrant/storage \
  qdrant/qdrant
```

### 5. Migrate & Seed

```bash
php artisan migrate
php artisan db:seed
```

### 6. Build Knowledge Base (embed everything into Qdrant)

```bash
php artisan knowledge:build
```

### 7. Serve

```bash
php artisan serve
```

---

## Frontend Integration

Replace in your React chatbot:

```
VITE_RASA_WEBHOOK_URL  →  VITE_CHATBOT_URL=http://localhost:8000
```

The chatbot component should call:

- `POST /api/chat` with `{ sender, message, locale }` (replaces Rasa webhook)
- `GET  /api/health` (replaces Rasa /healthz)

---

## API Reference

### POST /api/chat

```json
// Request
{ "sender": "user_abc123", "message": "ما هي الجامعات التي تقدم MBA؟", "locale": "ar" }

// Response
{ "replies": [{ "text": "يسعدني مساعدتك! توفر الجامعات التالية برنامج MBA..." }] }
```

### GET /api/health

```json
{ "status": "ok", "qdrant": "connected", "gemini": "ok" }
```

### POST /api/admin/knowledge/rebuild _(requires auth token)_

Rebuilds all embeddings from database. Call after bulk data changes.

---

## Knowledge Auto-Sync

When you update data via Admin Panel (universities, programs, courses, support team),
Laravel automatically re-embeds the changed record in Qdrant — no manual PDF uploads needed.

This is wired in each Model using the `KnowledgeSyncable` trait.
