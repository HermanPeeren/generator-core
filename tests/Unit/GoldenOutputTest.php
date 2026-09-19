<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Unit;

use Yepr\Gen\Core\Output\FileCollection;
use Yepr\Gen\Core\Pipeline;
use Yepr\Gen\Core\Testing\GoldenFiles;
use Yepr\Gen\Core\Testing\GoldenTestCase;
use Yepr\Gen\Core\Tests\Support\DemoModel;
use Yepr\Gen\Core\Tests\Support\DemoTarget;

/**
 * The whole golden suite for this repository, and the worked example a consuming
 * project copies: two methods, and the comparison comes from the base class.
 *
 * It doubles as the core's only end-to-end test - a model goes in and PHP, XML
 * and ini come out, through the renderer and all three emitters.
 */
final class GoldenOutputTest extends GoldenTestCase
{
    protected function fixtures(): GoldenFiles
    {
        return new GoldenFiles(\dirname(__DIR__) . '/Fixtures/golden');
    }

    protected function generate(string $name): FileCollection
    {
        $model = DemoModel::fromJson($this->fixtures()->model($name));

        return (new Pipeline())->run($model, new DemoTarget());
    }
}
