<?php

namespace App\Shared\Persistence;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Blocco "a nome" sul database (MySQL GET_LOCK), come nel legacy: serve a far lavorare un solo processo alla volta
 * su un'operazione lunga che chiama Stripe (chiusura di un gruppo), dove un blocco di riga non basterebbe.
 * Il blocco vale per la connessione e si rilascia sempre a fine lavoro.
 */
final class NamedLock
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Esegue $work con il blocco; se un altro processo lo tiene restituisce null senza fare nulla.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T|null
     */
    public function runExclusive(string $name, callable $work): mixed
    {
        $connection = $this->em->getConnection();
        $lock = 'bp_'.md5($name);
        if (1 !== (int) $connection->fetchOne('SELECT GET_LOCK(?, 0)', [$lock])) {
            return null;
        }

        try {
            return $work();
        } finally {
            $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
