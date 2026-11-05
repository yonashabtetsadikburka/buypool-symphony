<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * US-22: profilo del cliente (lettura, aggiornamento, cambio password).
 */
final class ProfileTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $counter = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheProfileNeedsALogin(): void
    {
        foreach ([['GET', '/api/profile'], ['PUT', '/api/profile'], ['POST', '/api/profile/password']] as [$method, $uri]) {
            $this->send($method, $uri);
            self::assertResponseStatusCodeSame(401, $method.' '.$uri);
        }
    }

    public function testTheProfileShowsTheCustomersData(): void
    {
        $user = $this->customer();
        $user->setPhone('3331234567')->setAddress('Via Roma 1')->setZipCode('06100')->setCity('Perugia')->setProvince('PG');
        $this->em->flush();
        $this->client->loginUser($user);

        $this->send('GET', '/api/profile');

        self::assertResponseStatusCodeSame(200);
        $data = $this->json()['dati'];
        self::assertSame($user->getId()->toRfc4122(), $data['id']);
        self::assertSame('Mario', $data['nome']);
        self::assertSame('Rossi', $data['cognome']);
        self::assertSame($user->getEmail(), $data['email']);
        self::assertSame('Via Roma 1', $data['indirizzo']);
        self::assertSame('06100', $data['cap']);
        self::assertSame('Perugia', $data['citta']);
        self::assertSame('PG', $data['provincia']);
        self::assertSame('privato', $data['tipo']);
        self::assertSame('cliente', $data['ruolo']);
        self::assertArrayNotHasKey('password', $data);
        self::assertArrayNotHasKey('password_hash', $data);
    }

    public function testTheCustomerUpdatesTheData(): void
    {
        $user = $this->customer();
        $this->client->loginUser($user);

        $this->send('PUT', '/api/profile', [
            'nome' => '  Luigi ',
            'cognome' => 'Verdi',
            'telefono' => '3339998888',
            'indirizzo' => 'Via Dante 5',
            'cap' => '06121',
            'citta' => 'Perugia',
            'provincia' => 'pg',
            'codice_fiscale' => 'rssmra80a01h501u',
            'partita_iva' => '01234567897',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['aggiornato' => true], $this->json()['dati']);
        $this->em->clear();
        $stored = $this->em->find(User::class, $user->getId());
        self::assertSame('Luigi', $stored->getFirstName());
        self::assertSame('Verdi', $stored->getLastName());
        self::assertSame('Via Dante 5', $stored->getAddress());
        self::assertSame('PG', $stored->getProvince());
        self::assertSame('RSSMRA80A01H501U', $stored->getFiscalCode());
        self::assertSame('01234567897', $stored->getVatNumber());
    }

    public function testOnlyTheSentFieldsChangeAndEmptyOnesAreCleared(): void
    {
        $user = $this->customer();
        $user->setPhone('3331234567')->setCity('Perugia');
        $this->em->flush();
        $this->client->loginUser($user);

        $this->send('PUT', '/api/profile', ['telefono' => '']);

        self::assertResponseStatusCodeSame(200);
        $this->em->clear();
        $stored = $this->em->find(User::class, $user->getId());
        self::assertNull($stored->getPhone());
        self::assertSame('Perugia', $stored->getCity());
        self::assertSame('Mario', $stored->getFirstName());
    }

    /**
     * @dataProvider invalidUpdates
     *
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidUpdates')]
    public function testInvalidDataIsRejectedAndNothingIsSaved(array $body, string $code): void
    {
        $user = $this->customer();
        $this->client->loginUser($user);

        $this->send('PUT', '/api/profile', $body + ['citta' => 'Roma']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame($code, $this->json()['errore']['codice']);
        $this->em->clear();
        self::assertNotSame('Roma', $this->em->find(User::class, $user->getId())->getCity(), 'Un errore non salva neanche gli altri campi.');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidUpdates(): iterable
    {
        yield 'nome vuoto' => [['nome' => '  '], 'CAMPO_MANCANTE'];
        yield 'nome lungo' => [['nome' => str_repeat('a', 101)], 'NOME_TROPPO_LUNGO'];
        yield 'cap lungo' => [['cap' => '12345678901'], 'CAP_NON_VALIDO'];
        yield 'citta lunga' => [['citta' => str_repeat('a', 101), 'cap' => '06100'], 'CITTA_NON_VALIDA'];
        yield 'provincia' => [['provincia' => 'PGX'], 'PROVINCIA_NON_VALIDA'];
        yield 'partita iva' => [['partita_iva' => '12345678901'], 'PIVA_NON_VALIDA'];
        yield 'codice fiscale' => [['codice_fiscale' => 'CORTO'], 'CF_NON_VALIDO'];
        yield 'telefono' => [['telefono' => str_repeat('1', 31)], 'TELEFONO_NON_VALIDO'];
        yield 'campo non testo' => [['telefono' => ['a']], 'CAMPO_NON_VALIDO'];
    }

    public function testAnUpdateWithoutKnownFieldsIsRejected(): void
    {
        $this->client->loginUser($this->customer());

        $this->send('PUT', '/api/profile', ['email' => 'altra@example.com', 'ruolo' => 'admin']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('NESSUN_CAMPO', $this->json()['errore']['codice']);
    }

    public function testEmailAndRoleCannotBeChangedFromTheProfile(): void
    {
        $user = $this->customer();
        $email = $user->getEmail();
        $this->client->loginUser($user);

        $this->send('PUT', '/api/profile', ['nome' => 'Gino', 'email' => 'altra@example.com', 'ruolo' => 'admin', 'tipo' => 'b2b']);

        self::assertResponseStatusCodeSame(200);
        $this->em->clear();
        $stored = $this->em->find(User::class, $user->getId());
        self::assertSame($email, $stored->getEmail());
        self::assertSame('cliente', $stored->getRole()->value);
        self::assertSame('privato', $stored->getCustomerType()->value);
    }

    public function testThePasswordChangesAndOnlyTheNewOneWorks(): void
    {
        $user = $this->customer();
        $this->client->loginUser($user);

        $this->send('POST', '/api/profile/password', ['vecchia_password' => 'Vecchia123', 'nuova_password' => 'Nuova4567']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['password_aggiornata' => true], $this->json()['dati']);
        // La sessione resta valida dopo il cambio.
        $this->send('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(200);

        $this->client->request('POST', '/api/auth/logout');
        $this->send('POST', '/api/auth/login', ['email' => $user->getEmail(), 'password' => 'Vecchia123']);
        self::assertResponseStatusCodeSame(401);
        $this->send('POST', '/api/auth/login', ['email' => $user->getEmail(), 'password' => 'Nuova4567']);
        self::assertResponseStatusCodeSame(200);
    }

    public function testAWrongCurrentPasswordIsRefusedWithoutChangingAnything(): void
    {
        $user = $this->customer();
        $this->client->loginUser($user);

        $this->send('POST', '/api/profile/password', ['vecchia_password' => 'Sbagliata1', 'nuova_password' => 'Nuova4567']);

        self::assertResponseStatusCodeSame(403, 'Non 401: la pagina lo scambierebbe per una sessione scaduta.');
        self::assertSame('PASSWORD_VECCHIA_ERRATA', $this->json()['errore']['codice']);
        $this->em->clear();
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($this->em->find(User::class, $user->getId()) ?? self::fail(), 'Vecchia123'));
    }

    public function testAWeakNewPasswordIsRefused(): void
    {
        $this->client->loginUser($this->customer());

        foreach (['corta1', 'senzanumeri', '12345678'] as $weak) {
            $this->send('POST', '/api/profile/password', ['vecchia_password' => 'Vecchia123', 'nuova_password' => $weak]);
            self::assertResponseStatusCodeSame(400, $weak);
            self::assertSame('PASSWORD_DEBOLE', $this->json()['errore']['codice']);
        }
    }

    public function testMissingPasswordFieldsAreRefused(): void
    {
        $this->client->loginUser($this->customer());

        $this->send('POST', '/api/profile/password', ['nuova_password' => 'Nuova4567']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('CAMPO_MANCANTE', $this->json()['errore']['codice']);
    }

    public function testAnAccountWithoutAPasswordCannotChangeIt(): void
    {
        $user = $this->customer();
        $user->setHashedPassword(null);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->send('POST', '/api/profile/password', ['vecchia_password' => 'qualsiasi1', 'nuova_password' => 'Nuova4567']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('ACCOUNT_SENZA_PASSWORD', $this->json()['errore']['codice']);
    }

    private function customer(): User
    {
        $user = (new User('Mario', 'p'.++$this->counter.'-'.bin2hex(random_bytes(3)).'@profile-test.example'))->setLastName('Rossi');
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setHashedPassword($hasher->hashPassword($user, 'Vecchia123'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function send(string $method, string $uri, array $data = []): void
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: 'GET' === $method ? null : json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
