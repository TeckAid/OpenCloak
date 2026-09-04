<?php

require_once __DIR__ . '/../bootstrap.php';

final class SecurityTest extends TestCase
{
    public function test_harness_reports_failure(): void
    {
        $suite = new class extends TestCase {
            public function test_fails(): void
            {
                $this->assertTrue(false);
            }
        };

        $results = $suite->run();

        $this->assertSame(1, count($results));
        $this->assertFalse($results[0]['passed']);
        $this->assertSame('test_fails', $results[0]['name']);
        $this->assertTrue(str_contains($results[0]['message'], 'Expected true'));
    }
}
