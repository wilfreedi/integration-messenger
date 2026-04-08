<?php

declare(strict_types=1);

namespace ChatSync\Shared\Domain;

use InvalidArgumentException;

abstract readonly class AbstractId
{
    final public function __construct(private string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('Identifier value must not be empty.');
        }
    }

    public static function fromString(string $value): static
    {
        return new static($value);
    }

    public static function generate(IdGenerator $generator): static
    {
        return new static($generator->next());
    }

    final public function toString(): string
    {
        return $this->value;
    }

    final public function __toString(): string
    {
        return $this->value;
    }
}

