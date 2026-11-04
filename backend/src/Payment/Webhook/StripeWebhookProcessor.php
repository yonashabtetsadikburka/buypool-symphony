<?php

namespace App\Payment\Webhook;

use App\Participation\Service\SucceededPaymentHandler;
use App\Payment\Entity\StripeEvent;
use App\Payment\Gateway\PaymentIntentData;
use App\Payment\Repository\StripeEventRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Elabora un evento Stripe già verificato, una sola volta (legacy: pagamento_webhook, tabella eventi_stripe).
 * L'evento viene registrato prima di essere elaborato e segnato "elaborato" solo se tutto è andato bene:
 * se l'elaborazione fallisce l'errore sale (risposta 500), Stripe ripete e il pagamento non si perde.
 *
 * Eventi: payment_intent.succeeded (acconto → adesione, saldo → pagata). Gli altri sono registrati e ignorati;
 * i pagamenti falliti e l'addebito del saldo alla chiusura sono la US-11 e la US-26.
 */
final class StripeWebhookProcessor
{
    public const PROCESSED = 'processed';
    public const DUPLICATE = 'duplicate';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StripeEventRepository $events,
        private readonly SucceededPaymentHandler $payments,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<mixed> $event JSON dell'evento Stripe, già decodificato
     *
     * @return self::PROCESSED|self::DUPLICATE
     */
    public function process(string $eventId, string $eventType, array $event): string
    {
        $record = $this->events->findOneByEventId($eventId);
        if (null !== $record && $record->isProcessed()) {
            return self::DUPLICATE;
        }

        if (null === $record) {
            $record = new StripeEvent($eventId, $eventType);
            $this->em->persist($record);
            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException) {
                // Lo stesso evento è arrivato due volte insieme: se ne sta già occupando l'altra richiesta.
                return self::DUPLICATE;
            }
        }

        try {
            if ('payment_intent.succeeded' === $eventType) {
                $object = $event['data']['object'] ?? null;
                $outcome = $this->payments->handle(PaymentIntentData::fromStripeObject(\is_array($object) ? $object : []));
                $this->logger->info('Evento Stripe elaborato', ['evento' => $eventId, 'esito' => $outcome->value]);
            }
            $record->markProcessed();
            $this->em->flush();
        } catch (\Throwable $e) {
            // L'evento resta "non elaborato": al prossimo invio di Stripe si riprova.
            $this->logger->error('Elaborazione evento Stripe fallita', ['evento' => $eventId, 'errore' => $e::class]);
            throw $e;
        }

        return self::PROCESSED;
    }
}
