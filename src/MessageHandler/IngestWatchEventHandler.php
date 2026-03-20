<?php

namespace App\MessageHandler;

use App\Message\IngestWatchEvent;
use App\Model\WatchEvent;
use App\Service\SessionTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class IngestWatchEventHandler
{
    public function __construct(
        private SessionTracker $sessionTracker,
        private LoggerInterface $logger
    ) {}

    public function __invoke(IngestWatchEvent $message): void
    {
        try {
            $event = WatchEvent::fromArray($message->payload);
            $this->sessionTracker->handle($event);
        } catch (\ValueError $e) {
            // Unknown event type — log and discard rather than retry
            // Retrying won't fix a bad event type
            $this->logger->warning('Discarding event with unknown type', [
                'eventType' => $message->payload['eventType'] ?? 'missing',
                'sessionId' => $message->payload['sessionId'] ?? 'missing',
            ]);
        } catch (\Exception $e) {
            // Unexpected error — log and rethrow so Messenger retries
            $this->logger->error('Failed to process watch event', [
                'error' => $e->getMessage(),
                'sessionId' => $message->payload['sessionId'] ?? 'missing',
            ]);

            throw $e;
        }
    }
}