<?php

declare(strict_types=1);

namespace Yepr\Gen\Core\Tests\Support;

use Yepr\Gen\Core\Model\ModelInterface;
use Yepr\Gen\Core\Model\ValidationException;

/**
 * A minimal model, standing in for a real one.
 *
 * Its only job is to give the golden suite and the documentation something
 * concrete to generate from, while staying small enough to read in one go.
 */
final class DemoModel implements ModelInterface
{
    /**
     * @param string                $name   The entity name, in the form a person writes it.
     * @param array<string, string> $fields Field name => type.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $fields
    ) {
    }

    public static function fromJson(string $json): self
    {
        /** @var array{name?: string, fields?: array<string, string>} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $errors = [];

        if (!isset($data['name']) || trim($data['name']) === '') {
            $errors[] = 'the model has no name';
        }

        if (($data['fields'] ?? []) === []) {
            $errors[] = 'the model has no fields';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($data['name'], $data['fields'] ?? []);
    }

    /** The name as a PHP identifier: "Flight plan" becomes "FlightPlan". */
    public function className(): string
    {
        return str_replace(' ', '', ucwords(preg_replace('/[^A-Za-z0-9 ]/', ' ', $this->name) ?? ''));
    }

    /** The name as a lower case element: "Flight plan" becomes "flightplan". */
    public function elementName(): string
    {
        return strtolower($this->className());
    }
}
