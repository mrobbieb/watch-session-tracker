<?php

namespace App\Tests\Service;

use App\Enum\EventType;
use App\Enum\SessionState;
use App\Model\WatchEvent;
use App\Redis\RedisClient;
use App\Service\SessionTracker;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class SessionTrackerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    private MockInterface $redis;
    private SessionTracker $tracker;

    protected function setUp(): void
    {
        $this->redis = Mockery::mock(RedisClient::class);
        $this->tracker = new SessionTracker($this->redis);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    // --- Helpers ---

    private function makeEvent(
        string $eventType,
        string $sessionId = 'session-123',
        string $eventTimestamp = '2026-02-10T19:32:15.123Z'
    ): WatchEvent {
        return WatchEvent::fromArray([
            'sessionId'      => $sessionId,
            'userId'         => 'user-456',
            'eventType'      => $eventType,
            'eventId'        => 'evt-789',
            'eventTimestamp' => $eventTimestamp,
            'receivedAt'     => $eventTimestamp,
            'payload'        => [
                'eventId'  => 'event-2026-wrestling-finals',
                'position' => 0,
                'quality'  => '1080p',
            ],
        ]);
    }

    // --- New session creation ---

    public function test_start_event_creates_new_session(): void
    {
        $event = $this->makeEvent('start');

        // No existing session
        $this->redis->shouldReceive('getSession')
            ->with('session-123')
            ->andReturn([]);

        // Should create session with active state
        $this->redis->shouldReceive('setSession')
            ->with('session-123', Mockery::on(function (array $data) {
                return $data['state'] === SessionState::Active->value
                    && $data['eventCount'] === 1
                    && $data['duration'] === 0;
            }))
            ->once();

        $this->redis->shouldReceive('appendEvent')->once();
        $this->redis->shouldReceive('trackActiveSession')->once();

        $this->tracker->handle($event);
    }

    // --- State transitions ---

    public function test_heartbeat_keeps_session_active(): void
    {
        $event = $this->makeEvent('heartbeat', 'session-123', '2026-02-10T19:37:15.123Z');

        $this->redis->shouldReceive('getSession')
            ->with('session-123')
            ->andReturn([
                'sessionId'    => 'session-123',
                'userId'       => 'user-456',
                'sportEventId' => 'event-2026-wrestling-finals',
                'state'        => SessionState::Active->value,
                'startedAt'    => '2026-02-10T19:32:15+00:00',
                'lastEventAt'  => '2026-02-10T19:32:15+00:00',
                'duration'     => '0',
                'eventCount'   => '1',
            ]);

        $this->redis->shouldReceive('setSession')
            ->with('session-123', Mockery::on(function (array $data) {
                return $data['state'] === SessionState::Active->value
                    && (int) $data['eventCount'] === 2
                    && (int) $data['duration'] === 300;
            }))
            ->once();

        $this->redis->shouldReceive('appendEvent')->once();
        $this->redis->shouldReceive('trackActiveSession')->once();

        $this->tracker->handle($event);
    }

    public function test_pause_event_transitions_to_paused(): void
    {
        $event = $this->makeEvent('pause', 'session-123', '2026-02-10T19:35:15.123Z');

        $this->redis->shouldReceive('getSession')
            ->andReturn([
                'sessionId'    => 'session-123',
                'userId'       => 'user-456',
                'sportEventId' => 'event-2026-wrestling-finals',
                'state'        => SessionState::Active->value,
                'startedAt'    => '2026-02-10T19:32:15+00:00',
                'lastEventAt'  => '2026-02-10T19:32:15+00:00',
                'duration'     => '0',
                'eventCount'   => '1',
            ]);

        $this->redis->shouldReceive('setSession')
            ->with('session-123', Mockery::on(function (array $data) {
                return $data['state'] === SessionState::Paused->value;
            }))
            ->once();

        $this->redis->shouldReceive('appendEvent')->once();
        $this->redis->shouldReceive('trackActiveSession')->once();

        $this->tracker->handle($event);
    }

    public function test_resume_event_transitions_to_active(): void
    {
        $event = $this->makeEvent('resume', 'session-123', '2026-02-10T19:36:15.123Z');

        $this->redis->shouldReceive('getSession')
            ->andReturn([
                'sessionId'    => 'session-123',
                'userId'       => 'user-456',
                'sportEventId' => 'event-2026-wrestling-finals',
                'state'        => SessionState::Paused->value,
                'startedAt'    => '2026-02-10T19:32:15+00:00',
                'lastEventAt'  => '2026-02-10T19:35:15+00:00',
                'duration'     => '180',
                'eventCount'   => '2',
            ]);

        $this->redis->shouldReceive('setSession')
            ->with('session-123', Mockery::on(function (array $data) {
                return $data['state'] === SessionState::Active->value;
            }))
            ->once();

        $this->redis->shouldReceive('appendEvent')->once();
        $this->redis->shouldReceive('trackActiveSession')->once();

        $this->tracker->handle($event);
    }

    public function test_buffer_start_transitions_to_buffering(): void
    {
        $event = $this->makeEvent('buffer_start', 'session-123', '2026-02-10T19:33:15.123Z');

        $this->redis->shouldReceive('getSession')
            ->andReturn([
                'sessionId'    => 'session-123',
                'userId'       => 'user-456',
                'sportEventId' => 'event-2026-wrestling-finals',
                'state'        => SessionState::Active->value,
                'startedAt'    => '2026-02-10T19:32:15+00:00',
                'lastEventAt'  => '2026-02-10T19:32:15+00:00',
                'duration'     => '0',
                'eventCount'   => '1',
            ]);

        $this->redis->shouldReceive('setSession')
            ->with('session-123', Mockery::on(function (array $data) {
                return $data['state'] === SessionState::Buffering->value;
            }))
            ->once();

        $this->redis->shouldReceive('appendEvent')->once();
        $this->redis->shouldReceive('trackActiveSession')->once();

        $this->tracker->handle($event);
    }

    public function test_end_event_transitions_to_ended_and_removes_from_active(): void
    {
        $event = $this->makeEvent('end', 'session-123', '2026-02-10T19:45:15.123Z');

        $this->redis->shouldReceive('getSession')
            ->andReturn([
                'sessionId'    => 'session-123',
                'userId'       => 'user-456',
                'sportEventId' => 'event-2026-wrestling-finals',
                'state'        => SessionState::Active->value,
                'startedAt'    => '2026-02-10T19:32:15+00:00',
                'lastEventAt'  => '2026-02-10T19:32:15+00:00',
                'duration'     => '0',
                'eventCount'   => '1',
            ]);

        $this->redis->shouldReceive('setSession')
            ->with('session-123', Mockery::on(function (array $data) {
                return $data['state'] === SessionState::Ended->value;
            }))
            ->once();

        $this->redis->shouldReceive('appendEvent')->once();

        // Critical — end event removes from active set, does NOT track
        $this->redis->shouldReceive('removeActiveSession')
            ->with('event-2026-wrestling-finals', 'session-123')
            ->once();

        $this->redis->shouldNotReceive('trackActiveSession');

        $this->tracker->handle($event);
    }

    // --- Duration calculation ---

    public function test_duration_calculates_correctly_between_timestamps(): void
    {
        $event = $this->makeEvent('heartbeat', 'session-123', '2026-02-10T20:32:15.123Z');

        $this->redis->shouldReceive('getSession')
            ->andReturn([
                'sessionId'    => 'session-123',
                'userId'       => 'user-456',
                'sportEventId' => 'event-2026-wrestling-finals',
                'state'        => SessionState::Active->value,
                'startedAt'    => '2026-02-10T19:32:15+00:00',
                'lastEventAt'  => '2026-02-10T19:32:15+00:00',
                'duration'     => '0',
                'eventCount'   => '1',
            ]);

        $this->redis->shouldReceive('setSession')
            ->with('session-123', Mockery::on(function (array $data) {
                return (int) $data['duration'] === 3600; // exactly 1 hour
            }))
            ->once();

        $this->redis->shouldReceive('appendEvent')->once();
        $this->redis->shouldReceive('trackActiveSession')->once();

        $this->tracker->handle($event);
    }

    // --- Query methods ---

    public function test_get_active_viewer_count_returns_redis_count(): void
    {
        $this->redis->shouldReceive('countActiveSessions')
            ->with('event-2026-wrestling-finals')
            ->andReturn(42);

        $count = $this->tracker->getActiveViewerCount('event-2026-wrestling-finals');

        $this->assertSame(42, $count);
    }

    public function test_get_session_details_returns_null_for_unknown_session(): void
    {
        $this->redis->shouldReceive('getSession')
            ->with('unknown-session')
            ->andReturn([]);

        $result = $this->tracker->getSessionDetails('unknown-session');

        $this->assertNull($result);
    }

    public function test_get_session_details_returns_session_with_events(): void
    {
        $this->redis->shouldReceive('getSession')
            ->with('session-123')
            ->andReturn([
                'sessionId' => 'session-123',
                'state'     => SessionState::Active->value,
            ]);

        $this->redis->shouldReceive('getSessionEvents')
            ->with('session-123')
            ->andReturn([['eventType' => 'start']]);

        $result = $this->tracker->getSessionDetails('session-123');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('events', $result);
        $this->assertCount(1, $result['events']);
    }
}