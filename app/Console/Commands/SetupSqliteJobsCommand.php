<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Exception;

class SetupSqliteJobsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'setup:sqlite-jobs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sets up the jobs table with the correct schema for the SQLite (jobs) connection.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $connectionName = 'jobs'; // As defined in config/database.php

        $this->info("Attempting to set up '{$connectionName}' table on the '{$connectionName}' (SQLite) connection...");

        try {
            // Ensure the connection exists
            config(["database.connections.{$connectionName}" => config("database.connections.{$connectionName}")]);
            DB::connection($connectionName)->getPdo();
        } catch (Exception $e) {
            $this->error("Database connection '{$connectionName}' not found or not configured correctly.");
            $this->error("Ensure it's defined in config/database.php and points to your SQLite database.");
            $this->error("Error: " . $e->getMessage());
            return Command::FAILURE;
        }
        
        if (config("database.connections.{$connectionName}.driver") !== 'sqlite') {
            $this->error("This command is intended for SQLite connections only. The '{$connectionName}' connection is not using the SQLite driver.");
            return Command::FAILURE;
        }

        try {
            DB::connection($connectionName)->transaction(function () use ($connectionName) {
                Schema::connection($connectionName)->dropIfExists('jobs');
                Schema::connection($connectionName)->create('jobs', function (Blueprint $table) {
                    $table->id();
                    $table->string('title');
                    $table->string('batch_no');
                    $table->longText('payload');
                    $table->json('variables')->nullable();
                    $table->timestamps();
                    $this->comment("Created 'jobs' table with custom schema on '{$connectionName}' connection.");
                });

                // You could add other tables or schema adjustments here if needed for this connection
            });

            $this->info("Successfully set up 'jobs' table on the '{$connectionName}' (SQLite) connection.");
            
            // Verify by listing tables (optional, for verbosity)
            // $tables = DB::connection($connectionName)->getDoctrineSchemaManager()->listTableNames();
            // $this->comment("Tables on '{$connectionName}': " . implode(', ', $tables));

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error("An error occurred while setting up the 'jobs' table on the '{$connectionName}' connection:");
            $this->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
