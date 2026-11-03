<?php

namespace App\Shared\Persistence;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Esegue un lavoro in transazione e salva alla fine. Non usa EntityManager::wrapInTransaction():
 * in caso d'errore quello chiude l'EntityManager, mentre qui un errore di validazione è una normale
 * risposta 4xx (e un webhook ripetuto deve poter ritentare).
 */
final class TransactionRunner
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $result = $work();
            $this->em->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }
}
