<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testHealthyReturns200(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['status' => 'ok', 'checks' => ['database' => 'ok']],
            json_decode((string) $client->getResponse()->getContent(), true)
        );
    }

    public function testUnreachableDatabaseReturns503(): void
    {
        self::ensureKernelShutdown();

        // Real env vars win over .env files, so the kernel boots with an
        // unreachable database without touching any committed file.
        $previousServer = $_SERVER['DATABASE_URL'] ?? null;
        $previousEnv = $_ENV['DATABASE_URL'] ?? null;
        $_SERVER['DATABASE_URL'] = 'mysql://root:root@127.0.0.1:1/buypool_unreachable?serverVersion=8.0';
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'];

        try {
            $client = static::createClient();
            $client->request('GET', '/api/health');
        } finally {
            // Si ripristina il valore di prima (non basta toglierlo): i test che girano dopo ne hanno bisogno.
            if (null === $previousServer) {
                unset($_SERVER['DATABASE_URL']);
            } else {
                $_SERVER['DATABASE_URL'] = $previousServer;
            }
            if (null === $previousEnv) {
                unset($_ENV['DATABASE_URL']);
            } else {
                $_ENV['DATABASE_URL'] = $previousEnv;
            }
        }

        self::assertResponseStatusCodeSame(503);
        self::assertSame(
            ['status' => 'error', 'checks' => ['database' => 'error']],
            json_decode((string) $client->getResponse()->getContent(), true)
        );
    }
}
