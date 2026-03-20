# Watch Session Tracker

A real time watch session tracking service for FS utilizing redis and symfony messaging queue.

I implemented queue based ingestion via Symfony Messenger to address the Operations requirement around event durability. The HTTP endpoint enqueues the raw payload and returns 202 immediately. A worker processes events asynchronously. This adds minimal complexity while solving the spike/loss problem directly.

## Requirements

- [Docker Desktop](https://docs.docker.com/desktop/)


## Start the Service
```bash
docker compose up
```

## Run Tests
```bash
docker compose exec app vendor/bin/phpunit
```

## Stack

- PHP 8.4
- Symfony 6.4 LTS
- Redis 7
- PHPUnit + Mockery

## Tools and Resources Used

- **Claude (Anthropic)** — Used throughout as a pair programmer and architecture sounding board. Helped design the Redis data model, generated boilerplate controller and service code, and discussed tradeoffs. All generated code was reviewed, understood, and modified where needed. The architecture decisions, data modeling choices, and README are my own.

- **Symfony Documentation** — Referenced for Symfony 6.4 service configuration and routing.

- **Predis Documentation** — Referenced for Redis client usage.

## Assumptions

- **Active session window is 60 seconds.** Heartbeats fire every 30 seconds per the PRD. A session is considered active if it has received any event within the last 60 seconds. one full missed heartbeat before being considered stale.

- **sessionId is client-generated and trusted.** The SDK owns session identity. No server-side session ID generation.

- **eventTimestamp is authoritative for duration calculation**, not receivedAt. Network latency means receivedAt may not accurately reflect when the event actually occurred.

- **Event types are limited to the set defined in the PRD.** Unknown event types are accepted by the HTTP layer and queued, but discarded by the worker with a log warning. This prioritizes the Operations requirement of never dropping events over early validation feedback at the API boundary.

- **No authentication on the API.** This is a v1 proof of concept. Auth would be required before production.

- **No event deduplication in v1.** The same eventId could theoretically be processed twice. In production we'd use a Redis SET to track processed eventIds and reject duplicates. https://redis.io/docs/latest/develop/data-types/#sets.

- **Out of order events are handled gracefully.** If a heartbeat or other event arrives before a `start` event, a session is created with the first received event as the 
  starting point. Given the Operations requirement to never drop events, accepting and 
  recording all events is preferable to rejecting them.

## Trade-offs

- **Queue based async ingestion via Symfony Messenger.** Events are enqueued to a Redis 
  transport and processed by a dedicated worker container. The HTTP endpoint returns 202 
  immediately without waiting for processing, decoupling ingestion throughput from 
  processing throughput. Failed messages are retried up to 3 times with exponential 
  backoff before being moved to a dead letter queue — directly addressing the Operations 
  requirement of never dropping events. The tradeoff is that viewer counts are eventually 
  consistent rather than immediately consistent, though in practice the delay is 
  milliseconds and well within the 10-15 second freshness window Product requires.

- **Redis as source of truth.** For v1 Redis is both the cache and the persistent store. In production I'd use MySQL + Doctrine ORM as the source of truth and Redis as a cache layer. See production notes below.

- **No TTL/cleanup on session data.** Session hashes and event logs will accumulate in Redis indefinitely. In production a cleanup job would expire ended sessions after a retention window.

- **PHP 8.4 over PHP 7.2.** FloSports currently runs PHP 7.2. I chose 8.4 because it's where I'm most productive today and aligns with the modernization direction. I'm comfortable working within a 7.2 codebase and understand the constraints.

## What I'd Do Differently in Production

**Persistence with Doctrine ORM**
I'd replace the Redis only storage model with a proper persistence layer using Doctrine ORM. Watch events would be modeled as a `WatchEvent` entity mapped to a MySQL table, giving us full historical queryability and auditability. 

The Redis sorted set approach for active viewer counts would remain.it's the right tool for that specific real-time query; but it would act as a cache layer in front of MySQL rather than the source of truth. Session state would be persisted to MySQL via Doctrine and cached in Redis for low-latency reads.

**Queue-based ingestion**
Symfony Messenger with a Redis transport would handle the ingestion pipeline. The HTTP endpoint enqueues and returns 202. Workers consume and process. This addresses the operations concern about dropped events during spikes.

**Event deduplication**
Track processed eventIds in a Redis SET. Reject duplicates before processing. Critical for delivery guarantees from the queue.

**Authentication**
JWT or API key middleware on all endpoints before going anywhere near production.

**Observability**
Datadog integration for metrics (active viewer counts, ingestion rate, queue depth, error rate) and structured logging. Papertrail/sumologic/elk for quick and easy log ingestion.

## Development

### Git Hooks

A pre-commit hook is included to run tests before each commit. Install it with:
```bash
cp scripts/pre-commit .git/hooks/pre-commit
chmod +x .git/hooks/pre-commit