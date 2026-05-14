<?php

declare(strict_types=1);

namespace TinyBlocks\Outbox\Serialization;

use TinyBlocks\Outbox\Exceptions\InvalidPayloadJson;

final readonly class SerializedPayload
{
    private function __construct(private string $payload)
    {
    }

    public static function from(string $payload): SerializedPayload
    {
        if (!json_validate($payload)) {
            throw InvalidPayloadJson::for(payload: $payload);
        }

        return new SerializedPayload(payload: $payload);
    }

    public static function fromArray(array $payload): SerializedPayload
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return new SerializedPayload(payload: $json);
    }

    public function toJson(): string
    {
        return $this->payload;
    }
}
