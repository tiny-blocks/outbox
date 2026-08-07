<?php

declare(strict_types=1);

namespace Test\TinyBlocks\Outbox\Unit;

use PHPUnit\Framework\TestCase;
use TinyBlocks\Outbox\Exceptions\InvalidPayloadJson;
use TinyBlocks\Outbox\Serialization\SerializedPayload;

final class SerializedPayloadTest extends TestCase
{
    public function testFromArrayWhenPayloadIsEncodableThenJsonIsProduced(): void
    {
        /** @Given an associative array describing an event payload */
        $payload = ['orderId' => 'ORDER-1', 'total' => 100];

        /** @When the payload is serialized from the array */
        $actual = SerializedPayload::fromArray(payload: $payload);

        /** @Then the JSON representation carries the array contents */
        self::assertSame('{"orderId":"ORDER-1","total":100}', $actual->toJson());
    }

    public function testFromArrayWhenPayloadIsNotEncodableThenInvalidPayloadJson(): void
    {
        /** @Given an array carrying a malformed UTF-8 string */
        $payload = ['reason' => "\xB1\x31"];

        /** @Then an InvalidPayloadJson is expected */
        $this->expectException(InvalidPayloadJson::class);

        /** @When the payload is serialized from the array */
        SerializedPayload::fromArray(payload: $payload);
    }
}
