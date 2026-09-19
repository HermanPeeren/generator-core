<?php

/**
 * Rewrites the approved output for a golden fixture.
 *
 * Run it deliberately, and read the diff: the golden files are the review
 * surface for every change to a template or a generator, and accepting one
 * without looking is the one way this practice fails.
 *
 *   php tools/generate-fixture.php            # every fixture
 *   php tools/generate-fixture.php flight     # one of them
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yepr\Gen\Core\Pipeline;
use Yepr\Gen\Core\Testing\GoldenFiles;
use Yepr\Gen\Core\Tests\Support\DemoModel;
use Yepr\Gen\Core\Tests\Support\DemoTarget;

$fixtures = new GoldenFiles(__DIR__ . '/../tests/Fixtures/golden');
$names    = $argv[1] ?? null ? [$argv[1]] : $fixtures->names();

foreach ($names as $name) {
    $model   = DemoModel::fromJson($fixtures->model($name));
    $files   = (new Pipeline())->run($model, new DemoTarget());
    $written = $fixtures->write($name, $files);

    printf("%s: %d file(s)\n", $name, \count($written));

    foreach ($written as $path) {
        printf("    %s\n", $path);
    }
}

echo "\nRead the diff before committing.\n";
