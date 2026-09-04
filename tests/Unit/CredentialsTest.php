<?php

require_once __DIR__ . '/../bootstrap.php';

final class CredentialsTest extends TestCase
{
    public function test_fresh_database_is_isolated(): void
    {
        $dbOne = DatabaseFixture::fresh();
        $dbOne->exec("INSERT INTO settings (key, value) VALUES ('alpha', 'one')");

        $dbTwo = DatabaseFixture::fresh();
        $row = $dbTwo->query("SELECT value FROM settings WHERE key = 'alpha'")->fetchColumn();

        $this->assertFalse($row !== false);
        $this->assertSame(false, $row);
    }
}
