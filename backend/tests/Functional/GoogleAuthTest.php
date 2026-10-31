<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Entity\ExternalIdentity;
use App\Identity\Entity\User;
use App\Identity\Enum\AccountStatus;
use App\Identity\Enum\ExternalProvider;
use App\Identity\Google\GoogleIdentity;
use App\Tests\Support\FakeGoogleIdTokenVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GoogleAuthTest extends WebTestCase
{
    private const EMAIL_DOMAIN = '@google-test.example';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeGoogleIdTokenVerifier::$identity = $this->identity('anna');
    }

    protected function tearDown(): void
    {
        FakeGoogleIdTokenVerifier::$identity = null;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM '.User::class.' u WHERE u.email LIKE :d')->setParameter('d', '%'.self::EMAIL_DOMAIN)->execute();

        parent::tearDown();
    }

    public function testFirstLoginCreatesACustomerAndLogsIn(): void
    {
        $this->post(['idToken' => FakeGoogleIdTokenVerifier::VALID_TOKEN, 'privacy' => true]);

        self::assertResponseStatusCodeSame(200);
        $dati = $this->json()['dati'];
        self::assertSame('cliente', $dati['ruolo']);
        self::assertSame('Anna', $dati['nome']);
        self::assertSame('anna'.self::EMAIL_DOMAIN, $dati['email']);

        $user = $this->findUser('anna');
        self::assertNotNull($user->getPrivacyConsentedAt());
        self::assertNull($user->getHashedPassword());
        self::assertNotNull($this->findLink('sub-anna'));

        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(200);
    }

    public function testFirstLoginWithoutPrivacyConsentIsRefusedAndCreatesNothing(): void
    {
        $this->post(['idToken' => FakeGoogleIdTokenVerifier::VALID_TOKEN]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('PRIVACY_NON_ACCETTATA', $this->json()['errore']['codice']);
        self::assertNull($this->findUser('anna'));
    }

    public function testFirstLoginCanCreateABusinessCustomer(): void
    {
        $this->post(['idToken' => FakeGoogleIdTokenVerifier::VALID_TOKEN, 'privacy' => true, 'tipo' => 'b2b', 'ragione_sociale' => 'Anna Srl', 'partita_iva' => '12345678903']);

        self::assertResponseStatusCodeSame(200);
        $user = $this->findUser('anna');
        self::assertSame('b2b', $user->getCustomerType()->value);
        self::assertSame('12345678903', $user->getVatNumber());
    }

    public function testSecondLoginUsesTheLinkedAccountWithoutAskingForPrivacyAgain(): void
    {
        $this->post(['idToken' => FakeGoogleIdTokenVerifier::VALID_TOKEN, 'privacy' => true]);
        $this->client->restart();

        $this->post(['idToken' => FakeGoogleIdTokenVerifier::VALID_TOKEN]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('anna'.self::EMAIL_DOMAIN, $this->json()['dati']['email']);
        self::assertSame(1, $this->countUsers());
    }

    public function testExistingAccountWithTheSameVerifiedEmailIsLinked(): void
    {
        $existing = new User('Anna', 'anna'.self::EMAIL_DOMAIN);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($existing);
        $em->flush();

        $this->post(['idToken' => FakeGoogleIdTokenVerifier::VALID_TOKEN]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->countUsers());
        self::assertSame($existing->getId()->toRfc4122(), $this->json()['dati']['id']);
        self::assertNotNull($this->findLink('sub-anna'));
    }

    public function testUnverifiedEmailCannotTakeOverAnExistingAccount(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new User('Anna', 'anna'.self::EMAIL_DOMAIN));
        $em->flush();
        FakeGoogleIdTokenVerifier::$identity = $this->identity('anna', verified: false);

        $this->post(['idToken' => FakeGoogleIdTokenVerifier::VALID_TOKEN, 'privacy' => true]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('EMAIL_NON_VERIFICATA', $this->json()['errore']['codice']);
        self::assertNull($this->findLink('sub-anna'));
    }

    public function testSuspendedAccountCannotLogIn(): void
    {
        $user = (new User('Anna', 'anna'.self::EMAIL_DOMAIN))->setStatus(AccountStatus::Sospeso);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->persist(new ExternalIdentity(ExternalProvider::Google, 'sub-anna', $user));
        $em->flush();

        $this->post(['idToken' => FakeGoogleIdTokenVerifier::VALID_TOKEN]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('UTENTE_SOSPESO', $this->json()['errore']['codice']);
    }

    public function testInvalidTokenReturns401(): void
    {
        $this->post(['idToken' => 'token-falso']);

        self::assertResponseStatusCodeSame(401);
        self::assertSame('TOKEN_GOOGLE_NON_VALIDO', $this->json()['errore']['codice']);
    }

    public function testMissingTokenReturns400(): void
    {
        $this->post([]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('CAMPO_MANCANTE', $this->json()['errore']['codice']);
    }

    private function identity(string $local, bool $verified = true): GoogleIdentity
    {
        return new GoogleIdentity('sub-'.$local, $local.self::EMAIL_DOMAIN, $verified, 'Anna', 'Bianchi');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function post(array $data): void
    {
        $this->client->request('POST', '/api/auth/google', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function findUser(string $local): ?User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(User::class)->findOneBy(['email' => $local.self::EMAIL_DOMAIN]);
    }

    private function findLink(string $subject): ?ExternalIdentity
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(ExternalIdentity::class)->findOneBy(['provider' => ExternalProvider::Google, 'subject' => $subject]);
    }

    private function countUsers(): int
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return (int) $em->createQuery('SELECT COUNT(u.id) FROM '.User::class.' u WHERE u.email LIKE :d')->setParameter('d', '%'.self::EMAIL_DOMAIN)->getSingleScalarResult();
    }
}
