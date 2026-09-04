<?php

abstract class TestCase
{
    protected function fail(string $message): never
    {
        throw new AssertionError($message);
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $message = $message !== '' ? $message : sprintf(
                'Expected %s, got %s',
                var_export($expected, true),
                var_export($actual, true)
            );
            $this->fail($message);
        }
    }

    protected function assertTrue(mixed $actual, string $message = ''): void
    {
        if ($actual !== true) {
            $this->fail($message !== '' ? $message : 'Expected true');
        }
    }

    protected function assertFalse(mixed $actual, string $message = ''): void
    {
        if ($actual !== false) {
            $this->fail($message !== '' ? $message : 'Expected false');
        }
    }

    protected function assertThrows(callable $callback, string $exceptionClass = Throwable::class, ?string $messageContains = null): Throwable
    {
        try {
            $callback();
        } catch (Throwable $e) {
            if (!is_a($e, $exceptionClass)) {
                $this->fail(sprintf(
                    'Expected %s, got %s',
                    $exceptionClass,
                    $e::class
                ));
            }
            if ($messageContains !== null && !str_contains($e->getMessage(), $messageContains)) {
                $this->fail(sprintf(
                    'Expected exception message to contain %s, got %s',
                    var_export($messageContains, true),
                    var_export($e->getMessage(), true)
                ));
            }
            return $e;
        }

        $this->fail(sprintf('Expected %s to be thrown', $exceptionClass));
    }

    /**
     * Runs the test methods on this instance and returns a deterministic
     * result list for the CLI runner.
     *
     * @return array<int, array{name:string,passed:bool,message:string,exception?:string,duration_ms:float}>
     */
    public function run(): array
    {
        $results = [];
        $methods = array_values(array_filter(
            get_class_methods($this),
            static fn (string $method): bool => str_starts_with($method, 'test_')
        ));
        sort($methods, SORT_STRING);

        foreach ($methods as $method) {
            $startedAt = hrtime(true);
            try {
                $this->$method();
                $results[] = [
                    'name' => $method,
                    'passed' => true,
                    'message' => '',
                    'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000,
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'name' => $method,
                    'passed' => false,
                    'message' => $e->getMessage(),
                    'exception' => $e::class,
                    'duration_ms' => (hrtime(true) - $startedAt) / 1_000_000,
                ];
            }
        }

        return $results;
    }
}
