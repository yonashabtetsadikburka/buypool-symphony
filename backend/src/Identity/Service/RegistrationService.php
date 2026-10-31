<?php

namespace App\Identity\Service;

use App\Identity\Entity\User;
use App\Identity\Repository\UserRepository;
use App\Shared\Http\ApiError;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Registrazione di un cliente (porta di auth_registrazione() del legacy).
 * Le regole sono controllate nello stesso ordine del legacy, così a parità di input esce lo stesso errore.
 */
final class RegistrationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * @param array<string, mixed> $data nome, cognome, email, password, privacy
     */
    public function register(array $data): User
    {
        $firstName = self::required($data, 'nome');
        $lastName = self::required($data, 'cognome');
        $email = self::required($data, 'email');
        $password = self::required($data, 'password');

        if (mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            throw new ApiError('NOME_TROPPO_LUNGO', 'Nome e cognome: massimo 100 caratteri');
        }
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL) || \strlen($email) > 190) {
            throw new ApiError('EMAIL_NON_VALIDA', 'Indirizzo email non valido');
        }
        if (!PasswordPolicy::isValid($password)) {
            throw new ApiError('PASSWORD_DEBOLE', 'La password deve avere almeno 8 caratteri, una lettera e un numero');
        }
        if (empty($data['privacy'])) {
            throw new ApiError('PRIVACY_NON_ACCETTATA', "Devi accettare l'informativa privacy per registrarti");
        }

        if (null !== $this->users->findOneByEmail($email)) {
            throw self::duplicateEmail();
        }

        $user = (new User($firstName, $email))
            ->setLastName($lastName)
            ->setPrivacyConsentedAt(new \DateTimeImmutable());
        $user->setHashedPassword($this->hasher->hashPassword($user, $password));

        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw self::duplicateEmail();
        }

        return $user;
    }

    private static function duplicateEmail(): ApiError
    {
        return new ApiError('EMAIL_GIA_USATA', 'Questa email è già registrata. Accedi o reimposta la password.', 409);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function required(array $data, string $field): string
    {
        $value = $data[$field] ?? '';
        $value = \is_scalar($value) ? trim((string) $value) : '';
        if ('' === $value) {
            throw new ApiError('CAMPO_MANCANTE', "Manca il campo: $field");
        }

        return $value;
    }
}
