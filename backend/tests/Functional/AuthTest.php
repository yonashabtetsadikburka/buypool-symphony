<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Entity\User;
use App\Identity\Enum\AccountStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthTest extends WebTestCase
{
    private const EMAIL_DOMAIN = '@auth-test.example';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :d')->setParameter('d', '%'.self::EMAIL_DOMAIN)->execute();

        parent::tearDown();
    }

    public function testValidRegistrationReturns201StoresConsentAndLogsIn(): void
    {
        $this->post('/api/auth/register', $this->payload('nuovo'));

        self::assertResponseStatusCodeSame(201);
        $body = $this->json();
        self::assertTrue($body['ok']);
        self::assertSame('cliente', $body['dati']['ruolo']);
        self::assertSame('attivo', $body['dati']['stato']);
        self::assertSame('nuovo'.self::EMAIL_DOMAIN, $body['dati']['email']);
        self::assertArrayNotHasKey('password', $body['dati']);

        $user = $this->findUser('nuovo');
        self::assertNotNull($user->getPrivacyConsentedAt());
        self::assertNotSame('Password1', $user->getHashedPassword());

        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(200);
    }

    public function testMissingPrivacyReturns400(): void
    {
        $this->post('/api/auth/register', ['privacy' => false] + $this->payload('privacy'));

        self::assertResponseStatusCodeSame(400);
        self::assertSame('PRIVACY_NON_ACCETTATA', $this->json()['errore']['codice']);
        self::assertNull($this->findUser('privacy'));
    }

    public function testInvalidEmailReturns400(): void
    {
        $this->post('/api/auth/register', ['email' => 'non-una-email'] + $this->payload('x'));

        self::assertResponseStatusCodeSame(400);
        self::assertSame('EMAIL_NON_VALIDA', $this->json()['errore']['codice']);
    }

    public function testShortPasswordReturns400(): void
    {
        $this->post('/api/auth/register', ['password' => 'abc123'] + $this->payload('corta'));

        self::assertResponseStatusCodeSame(400);
        self::assertSame('PASSWORD_DEBOLE', $this->json()['errore']['codice']);
    }

    public function testMissingFieldReturns400(): void
    {
        $payload = $this->payload('manca');
        unset($payload['cognome']);
        $this->post('/api/auth/register', $payload);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('CAMPO_MANCANTE', $this->json()['errore']['codice']);
    }

    public function testInvalidJsonReturns400(): void
    {
        $this->client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: '{non json');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('JSON_NON_VALIDO', $this->json()['errore']['codice']);
    }

    public function testDuplicateEmailReturns409(): void
    {
        $this->post('/api/auth/register', $this->payload('doppio'));
        self::assertResponseStatusCodeSame(201);

        $this->client->restart();
        $this->post('/api/auth/register', $this->payload('doppio'));

        self::assertResponseStatusCodeSame(409);
        self::assertSame('EMAIL_GIA_USATA', $this->json()['errore']['codice']);
    }

    public function testLoginWithValidCredentialsReturnsIdentity(): void
    {
        $this->createUser('login');

        $this->post('/api/auth/login', ['email' => 'login'.self::EMAIL_DOMAIN, 'password' => 'Password1']);

        self::assertResponseStatusCodeSame(200);
        $dati = $this->json()['dati'];
        self::assertSame('cliente', $dati['ruolo']);
        self::assertSame('attivo', $dati['stato']);
        self::assertSame('Mario', $dati['nome']);
        self::assertNotEmpty($dati['id']);
    }

    public function testInvalidCredentialsReturn401WithTheSameErrorForUnknownEmailAndWrongPassword(): void
    {
        $this->createUser('esiste');

        $this->post('/api/auth/login', ['email' => 'esiste'.self::EMAIL_DOMAIN, 'password' => 'Sbagliata1']);
        self::assertResponseStatusCodeSame(401);
        $wrongPassword = $this->json();

        $this->post('/api/auth/login', ['email' => 'nessuno'.self::EMAIL_DOMAIN, 'password' => 'Sbagliata1']);
        self::assertResponseStatusCodeSame(401);

        self::assertSame($wrongPassword, $this->json());
        self::assertSame('CREDENZIALI_NON_VALIDE', $wrongPassword['errore']['codice']);
    }

    public function testSuspendedUserCannotLogInButOnlyWithTheRightPassword(): void
    {
        $this->createUser('sospeso', AccountStatus::Sospeso);

        $this->post('/api/auth/login', ['email' => 'sospeso'.self::EMAIL_DOMAIN, 'password' => 'Password1']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('UTENTE_SOSPESO', $this->json()['errore']['codice']);

        $this->post('/api/auth/login', ['email' => 'sospeso'.self::EMAIL_DOMAIN, 'password' => 'Sbagliata1']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testProtectedRouteWithoutSessionReturns401(): void
    {
        $this->client->request('GET', '/api/auth/me');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('NON_AUTENTICATO', $this->json()['errore']['codice']);
    }

    public function testLogoutInvalidatesTheSession(): void
    {
        $this->createUser('uscita');
        $this->post('/api/auth/login', ['email' => 'uscita'.self::EMAIL_DOMAIN, 'password' => 'Password1']);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(200);

        $this->client->request('POST', '/api/auth/logout');
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->json()['dati']['uscito']);

        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $local): array
    {
        return [
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'email' => $local.self::EMAIL_DOMAIN,
            'password' => 'Password1',
            'privacy' => true,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function post(string $uri, array $data): void
    {
        $this->client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function createUser(string $local, AccountStatus $status = AccountStatus::Attivo): User
    {
        $container = self::getContainer();
        $user = (new User('Mario', $local.self::EMAIL_DOMAIN))->setLastName('Rossi')->setStatus($status);
        $user->setHashedPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Password1'));
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function findUser(string $local): ?User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(User::class)->findOneBy(['email' => $local.self::EMAIL_DOMAIN]);
    }
}
