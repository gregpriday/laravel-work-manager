<?php

test('make:order-type generates order type class', function () {
    $path = app_path('WorkTypes/TestOrderType.php');

    // Clean up if exists
    if (file_exists($path)) {
        unlink($path);
    }

    $this->artisan('work-manager:make:order-type', [
        'name' => 'TestOrder',
        '--type' => 'test.order',
    ])->assertExitCode(0);

    expect($path)->toBeFile();

    $content = file_get_contents($path);
    expect($content)->toContain('class TestOrderType');
    expect($content)->toContain("return 'test.order'");

    // Clean up
    unlink($path);
});

test('make:order-type with partials flag generates partial support', function () {
    $path = app_path('WorkTypes/PartialTestType.php');

    if (file_exists($path)) {
        unlink($path);
    }

    $this->artisan('work-manager:make:order-type', [
        'name' => 'PartialTest',
        '--parts' => true,
    ])->assertExitCode(0);

    $content = file_get_contents($path);
    expect($content)->toContain('partialRules');
    expect($content)->toContain('afterValidatePart');
    expect($content)->toContain('requiredParts');
    expect($content)->toContain('assemble');
    expect($content)->toContain('validateAssembled');

    unlink($path);
});

test('make:order-type with tests flag generates test file', function () {
    $classPath = app_path('WorkTypes/TestWithTestsType.php');
    $testPath = base_path('tests/Feature/WorkManager/TestWithTestsTypeTest.php');

    if (file_exists($classPath)) {
        unlink($classPath);
    }
    if (file_exists($testPath)) {
        unlink($testPath);
    }

    $this->artisan('work-manager:make:order-type', [
        'name' => 'TestWithTests',
        '--with-tests' => true,
    ])->assertExitCode(0);

    expect($classPath)->toBeFile();
    expect($testPath)->toBeFile();

    unlink($classPath);
    unlink($testPath);
});

test('make:allocator generates allocator class', function () {
    $path = app_path('Strategies/TestAllocator.php');

    if (file_exists($path)) {
        unlink($path);
    }

    $this->artisan('work-manager:make:allocator', [
        'name' => 'Test',
        '--type' => 'test.type',
    ])->assertExitCode(0);

    expect($path)->toBeFile();

    $content = file_get_contents($path);
    expect($content)->toContain('class TestAllocator');
    expect($content)->toContain('implements AllocatorStrategy');

    unlink($path);
});

test('make:workspace creates directory structure', function () {
    $this->artisan('work-manager:make:workspace')
        ->assertExitCode(0);

    expect(app_path('WorkTypes'))->toBeDirectory();
    expect(app_path('Strategies'))->toBeDirectory();
});

test('make:workspace with examples generates example classes', function () {
    $orderTypePath = app_path('WorkTypes/ExampleType.php');
    $allocatorPath = app_path('Strategies/ExampleAllocator.php');

    // Clean up if exists
    if (file_exists($orderTypePath)) {
        unlink($orderTypePath);
    }
    if (file_exists($allocatorPath)) {
        unlink($allocatorPath);
    }

    $this->artisan('work-manager:make:workspace', ['--with-examples' => true])
        ->assertExitCode(0);

    expect($orderTypePath)->toBeFile();
    expect($allocatorPath)->toBeFile();

    unlink($orderTypePath);
    unlink($allocatorPath);
});
