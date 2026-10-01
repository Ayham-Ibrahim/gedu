<?php

namespace App\Console\Commands;

use App\Services\KnowledgeBuilderService;
use Illuminate\Console\Command;

/**
 * KnowledgeBuildCommand
 *
 * php artisan knowledge:build
 *
 * Reads all records from MySQL and re-embeds them into Qdrant.
 * Run this:
 *   - After first setup (after seeding)
 *   - After bulk-importing data
 *   - If Qdrant data gets corrupted
 *
 * For single-record updates, the model observers handle it automatically.
 */
class KnowledgeBuildCommand extends Command
{
    protected $signature   = 'knowledge:build {--fresh : Delete and recreate the Qdrant collection first}';
    protected $description = 'Build/rebuild the RAG knowledge base by embedding all DB records into Qdrant';

    public function __construct(private readonly KnowledgeBuilderService $builder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🚀 Starting knowledge base build...');
        $this->newLine();

        $start = microtime(true);

        try {
            $stats = $this->builder->rebuildAll();

            $elapsed = round(microtime(true) - $start, 2);

            $this->table(
                ['Source', 'Records Indexed'],
                [
                    ['Universities',   $stats['universities']],
                    ['Programs',       $stats['programs']],
                    ['Courses',        $stats['courses']],
                    ['Support Team',   $stats['support_members']],
                    ['Website Pages',  $stats['site_pages']],
                ]
            );

            $total = array_sum($stats);
            $this->newLine();
            $this->info("✅ Done! {$total} chunks indexed into Qdrant in {$elapsed}s");
            $this->info('Your chatbot is ready to answer questions.');

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('❌ Build failed: ' . $e->getMessage());

            if ($this->output->isVerbose()) {
                $this->error($e->getTraceAsString());
            } else {
                $this->line('Run with -v for the full stack trace.');
            }
            return Command::FAILURE;
        }
    }
}
