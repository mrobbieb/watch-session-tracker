<?php

namespace App\Service;

use App\Enum\EventType;
use App\Enum\SessionState;
use App\Model\WatchEvent;
use App\Redis\RedisClient;

class SessionTracker
{
    public function __construct(private RedisClient $redis) {}

    /**
     * Process an incoming watch event and update session state accordingly.
     */
    public function handle(WatchEvent $event): void
    {
        $existing = $this->redis->getSession($event->sessionId);

        if (empty($existing)) {
            $this->createSession($event);
        } else {
            $this->updateSession($event, $existing);
        }

        // Always append the raw event to the session log
        $this->redis->appendEvent($event->sessionId, $event->toArray());

        // Update active viewers unless session has ended
        if ($event->eventType !== EventType::End) {
            $this->redis->trackActiveSession($event->sportEventId, $event->sessionId);
        } else {
            $this->redis->removeActiveSession($event->sportEventId, $event->sessionId);
        }
    }

    /**
     * Create a new session from a start event.
     */
    private function createSession(WatchEvent $event): void
    {
        $this->redis->setSession($event->sessionId, [
            'sessionId'   => $event->sessionId,
            'userId'      => $event->userId,
            'sportEventId' => $event->sportEventId,
            'state'       => SessionState::Active->value,
            'startedAt'   => $event->eventTimestamp->format(\DateTimeInterface::ATOM),
            'lastEventAt' => $event->eventTimestamp->format(\DateTimeInterface::ATOM),
            'duration'    => 0,
            'eventCount'  => 1,
        ]);
    }

    /**
     * Update an existing session based on the incoming event type.
     */
    private function updateSession(WatchEvent $event, array $existing): void
    {
        $state = $this->resolveState($event->eventType);
        $duration = $this->calculateDuration(
            $existing['startedAt'],
            $event->eventTimestamp
        );

        $this->redis->setSession($event->sessionId, [
            ...$existing,
            'state'       => $state->value,
            'lastEventAt' => $event->eventTimestamp->format(\DateTimeInterface::ATOM),
            'duration'    => $duration,
            'eventCount'  => (int) $existing['eventCount'] + 1,
        ]);
    }

    /**
     * Resolve the session state from the incoming event type.
     */
    private function resolveState(EventType $eventType): SessionState
    {
        return match($eventType) {
            EventType::Pause                => SessionState::Paused,
            EventType::BufferStart          => SessionState::Buffering,
            EventType::End                  => SessionState::Ended,
            default                         => SessionState::Active,
        };
    }

    /**
     * Calculate total session duration in seconds from start to current event.
     */
    private function calculateDuration(string $startedAt, \DateTimeImmutable $currentTime): int
    {
        $start = new \DateTimeImmutable($startedAt);
        return $currentTime->getTimestamp() - $start->getTimestamp();
    }

    /**
     * Get the current active viewer count for a sport event.
     */
    public function getActiveViewerCount(string $eventId): int
    {
        return $this->redis->countActiveSessions($eventId);
    }

    /**
     * Get full session details including event history.
     */
    public function getSessionDetails(string $sessionId): ?array
    {
        $session = $this->redis->getSession($sessionId);

        if (empty($session)) {
            return null;
        }

        return [
            ...$session,
            'events' => $this->redis->getSessionEvents($sessionId),
        ];
    }
}