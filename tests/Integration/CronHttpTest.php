<?php

declare(strict_types=1);

namespace Billas\Tests\Integration;

/**
 * RouterOS-facing cron endpoint: token auth and task execution over HTTP.
 */
final class CronHttpTest extends HttpTestCase
{
    private const TOKEN = 'testing-cron-token-0123456789abcdef';

    public function testCronRejectsInvalidToken(): void
    {
        $response = $this->http('POST', '/internal/cron/run', [
            'headers' => [
                'X-Cron-Token' => 'wrong-token',
                'Content-Type' => 'application/json',
            ],
            'body' => ['tasks' => ['vouchers'], 'force' => true],
        ]);

        self::assertSame(401, $response->getStatusCode());
        $json = $this->jsonResponse($response);
        self::assertFalse($json['ok']);
        self::assertStringContainsString('Token cron', (string) $json['error']);
    }

    public function testCronRunsRequestedTasksWithValidToken(): void
    {
        $response = $this->http('POST', '/internal/cron/run', [
            'headers' => [
                'X-Cron-Token' => self::TOKEN,
                'Content-Type' => 'application/json',
            ],
            'body' => ['tasks' => ['vouchers'], 'force' => true],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $json = $this->jsonResponse($response);
        self::assertTrue($json['ok']);
        self::assertSame('ok', $json['tasks']['vouchers']['status'] ?? null);
        self::assertGreaterThan(0, (int) $this->scalar(
            "SELECT last_run_at FROM job_state WHERE task = 'vouchers'"
        ));
    }

    public function testCronRejectsNonArrayTasks(): void
    {
        $response = $this->http('POST', '/internal/cron/run', [
            'headers' => [
                'X-Cron-Token' => self::TOKEN,
                'Content-Type' => 'application/json',
            ],
            'body' => ['tasks' => 'vouchers'],
        ]);

        self::assertSame(422, $response->getStatusCode());
        $json = $this->jsonResponse($response);
        self::assertFalse($json['ok']);
        self::assertStringContainsString('tasks', (string) $json['error']);
    }
}
