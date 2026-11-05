<?php

/**
 * Processo figlio del test di concorrenza (ConcurrencyTest): avvia il kernel di test, segnala "pronto", aspetta il via
 * e poi esegue UNA operazione sul database di test, in parallelo all'altro processo. Stampa il risultato in JSON.
 *
 * Uso: php concurrency_worker.php <withdraw|deposit> <file del via> <file pronto> <parametri JSON>
 */

use App\Identity\Entity\User;
use App\Kernel;
use App\Participation\Service\PaidDeposit;
use App\Shared\Http\ApiError;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__).'/bootstrap.php';

// Due processi che avviano il kernel insieme possono contendersi la scrittura dei file dei proxy di Doctrine (su Windows
// il rename dà un avviso innocuo: il file c'è comunque). Gli avvisi non devono sporcare l'output JSON.
ini_set('display_errors', '0');
error_reporting(\E_ALL & ~\E_WARNING);

[, $action, $goFile, $readyFile, $payload] = $argv;
$params = json_decode($payload, true, flags: \JSON_THROW_ON_ERROR);

$kernel = new Kernel('test', (bool) ($_SERVER['APP_DEBUG'] ?? false));
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get(EntityManagerInterface::class);
$em->getConnection()->executeQuery('SELECT 1'); // connessione aperta prima del via

$withdrawal = 'withdraw' === $action ? $container->get(App\Participation\Service\WithdrawalService::class) : null;
$deposit = 'deposit' === $action ? $container->get(App\Participation\Service\DepositConfirmationService::class) : null;
$user = 'withdraw' === $action ? $em->find(User::class, Uuid::fromString($params['user'])) : null;

touch($readyFile);
$limit = microtime(true) + 30;
while (!file_exists($goFile)) {
    if (microtime(true) > $limit) {
        fwrite(\STDERR, "via non arrivato\n");
        exit(2);
    }
    usleep(100);
}

if (($params['delayMs'] ?? 0) > 0) {
    usleep((int) $params['delayMs'] * 1000); // sfalsa l'ordine di arrivo per provare entrambe le vittorie
}

$result = ['azione' => $action];
try {
    if (null !== $withdrawal) {
        $result += ['ok' => true, 'dati' => $withdrawal->withdraw($user, $params['campaign'])];
    } else {
        $outcome = $deposit->confirm(new PaidDeposit($params['pi'], $params['amount'], 'pm_test', 'succeeded', [
            'tipo' => 'acconto',
            'colletta_id' => $params['campaign'],
            'quantita' => (string) $params['quantity'],
            'utente_id' => $params['user'],
            'delivery_type' => 'ritiro_sede',
            'id_sede' => $params['pickupPoint'],
            'indirizzo' => '',
        ]));
        $result += ['ok' => true, 'dati' => $outcome->name];
    }
} catch (ApiError $error) {
    $result += ['ok' => false, 'codice' => $error->codice, 'http' => $error->httpStatus];
} catch (Throwable $error) {
    $result += ['ok' => false, 'codice' => 'ECCEZIONE', 'messaggio' => $error::class.': '.$error->getMessage()];
}

echo json_encode($result, \JSON_THROW_ON_ERROR);
