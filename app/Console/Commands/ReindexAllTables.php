<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReindexAllTables extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reindex-all-tables';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reindex or optimize all database tables depending on DB driver';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $db = config('database.default');

        $this->info("Starting reindexing for database driver: {$db}\n");

        // --- MySQL / MariaDB ---
        if ($db === 'mysql') {
            $tables = DB::select('SHOW TABLES');
            foreach ($tables as $table) {
                $tableName = reset($table);
                DB::statement("OPTIMIZE TABLE $tableName");
                $this->info("Optimized: $tableName");
            }
            $this->info("\n✔ MySQL optimization completed.");
        }

        // --- PostgreSQL ---
        elseif ($db === 'pgsql') {
            DB::statement("REINDEX DATABASE " . config('database.connections.pgsql.database'));
            $this->info("✔ PostgreSQL reindex completed.");
        }

        // --- SQLite ---
        elseif ($db === 'sqlite') {
            DB::statement("REINDEX;");
            $this->info("✔ SQLite reindex completed.");
        }

        else {
            $this->error("❌ Reindex not supported for this DB driver.");
        }
    }
}
