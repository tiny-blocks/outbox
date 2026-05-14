<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Serialization;

use TinyBlocks\Outbox\Exceptions\InvalidSnapshotJson;

final readonly class SerializedSnapshot
{
    private function __construct(private string $snapshot)
    {
    }

    public static function from(string $snapshot): SerializedSnapshot
    {
        if (!json_validate($snapshot)) {
            throw InvalidSnapshotJson::for(snapshot: $snapshot);
        }

        return new SerializedSnapshot(snapshot: $snapshot);
    }

    public static function fromArray(array $snapshot): SerializedSnapshot
    {
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);

        return new SerializedSnapshot(snapshot: $json);
    }

    public function toJson(): string
    {
        return $this->snapshot;
    }
}
