<?php

namespace GregPriday\WorkManager\Console\Make;

use Illuminate\Console\Command;

/**
 * One-shot scaffolder that creates workspace structure.
 *
 * @internal Dev command for initial setup
 *
 * @see docs/reference/commands-reference.md
 */
class WorkspaceCommand extends Command
{
    protected $signature = 'work-manager:make:workspace
        {--with-examples : Generate example order type and allocator}
        {--with-tests : Generate test structure}';

    protected $description = 'Create WorkManager workspace structure';

    public function handle(): int
    {
        $this->info('Creating WorkManager workspace...');

        // Create directories
        $directories = [
            app_path('WorkTypes'),
            app_path('Strategies'),
        ];

        if ($this->option('with-tests')) {
            $directories[] = base_path('tests/Feature/WorkManager');
        }

        foreach ($directories as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
                $this->line("Created directory: {$dir}");
            } else {
                $this->line("Directory exists: {$dir}");
            }
        }

        // Create .gitkeep files
        foreach ($directories as $dir) {
            $gitkeep = $dir.'/.gitkeep';
            if (! file_exists($gitkeep)) {
                touch($gitkeep);
            }
        }

        // Generate examples if requested
        if ($this->option('with-examples')) {
            $this->generateExamples();
        }

        // Instructions
        $this->newLine();
        $this->info('Workspace created successfully!');
        $this->newLine();
        $this->line('<fg=yellow>Next steps:</>');
        $this->line('1. Generate order types: php artisan work-manager:make:order-type YourType');
        $this->line('2. Generate allocators: php artisan work-manager:make:allocator YourAllocator');
        $this->line('3. Register routes in a service provider:');
        $this->line('   WorkManager::routes();');
        $this->line('4. Run migrations: php artisan migrate');
        $this->line('5. Schedule commands in app/Console/Kernel.php:');
        $this->line("   \$schedule->command('work-manager:generate')->everyFifteenMinutes();");
        $this->line("   \$schedule->command('work-manager:maintain')->everyMinute();");

        return self::SUCCESS;
    }

    /**
     * Generate example order type and allocator.
     */
    protected function generateExamples(): void
    {
        $this->newLine();
        $this->info('Generating examples...');

        // Generate example order type
        $this->call('work-manager:make:order-type', [
            'name' => 'ExampleType',
            '--type' => 'example.task',
            '--with-tests' => $this->option('with-tests'),
        ]);

        // Generate example allocator
        $this->call('work-manager:make:allocator', [
            'name' => 'ExampleAllocator',
            '--type' => 'example.task',
        ]);

        $this->newLine();
        $this->info('Examples generated!');
    }
}
