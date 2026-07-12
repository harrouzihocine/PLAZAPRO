<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Modules\Web\Models\WebStatEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The anonymous analytics sink: batched inserts, a strict event whitelist, and
 * nothing identifying beyond the client-side random session key.
 */
class PublicTrackTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = 'abcdef0123456789abcdef0123456789';

    public function test_a_batch_of_events_is_recorded_with_request_context(): void
    {
        $this->postJson('/api/v1/public/track', [
            'session' => self::SESSION,
            'locale' => 'ar',
            'referrer' => 'https://facebook.com/some-ad',
            'events' => [
                ['event' => 'page_view', 'path' => '/plaza'],
                ['event' => 'project_view', 'location_id' => 42, 'path' => '/plaza/projects/42'],
                ['event' => 'whatsapp_click'],
            ],
        ], ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) Mobile'])->assertNoContent();

        $this->assertSame(3, WebStatEvent::count());

        $projectView = WebStatEvent::where('event', 'project_view')->sole();
        $this->assertSame(self::SESSION, $projectView->session_key);
        $this->assertSame(42, (int) $projectView->location_id);
        $this->assertSame('ar', $projectView->locale);
        $this->assertTrue($projectView->is_mobile);
        $this->assertSame('https://facebook.com/some-ad', $projectView->referrer);
    }

    public function test_unknown_events_and_malformed_sessions_are_rejected(): void
    {
        $this->postJson('/api/v1/public/track', [
            'session' => self::SESSION,
            'events' => [['event' => 'drop_tables']],
        ])->assertUnprocessable();

        $this->postJson('/api/v1/public/track', [
            'session' => 'not-a-hex-key!',
            'events' => [['event' => 'page_view']],
        ])->assertUnprocessable();

        $this->assertSame(0, WebStatEvent::count());
    }

    public function test_a_batch_is_capped_at_twenty_events(): void
    {
        $events = array_fill(0, 21, ['event' => 'page_view']);

        $this->postJson('/api/v1/public/track', [
            'session' => self::SESSION,
            'events' => $events,
        ])->assertUnprocessable();
    }
}
