<?php

namespace App\Console\Commands;

use App\Services\WebsiteKnowledgeImporter;
use Illuminate\Console\Command;

/**
 * KnowledgeImportCommand
 *
 * php artisan knowledge:import [file] [--no-build]
 *
 * Loads the website's content export (frontend: `npm run export:knowledge`)
 * into MySQL, removes anything no longer on the website, then rebuilds Qdrant.
 */
class KnowledgeImportCommand extends Command
{
    public const DEFAULT_FILE = 'database/data/gedu-knowledge.json';

    protected $signature = 'knowledge:import
        {file? : Path to gedu-knowledge.json (default: database/data/gedu-knowledge.json)}
        {--no-build : Only update MySQL, skip re-embedding into Qdrant}';

    protected $description = 'Import the website content export and rebuild the chatbot knowledge base';

    public function handle(WebsiteKnowledgeImporter $importer): int
    {
        $file = $this->argument('file') ?? base_path(self::DEFAULT_FILE);

        if (! is_file($file)) {
            $this->error("❌ File not found: {$file}");
            $this->line('Create it in the frontend project with: npm run export:knowledge');
            return Command::FAILURE;
        }

        $data = json_decode(file_get_contents($file), true);
        if (! is_array($data)) {
            $this->error('❌ Not valid JSON: ' . json_last_error_msg());
            return Command::FAILURE;
        }

        $this->info("📥 Importing website content exported at " . ($data['generated_at'] ?? 'unknown time'));

        try {
            $stats = $importer->import($data);
        } catch (\Throwable $e) {
            $this->error('❌ Import failed (database unchanged): ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->table(['Imported', 'Count'], [
            ['Universities', $stats['universities']],
            ['Programs', $stats['programs']],
            ['Advisors', $stats['advisors']],
            ['Website pages', $stats['pages']],
            ['Removed (no longer on website)', $stats['removed']],
        ]);

        if ($this->option('no-build')) {
            $this->warn('Skipped Qdrant rebuild. Run: php artisan knowledge:build');
            return Command::SUCCESS;
        }

        return $this->call('knowledge:build');
    }
}
