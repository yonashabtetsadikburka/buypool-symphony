<?php

namespace App\Identity\Service;

use App\Identity\Entity\User;
use App\Identity\Enum\CustomerType;
use App\Shared\Http\ApiError;

/**
 * Tipo di cliente (privato o b2b) letto dalla richiesta di registrazione o dal primo accesso con Google.
 * Per i privati non si salva nulla; per i b2b ragione sociale e partita IVA sono obbligatorie e validate.
 */
final class CustomerTypeApplier
{
    public const COMPANY_NAME_MAX_LENGTH = 190;

    /**
     * @param array<string, mixed> $data tipo, ragione_sociale, partita_iva
     */
    public static function apply(User $user, array $data): void
    {
        $type = \is_string($data['tipo'] ?? null) ? trim($data['tipo']) : '';
        if (CustomerType::B2b->value !== $type) {
            $user->setCustomerType(CustomerType::Privato);

            return;
        }

        $company = \is_string($data['ragione_sociale'] ?? null) ? trim($data['ragione_sociale']) : '';
        if ('' === $company) {
            throw new ApiError('RAGIONE_SOCIALE_MANCANTE', 'Inserisci la ragione sociale', 400, ['ragione_sociale' => 'Campo obbligatorio']);
        }
        if (mb_strlen($company) > self::COMPANY_NAME_MAX_LENGTH) {
            throw new ApiError('RAGIONE_SOCIALE_LUNGA', 'Ragione sociale: massimo 190 caratteri', 400, ['ragione_sociale' => 'Massimo 190 caratteri']);
        }
        $vat = VatNumber::normalize(\is_string($data['partita_iva'] ?? null) ? $data['partita_iva'] : '');
        if (null === $vat) {
            throw new ApiError('PIVA_NON_VALIDA', 'Partita IVA non valida (11 cifre con cifra di controllo)', 400, ['partita_iva' => 'Partita IVA non valida']);
        }

        $user->setCustomerType(CustomerType::B2b)->setCompanyName($company)->setVatNumber($vat);
    }
}
