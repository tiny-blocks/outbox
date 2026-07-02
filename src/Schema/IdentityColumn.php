<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Schema;

/**
 * Identity column that maps an aggregate or event identifier to its stored representation.
 */
interface IdentityColumn
{
    /**
     * Returns the physical column name.
     *
     * @return string The column name in the outbox table.
     */
    public function name(): string;

    /**
     * Converts an identity value to the representation persisted in the column.
     *
     * @param mixed $identityValue The identity value to convert.
     * @return string The value as stored in the column.
     */
    public function convert(mixed $identityValue): string;
}
