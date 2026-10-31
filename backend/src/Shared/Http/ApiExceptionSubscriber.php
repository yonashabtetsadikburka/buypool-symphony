<?php

namespace App\Shared\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sotto /api le eccezioni conosciute escono nella forma unica di ApiResponse.
 * Gli errori imprevisti restano a Symfony (500 e log).
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onException'];
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();

        if ($exception instanceof ApiError) {
            $event->setResponse(ApiResponse::fromError($exception));

            return;
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            [$codice, $message] = match ($status) {
                404 => ['ROTTA_INESISTENTE', 'Endpoint non trovato'],
                405 => ['METODO_NON_CONSENTITO', 'Metodo non consentito'],
                429 => ['TROPPI_TENTATIVI', 'Troppi tentativi: riprova tra qualche minuto'],
                default => ['RICHIESTA_NON_VALIDA', 400 === $status ? $exception->getMessage() : 'Richiesta non valida'],
            };

            $event->setResponse(ApiResponse::error($codice, $message, $status));
        }
    }
}
