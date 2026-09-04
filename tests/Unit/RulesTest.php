<?php

require_once __DIR__ . '/../bootstrap.php';

final class RulesTest extends TestCase
{
    public function test_wildcard_match_list_supports_wildcards(): void
    {
        $this->assertTrue(wildcard_match_list('*.facebook.com,example.com', 'ads.facebook.com'));
        $this->assertFalse(wildcard_match_list('*.facebook.com,example.com', 'news.example.org'));
    }

    public function test_parse_os_min_versions_parses_entries(): void
    {
        $this->assertSame(
            ['ios' => 14.0, 'android' => 10.0],
            parse_os_min_versions('iOS>=14.0, Android>=10')
        );
    }
}
