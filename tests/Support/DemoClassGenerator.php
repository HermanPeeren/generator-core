<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Support;

use Yepr\Gen\Core\Emitter\PhpEmitter;
use Yepr\Gen\Core\GeneratorInterface;
use Yepr\Gen\Core\Model\ModelInterface;
use Yepr\Gen\Core\Output\FileCollection;
use Yepr\Gen\Core\Template\TwigRenderer;

/** Writes the entity class, through a template. */
final class DemoClassGenerator implements GeneratorInterface
{
    private const TEMPLATE = <<<'TWIG'
        <?php

        declare(strict_types=1);

        namespace Demo\{{ class }};

        /**
         * {{ label }}
         */
        final class {{ class }}
        {
        {% for name, type in fields %}
            public {{ type }} ${{ name }};
        {% endfor %}
        }
        TWIG;

    public function supports(ModelInterface $model): bool
    {
        return $model instanceof DemoModel;
    }

    public function generate(ModelInterface $model, FileCollection $files): void
    {
        \assert($model instanceof DemoModel);

        $renderer = TwigRenderer::forTemplates(['entity' => self::TEMPLATE]);

        $files->add('src/' . $model->className() . '.php', $renderer->render('entity', [
            // The class name is syntax, so it is checked rather than escaped.
            'class'  => PhpEmitter::identifier($model->className()),
            'label'  => $model->name,
            'fields' => $model->fields,
        ]));
    }
}
