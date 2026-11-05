<?php

namespace App\Identity\Service;

use App\Identity\Entity\User;
use App\Shared\Http\ApiError;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Profilo del cliente collegato (US-22, legacy: profilo_leggi, profilo_aggiorna, profilo_cambia_password).
 * L'email e il tipo di cliente non si cambiano da qui. Gli errori hanno gli stessi codici del legacy; il primo campo
 * non valido interrompe l'aggiornamento e non viene salvato nulla.
 */
final class ProfileService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'nome' => $user->getFirstName(),
            'cognome' => $user->getLastName() ?? '',
            'email' => $user->getEmail(),
            'telefono' => $user->getPhone() ?? '',
            'indirizzo' => $user->getAddress() ?? '',
            'cap' => $user->getZipCode() ?? '',
            'citta' => $user->getCity() ?? '',
            'provincia' => $user->getProvince() ?? '',
            'partita_iva' => $user->getVatNumber(),
            'codice_fiscale' => $user->getFiscalCode(),
            'ragione_sociale' => $user->getCompanyName(),
            'tipo' => $user->getCustomerType()->value,
            'ruolo' => $user->getRole()->value,
            'data_iscrizione' => $user->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * Aggiorna solo i campi presenti nella richiesta.
     *
     * @param array<string, mixed> $data nome, cognome, telefono, indirizzo, cap, citta, provincia, partita_iva, codice_fiscale
     *
     * @return array{aggiornato: bool}
     */
    public function update(User $user, array $data): array
    {
        $changes = [];
        $known = ['nome', 'cognome', 'telefono', 'indirizzo', 'cap', 'citta', 'provincia', 'partita_iva', 'codice_fiscale'];
        foreach ($known as $field) {
            if (\array_key_exists($field, $data)) {
                $changes[$field] = $this->text($data[$field], $field);
            }
        }
        if ([] === $changes) {
            throw new ApiError('NESSUN_CAMPO', 'Nessun campo da aggiornare');
        }

        // Si controlla tutto prima di toccare l'utente: un errore non lascia modifiche a metà.
        $validated = [];
        foreach ($changes as $field => $value) {
            $validated[$field] = $this->validate($field, $value);
        }

        foreach ($validated as $field => $value) {
            match ($field) {
                'nome' => $user->setFirstName((string) $value),
                'cognome' => $user->setLastName($value),
                'telefono' => $user->setPhone($value),
                'indirizzo' => $user->setAddress($value),
                'cap' => $user->setZipCode($value),
                'citta' => $user->setCity($value),
                'provincia' => $user->setProvince($value),
                'partita_iva' => $user->setVatNumber($value),
                'codice_fiscale' => $user->setFiscalCode($value),
            };
        }
        $this->em->flush();

        return ['aggiornato' => true];
    }

    /**
     * @param array<string, mixed> $data vecchia_password, nuova_password
     *
     * @return array{password_aggiornata: bool}
     */
    public function changePassword(User $user, array $data): array
    {
        $old = $this->required($data, 'vecchia_password');
        $new = $this->required($data, 'nuova_password');

        if (!PasswordPolicy::isValid($new)) {
            throw new ApiError('PASSWORD_DEBOLE', 'La password deve avere almeno 8 caratteri, una lettera e un numero');
        }
        if (null === $user->getHashedPassword()) {
            // Account creato con Google: non ha una password da cambiare (la imposterà il recupero password, US-17).
            throw new ApiError('ACCOUNT_SENZA_PASSWORD', "Questo account accede con Google e non ha una password da cambiare", 409);
        }
        if (!$this->hasher->isPasswordValid($user, $old)) {
            // 403 e non 401: un 401 farebbe credere alla pagina che la sessione sia scaduta e la farebbe uscire.
            throw new ApiError('PASSWORD_VECCHIA_ERRATA', "La password attuale non è corretta", 403);
        }

        $user->setHashedPassword($this->hasher->hashPassword($user, $new));
        $this->em->flush();

        return ['password_aggiornata' => true];
    }

    private function validate(string $field, string $value): string|null
    {
        switch ($field) {
            case 'nome':
                if ('' === $value) {
                    throw new ApiError('CAMPO_MANCANTE', 'Manca il campo: nome');
                }
                if (mb_strlen($value) > 100) {
                    throw new ApiError('NOME_TROPPO_LUNGO', 'Nome e cognome: massimo 100 caratteri');
                }

                return $value;
            case 'cognome':
                if (mb_strlen($value) > 100) {
                    throw new ApiError('NOME_TROPPO_LUNGO', 'Nome e cognome: massimo 100 caratteri');
                }

                return $this->nullable($value);
            case 'telefono':
                if (mb_strlen($value) > 30) {
                    throw new ApiError('TELEFONO_NON_VALIDO', 'Telefono: massimo 30 caratteri');
                }

                return $this->nullable($value);
            case 'indirizzo':
                if (mb_strlen($value) > 255) {
                    throw new ApiError('INDIRIZZO_NON_VALIDO', 'Indirizzo: massimo 255 caratteri');
                }

                return $this->nullable($value);
            case 'cap':
                if (mb_strlen($value) > 10) {
                    throw new ApiError('CAP_NON_VALIDO', 'CAP: massimo 10 caratteri');
                }

                return $this->nullable($value);
            case 'citta':
                if (mb_strlen($value) > 100) {
                    throw new ApiError('CITTA_NON_VALIDA', 'Città: massimo 100 caratteri');
                }

                return $this->nullable($value);
            case 'provincia':
                $province = strtoupper($value);
                if ('' !== $province && 1 !== preg_match('/^[A-Z]{2}$/', $province)) {
                    throw new ApiError('PROVINCIA_NON_VALIDA', 'Provincia: 2 lettere (es. PG)');
                }

                return $this->nullable($province);
            case 'partita_iva':
                $vat = (string) preg_replace('/\s+/', '', $value);
                if ('' === $vat) {
                    return null;
                }
                $normalized = VatNumber::normalize($vat);
                if (null === $normalized) {
                    throw new ApiError('PIVA_NON_VALIDA', 'Partita IVA non valida (11 cifre con cifra di controllo)');
                }

                return $normalized;
            default: // codice_fiscale
                $fiscalCode = strtoupper((string) preg_replace('/\s+/', '', $value));
                if ('' !== $fiscalCode && 1 !== preg_match('/^[A-Z0-9]{16}$/', $fiscalCode)) {
                    throw new ApiError('CF_NON_VALIDO', 'Codice fiscale: 16 caratteri alfanumerici');
                }

                return $this->nullable($fiscalCode);
        }
    }

    private function nullable(string $value): ?string
    {
        return '' === $value ? null : $value;
    }

    private function text(mixed $value, string $field): string
    {
        if (null === $value) {
            return '';
        }
        if (!\is_scalar($value)) {
            throw new ApiError('CAMPO_NON_VALIDO', "Campo non valido: $field");
        }

        return trim((string) $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function required(array $data, string $field): string
    {
        $value = $data[$field] ?? '';
        $value = \is_scalar($value) ? (string) $value : '';
        if ('' === $value) {
            throw new ApiError('CAMPO_MANCANTE', "Manca il campo: $field");
        }

        return $value;
    }
}
