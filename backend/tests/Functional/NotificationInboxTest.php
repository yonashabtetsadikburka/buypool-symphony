<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Entity\User;
use App\Notification\Entity\Notification;
use App\Notification\Enum\NotificationType;
use App\Notification\Enum\ReferenceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * US-23 (parte lettura): le mie notifiche, il conteggio delle non lette e "segna come letta".
 */
final class NotificationInboxTest extends WebTestCase
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

    public function testTheInboxNeedsALogin(): void
    {
        foreach ([['GET', '/api/notifications'], ['GET', '/api/notifications/unread-count'], ['POST', '/api/notifications/read-all'], ['PUT', '/api/notifications/018f0000-0000-7000-8000-000000000000/read']] as [$method, $uri]) {
            $this->client->request($method, $uri);
            self::assertResponseStatusCodeSame(401, $method.' '.$uri);
        }
    }

    public function testTheListShowsOnlyMyNotificationsNewestFirstWithTheLegacyFields(): void
    {
        $me = $this->user();
        $other = $this->user();
        $old = $this->notify($me, 'Vecchia', new \DateTimeImmutable('-2 days'));
        $new = $this->notify($me, 'Recente', new \DateTimeImmutable('-1 hour'), ReferenceType::Colletta, '018f1111-0000-7000-8000-000000000001');
        $this->notify($other, 'Di un altro', new \DateTimeImmutable('-1 minute'));
        $this->client->loginUser($me);

        $this->client->request('GET', '/api/notifications');

        self::assertResponseStatusCodeSame(200);
        $list = $this->json()['dati'];
        self::assertSame([$new->getId()->toRfc4122(), $old->getId()->toRfc4122()], array_column($list, 'id'));
        self::assertSame('Recente', $list[0]['titolo']);
        self::assertSame('Messaggio di Recente', $list[0]['messaggio']);
        self::assertSame('MOQ_RAGGIUNTO', $list[0]['tipo']);
        self::assertSame('colletta', $list[0]['tipo_riferimento']);
        self::assertSame('018f1111-0000-7000-8000-000000000001', $list[0]['id_riferimento']);
        self::assertFalse($list[0]['letta']);
        self::assertNull($list[1]['tipo_riferimento']);
        self::assertNull($list[1]['id_riferimento']);
        self::assertNotEmpty($list[0]['data_creazione']);
    }

    public function testTheListIsLimitedToTheLatest200(): void
    {
        $me = $this->user();
        for ($i = 0; $i < 205; ++$i) {
            $this->em->persist(new Notification($me, NotificationType::Sistema, 'N'.$i, 'm'));
        }
        $this->em->flush();
        $this->client->loginUser($me);

        $this->client->request('GET', '/api/notifications');

        self::assertCount(200, $this->json()['dati']);
    }

    public function testTheUnreadCountOnlyCountsMyUnreadOnes(): void
    {
        $me = $this->user();
        $other = $this->user();
        $this->notify($me, 'Una', new \DateTimeImmutable('-3 hours'));
        $this->notify($me, 'Due', new \DateTimeImmutable('-2 hours'));
        $this->notify($me, 'Letta', new \DateTimeImmutable('-1 hour'))->markRead();
        $this->notify($other, 'Altrui', new \DateTimeImmutable('-1 hour'));
        $this->em->flush();
        $this->client->loginUser($me);

        $this->client->request('GET', '/api/notifications/unread-count');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['non_lette' => 2], $this->json()['dati']);
    }

    public function testAnUnreadNotificationCanBeMarkedAsReadAndTheCountDrops(): void
    {
        $me = $this->user();
        $first = $this->notify($me, 'Una', new \DateTimeImmutable('-2 hours'));
        $this->notify($me, 'Due', new \DateTimeImmutable('-1 hour'));
        $this->client->loginUser($me);

        $this->client->request('PUT', '/api/notifications/'.$first->getId()->toRfc4122().'/read');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['letta' => true], $this->json()['dati']);

        // Ripetere l'operazione non è un errore.
        $this->client->request('PUT', '/api/notifications/'.$first->getId()->toRfc4122().'/read');
        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/notifications/unread-count');
        self::assertSame(['non_lette' => 1], $this->json()['dati']);
        $this->em->clear();
        $stored = $this->em->find(Notification::class, $first->getId());
        self::assertTrue($stored->isRead());
        self::assertNotNull($stored->getReadAt());
    }

    public function testSomeoneElsesOrUnknownNotificationsAreNotFound(): void
    {
        $me = $this->user();
        $theirs = $this->notify($this->user(), 'Altrui', new \DateTimeImmutable('-1 hour'));
        $this->client->loginUser($me);

        $this->client->request('PUT', '/api/notifications/'.$theirs->getId()->toRfc4122().'/read');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('NOTIFICA_INESISTENTE', $this->json()['errore']['codice']);

        $this->client->request('PUT', '/api/notifications/018f0000-0000-7000-8000-000000000000/read');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('NOTIFICA_INESISTENTE', $this->json()['errore']['codice']);

        $this->em->clear();
        self::assertFalse($this->em->find(Notification::class, $theirs->getId())->isRead(), 'La notifica altrui non si tocca.');
    }

    public function testMarkAllAsReadOnlyTouchesMyUnreadOnes(): void
    {
        $me = $this->user();
        $other = $this->user();
        $this->notify($me, 'Una', new \DateTimeImmutable('-3 hours'));
        $this->notify($me, 'Due', new \DateTimeImmutable('-2 hours'));
        $this->notify($me, 'Letta', new \DateTimeImmutable('-1 hour'))->markRead();
        $theirs = $this->notify($other, 'Altrui', new \DateTimeImmutable('-1 hour'));
        $this->em->flush();
        $this->client->loginUser($me);

        $this->client->request('POST', '/api/notifications/read-all');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['aggiornate' => 2], $this->json()['dati']);
        $this->client->request('GET', '/api/notifications/unread-count');
        self::assertSame(['non_lette' => 0], $this->json()['dati']);
        $this->client->request('POST', '/api/notifications/read-all');
        self::assertSame(['aggiornate' => 0], $this->json()['dati']);
        $this->em->clear();
        self::assertFalse($this->em->find(Notification::class, $theirs->getId())->isRead());
    }

    private function user(): User
    {
        $user = new User('Cliente', 'n'.++$this->counter.'-'.bin2hex(random_bytes(3)).'@inbox-test.example');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function notify(User $user, string $title, \DateTimeImmutable $when, ?ReferenceType $reference = null, ?string $referenceId = null): Notification
    {
        $notification = (new Notification($user, NotificationType::MoqRaggiunto, $title, 'Messaggio di '.$title))->setReference($reference, $referenceId);
        $this->em->persist($notification);
        $this->em->flush();
        // La data di creazione la decide il costruttore: la si sposta per provare l'ordine.
        $this->em->getConnection()->executeStatement('UPDATE notifications SET created_at = ? WHERE id = ?', [$when->format('Y-m-d H:i:s'), $notification->getId()->toBinary()]);
        $this->em->refresh($notification);

        return $notification;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
