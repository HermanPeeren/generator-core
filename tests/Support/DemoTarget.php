<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Support;

use Yepr\Gen\Core\GeneratorInterface;
use Yepr\Gen\Core\Model\ValidatorInterface;
use Yepr\Gen\Core\Target\TargetInterface;

/**
 * A target small enough to read, exercising the whole core.
 *
 * Three generators writing three languages, because that is the case a single
 * escaper cannot serve: a class through Twig and the PHP emitter, a manifest
 * through the XML emitter, a language file through the ini emitter.
 *
 * It also shows the ordering rule in practice. The manifest inventories what the
 * others produced, so it runs last - which is the reason a target states the
 * order rather than leaving it to a registry.
 */
final class DemoTarget implements TargetInterface
{
    public function id(): string
    {
        return 'demo';
    }

    public function label(): string
    {
        return 'Demo package';
    }

    public function validator(): ?ValidatorInterface
    {
        return null;
    }

    /** @return GeneratorInterface[] */
    public function generators(): array
    {
        return [
            new DemoClassGenerator(),
            new DemoLanguageGenerator(),
            new DemoManifestGenerator(),
        ];
    }
}
