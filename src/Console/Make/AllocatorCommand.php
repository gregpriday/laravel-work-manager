<?php

namespace GregPriday\WorkManager\Console\Make;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Generate an AllocatorStrategy stub.
 *
 * @internal Dev command for scaffolding
 *
 * @see docs/reference/commands-reference.md
 */
class AllocatorCommand extends Command
{
    protected $signature = 'work-manager:make:allocator
        {name : The name of the allocator class (e.g., UserDataSyncAllocator)}
        {--type= : The order type this allocator creates}';

    protected $description = 'Generate a new allocator strategy class';

    public function handle(): int
    {
        $name = $this->argument('name');
        $className = Str::studly($name);

        // Ensure it ends with "Allocator" suffix
        if (! str_ends_with($className, 'Allocator')) {
            $className .= 'Allocator';
        }

        $type = $this->option('type') ?: 'example.type';

        // Determine paths
        $namespace = 'App\\Strategies';
        $directory = app_path('Strategies');
        $filePath = $directory.'/'.$className.'.php';

        // Check if file exists
        if (file_exists($filePath)) {
            $this->error("Allocator already exists: {$filePath}");

            return self::FAILURE;
        }

        // Create directory if it doesn't exist
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
            $this->line("Created directory: {$directory}");
        }

        // Get stub
        $stub = $this->getStub('allocator-strategy.stub');

        // Replace placeholders
        $content = $this->replacePlaceholders($stub, [
            'namespace' => $namespace,
            'class' => $className,
            'name' => Str::title(str_replace('Allocator', '', $className)),
            'description' => "Discovers work for {$type}",
            'type' => $type,
        ]);

        // Write file
        file_put_contents($filePath, $content);
        $this->info("Created allocator: {$filePath}");

        // Instructions
        $this->newLine();
        $this->line('<fg=yellow>Next steps:</>');
        $this->line('1. Implement the discoverWork() method');
        $this->line('2. Register in AppServiceProvider:');
        $this->line("   \$this->app->tag([\\{$namespace}\\{$className}::class], 'work-manager.strategies');");
        $this->line('3. Run: php artisan work-manager:generate');

        return self::SUCCESS;
    }

    /**
     * Get stub content.
     */
    protected function getStub(string $name): string
    {
        $path = __DIR__.'/../../../stubs/'.$name;

        if (! file_exists($path)) {
            throw new \RuntimeException("Stub not found: {$path}");
        }

        return file_get_contents($path);
    }

    /**
     * Replace placeholders in stub.
     */
    protected function replacePlaceholders(string $stub, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $stub = str_replace('{{ '.$key.' }}', $value, $stub);
        }

        return $stub;
    }
}
