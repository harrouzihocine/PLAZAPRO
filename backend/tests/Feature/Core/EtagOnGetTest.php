<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Tests\TestCase;

class EtagOnGetTest extends TestCase
{
    public function test_json_get_carries_an_etag(): void
    {
        $response = $this->getJson('/api/v1/app-config');

        $response->assertOk();
        $this->assertNotNull($response->headers->get('ETag'));
        $this->assertSame('no-cache, private', $response->headers->get('Cache-Control'));
    }

    public function test_matching_if_none_match_returns_an_empty_304(): void
    {
        $etag = $this->getJson('/api/v1/app-config')->headers->get('ETag');

        $response = $this->withHeaders(['If-None-Match' => $etag])
            ->getJson('/api/v1/app-config');

        $response->assertStatus(304);
        $this->assertSame('', $response->getContent());
    }

    public function test_stale_if_none_match_returns_the_full_body(): void
    {
        $response = $this->withHeaders(['If-None-Match' => '"nope"'])
            ->getJson('/api/v1/app-config');

        $response->assertOk();
        $response->assertJsonPath('service_worker', true);
    }
}
