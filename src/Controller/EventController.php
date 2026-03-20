<?php

namespace App\Controller;

use App\Model\WatchEvent;
use App\Service\SessionTracker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Message\IngestWatchEvent;

class EventController extends AbstractController
{
    // Add to constructor:
    public function __construct(
        private SessionTracker $sessionTracker,
        private MessageBusInterface $bus
    ) {}
    /**
     * Ingest a player SDK event.
     *
     * POST /events
     */
    #[Route('/events', name: 'ingest_event', methods: ['POST'])]
    public function ingest(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(
                ['error' => 'Invalid JSON payload'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Basic structure check before queuing
        $required = ['sessionId', 'userId', 'eventType', 'eventId', 'eventTimestamp', 'receivedAt', 'payload'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                return $this->json(
                    ['error' => "Missing required field: {$field}"],
                    Response::HTTP_BAD_REQUEST
                );
            }
        }

        $this->bus->dispatch(new IngestWatchEvent($data));

        return $this->json(
            ['status' => 'accepted'],
            Response::HTTP_ACCEPTED
        );
    }

    /**
     * Get the current active viewer count for a sport event.
     *
     * GET /events/{eventId}/viewers
     */
    #[Route('/events/{eventId}/viewers', name: 'active_viewers', methods: ['GET'])]
    public function activeViewers(string $eventId): JsonResponse
    {
        $count = $this->sessionTracker->getActiveViewerCount($eventId);

        return $this->json([
            'eventId' => $eventId,
            'activeViewers' => $count,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * Get session details for a given session ID.
     *
     * GET /sessions/{sessionId}
     */
    #[Route('/sessions/{sessionId}', name: 'session_details', methods: ['GET'])]
    public function sessionDetails(string $sessionId): JsonResponse
    {
        $session = $this->sessionTracker->getSessionDetails($sessionId);

        if ($session === null) {
            return $this->json(
                ['error' => 'Session not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->json($session);
    }
}