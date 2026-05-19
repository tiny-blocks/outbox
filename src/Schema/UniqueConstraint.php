<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Schema;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class UniqueConstraint
{
    private function __construct(public string $name)
    {
    }

    /**
     * Creates a UniqueConstraint using the default constraint name.
     *
     * @return UniqueConstraint The default unique constraint.
     */
    public static function default(): UniqueConstraint
    {
        return UniqueConstraint::named(name: 'unq_outbox_events_aggregate_type_aggregate_id_aggregate_version');
    }

    /**
     * Creates a UniqueConstraint with the explicit constraint name.
     *
     * @param string $name The physical name of the unique constraint in the outbox table.
     * @return UniqueConstraint The created unique constraint.
     */
    public static function named(string $name): UniqueConstraint
    {
        return new UniqueConstraint(name: $name);
    }

    /**
     * Tells whether the given driver exception was raised by this unique constraint.
     *
     * @param UniqueConstraintViolationException $exception The driver exception to inspect.
     * @return bool True when the exception message mentions this constraint name, false otherwise.
     */
    public function isViolatedBy(UniqueConstraintViolationException $exception): bool
    {
        return str_contains($exception->getMessage(), $this->name);
    }
}
