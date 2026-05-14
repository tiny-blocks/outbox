<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Schema;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class UniqueConstraint
{
    private function __construct(public string $name)
    {
    }

    public static function default(): UniqueConstraint
    {
        return UniqueConstraint::named(name: 'uniq_aggregate_sequence');
    }

    public static function named(string $name): UniqueConstraint
    {
        return new UniqueConstraint(name: $name);
    }

    public function isViolatedBy(UniqueConstraintViolationException $exception): bool
    {
        return str_contains($exception->getMessage(), $this->name);
    }
}
