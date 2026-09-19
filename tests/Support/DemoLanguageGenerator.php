<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Support;

use Yepr\Gen\Core\Emitter\IniEmitter;
use Yepr\Gen\Core\GeneratorInterface;
use Yepr\Gen\Core\Model\ModelInterface;
use Yepr\Gen\Core\Output\FileCollection;

/** Writes the language file. */
final class DemoLanguageGenerator implements GeneratorInterface
{
    public function supports(ModelInterface $model): bool
    {
        return $model instanceof DemoModel;
    }

    public function generate(ModelInterface $model, FileCollection $files): void
    {
        \assert($model instanceof DemoModel);

        $prefix = 'DEMO_' . strtoupper($model->elementName());
        $lines  = [IniEmitter::line($prefix . '_LABEL', $model->name)];

        foreach (array_keys($model->fields) as $field) {
            $lines[] = IniEmitter::line($prefix . '_FIELD_' . strtoupper($field), ucfirst($field));
        }

        $files->add('language/en-GB/demo.ini', implode("\n", $lines) . "\n");
    }
}
