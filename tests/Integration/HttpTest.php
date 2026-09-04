<?php

require_once __DIR__ . '/../bootstrap.php';

final class HttpTest extends TestCase
{
    public function test_unknown_slug_is_404(): void
    {
        $response = HttpFixture::request('GET', '/definitely-missing');

        $this->assertSame(404, $response['status']);
    }

    public function test_api_without_bearer_is_401(): void
    {
        $response = HttpFixture::request('GET', '/api/links');

        $this->assertSame(401, $response['status']);
        $this->assertTrue(str_contains($response['body'], 'API key required'));
    }

    public function test_data_path_is_not_downloadable(): void
    {
        $response = HttpFixture::request('GET', '/data/cloaking.db');

        $this->assertSame(404, $response['status']);
        $this->assertSame('Not Found', $response['body']);
    }
}
