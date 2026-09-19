<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Support;

use Yepr\Gen\Core\Emitter\XmlEmitter;
use Yepr\Gen\Core\GeneratorInterface;
use Yepr\Gen\Core\Model\ModelInterface;
use Yepr\Gen\Core\Output\FileCollection;

/** Writes the manifest, listing what the others generated. */
final class DemoManifestGenerator implements GeneratorInterface
{
    public function supports(ModelInterface $model): bool
    {
        return $model instanceof DemoModel;
    }

    public function generate(ModelInterface $model, FileCollection $files): void
    {
        \assert($model instanceof DemoModel);

        $folders = [];

        foreach ($files->paths() as $path) {
            $folders[strtok($path, '/')] = true;
        }

        $lines   = ['<?xml version="1.0" encoding="utf-8"?>', '<demo>'];
        $lines[] = XmlEmitter::element('name', [], $model->name, 1);

        foreach (array_keys($folders) as $folder) {
            $lines[] = XmlEmitter::element('folder', [], (string) $folder, 1);
        }

        $lines[] = '</demo>';

        $files->add('demo.xml', implode("\n", $lines) . "\n");
    }
}
