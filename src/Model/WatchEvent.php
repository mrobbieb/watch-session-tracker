<?php

namespace App\Model;

use App\Enum\EventType;

class WatchEvent
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $userId,
        public readonly EventType $eventType,
        public readonly string $eventId,
        public readonly \DateTimeImmutable $eventTimestamp,
        public readonly \DateTimeImmutable $receivedAt,
        public readonly string $sportEventId,
        public readonly float $position,
        public readonly string $quality,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            sessionId: $data['sessionId'],
            userId: $data['userId'],
            eventType: EventType::from($data['eventType']),
            eventId: $data['eventId'],
            eventTimestamp: new \DateTimeImmutable($data['eventTimestamp']),
            receivedAt: new \DateTimeImmutable($data['receivedAt']),
            sportEventId: $data['payload']['eventId'],
            position: (float) $data['payload']['position'],
            quality: $data['payload']['quality'],
        );
    }

    public function toArray(): array
    {
        return [
            'sessionId' => $this->sessionId,
            'userId' => $this->userId,
            'eventType' => $this->eventType->value,
            'eventId' => $this->eventId,
            'eventTimestamp' => $this->eventTimestamp->format(\DateTimeInterface::ATOM),
            'receivedAt' => $this->receivedAt->format(\DateTimeInterface::ATOM),
            'sportEventId' => $this->sportEventId,
            'position' => $this->position,
            'quality' => $this->quality,
        ];
    }
}