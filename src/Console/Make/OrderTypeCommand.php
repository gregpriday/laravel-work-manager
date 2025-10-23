<?php

namespace GregPriday\WorkManager\Console\Make;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Generate a stub AbstractOrderType with schema, plan(), and validation hooks.
 *
 * @internal Dev command for scaffolding
 *
 * @see docs/reference/commands-reference.md
 */
class OrderTypeCommand extends Command
{
    protected $signature = 'work-manager:make:order-type
        {name : The name of the order type class (e.g., UserDataSync)}
        {--type= : The type identifier (e.g., user.data.sync)}
        {--parts : Include partial submission hooks}
        {--with-tests : Generate a Pest test file}';

    protected $description = 'Generate a new order type class';

    public function handle(): int
    {
        $name = $this->argument('name');
        $className = Str::studly($name);

        // Ensure it ends with "Type" suffix
        if (! str_ends_with($className, 'Type')) {
            $className .= 'Type';
        }

        $type = $this->option('type') ?: Str::snake(str_replace('Type', '', $className), '.');
        $withParts = $this->option('parts');
        $withTests = $this->option('with-tests');

        // Determine paths
        $namespace = 'App\\WorkTypes';
        $directory = app_path('WorkTypes');
        $filePath = $directory.'/'.$className.'.php';

        // Check if file exists
        if (file_exists($filePath)) {
            $this->error("Order type already exists: {$filePath}");

            return self::FAILURE;
        }

        // Create directory if it doesn't exist
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
            $this->line("Created directory: {$directory}");
        }

        // Get stub
        $stubName = $withParts ? 'order-type.partials.stub' : 'order-type.stub';
        $stub = $this->getStub($stubName);

        // Replace placeholders
        $content = $this->replacePlaceholders($stub, [
            'namespace' => $namespace,
            'class' => $className,
            'type' => $type,
            'description' => "Order type for {$type}",
        ]);

        // Write file
        file_put_contents($filePath, $content);
        $this->info("Created order type: {$filePath}");

        // Generate test if requested
        if ($withTests) {
            $this->generateTest($className, $namespace, $type);
        }

        // Instructions
        $this->newLine();
        $this->line('<fg=yellow>Next steps:</>');
        $this->line('1. Customize the schema() method with your JSON schema');
        $this->line('2. Implement the plan() method to break orders into items');
        $this->line('3. Implement the apply() method with your idempotent changes');
        $this->line('4. Register in AppServiceProvider:');
        $this->line("   WorkManager::registry()->register(new \\{$namespace}\\{$className}());");

        return self::SUCCESS;
    }

    /**
     * Generate a test file for the order type.
     */
    protected function generateTest(string $className, string $namespace, string $type): void
    {
        $testDirectory = base_path('tests/Feature/WorkManager');
        $testPath = $testDirectory.'/'.$className.'Test.php';

        if (file_exists($testPath)) {
            $this->warn("Test already exists: {$testPath}");

            return;
        }

        if (! is_dir($testDirectory)) {
            mkdir($testDirectory, 0755, true);
        }

        $stub = $this->getStub('order-type.test.stub');
        $content = $this->replacePlaceholders($stub, [
            'orderTypeNamespace' => $namespace,
            'orderTypeClass' => $className,
            'type' => $type,
        ]);

        file_put_contents($testPath, $content);
        $this->info("Created test: {$testPath}");
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
