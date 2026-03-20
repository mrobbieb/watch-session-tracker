<?php

namespace App\Redis;

use Predis\Client;

class RedisClient
{
    private Client $client;

    public function __construct(string $host, int $port)
    {
        $this->client = new Client([
            'scheme' => 'tcp',
            'host'   => $host,
            'port'   => $port,
        ]);
    }

    /**
     * Store session data as a hash.
     */
    public function setSession(string $sessionId, array $data): void
    {
        $this->client->hmset("session:{$sessionId}", $data);
    }

    /**
     * Retrieve all fields for a session.
     */
    public function getSession(string $sessionId): array
    {
        return $this->client->hgetall("session:{$sessionId}");
    }

    /**
     * Append a raw event to the session's event log.
     */
    public function appendEvent(string $sessionId, array $event): void
    {
        $this->client->rpush(
            "session:{$sessionId}:events",
            [json_encode($event)]
        );
    }

    /**
     * Retrieve all events for a session.
     */
    public function getSessionEvents(string $sessionId): array
    {
        $events = $this->client->lrange("session:{$sessionId}:events", 0, -1);

        return array_map(fn($e) => json_decode($e, true), $events);
    }

    /**
     * Add or update a session in the active viewers sorted set.
     * Score is current Unix timestamp — used to detect stale sessions.
     */
    public function trackActiveSession(string $eventId, string $sessionId): void
    {
        $this->client->zadd(
            "active_sessions:{$eventId}",
            [($sessionId) => time()]
        );
    }

    /**
     * Remove a session from the active viewers sorted set.
     */
    public function removeActiveSession(string $eventId, string $sessionId): void
    {
        $this->client->zrem("active_sessions:{$eventId}", $sessionId);
    }

    /**
     * Count active sessions for an event.
     * A session is considered active if its last heartbeat was within 60 seconds.
     */
    public function countActiveSessions(string $eventId): int
    {
        return $this->client->zcount(
            "active_sessions:{$eventId}",
            time() - 60,
            '+inf'
        );
    }
}