<?php

namespace Database\Seeders;

use App\Console\Commands\KnowledgeImportCommand;
use App\Services\WebsiteKnowledgeImporter;
use Illuminate\Database\Seeder;

/**
 * DatabaseSeeder
 *
 * Seeds the knowledge tables from the website's own content export
 * (database/data/gedu-knowledge.json), produced in the frontend project by:
 *
 *   npm run export:knowledge -- --out=<backend>/database/data/gedu-knowledge.json
 *
 * The website is the single source of truth — don't hand-edit data here.
 * After seeding, run: php artisan knowledge:build
 */
class DatabaseSeeder extends Seeder
{
    public function run(WebsiteKnowledgeImporter $importer): void
    {
        $file = base_path(KnowledgeImportCommand::DEFAULT_FILE);
        $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        $stats = $importer->import($data);

        $this->command->info(sprintf(
            '✅ Seeded %d universities, %d programs, %d advisors, %d website pages. Now run: php artisan knowledge:build',
            $stats['universities'], $stats['programs'], $stats['advisors'], $stats['pages']
        ));
    }
}
