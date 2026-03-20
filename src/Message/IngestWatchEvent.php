<?php

namespace App\Message;

/**
 * Message dispatched when a player SDK event is received.
 * The raw payload is queued here and validated in the handler,
 * ensuring we never drop an event due to a validation error
 * before it's been persisted to the queue.
 */
class IngestWatchEvent
{
    public function __construct(
        public readonly array $payload
    ) {}
}