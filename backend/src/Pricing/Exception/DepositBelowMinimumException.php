<?php

namespace App\Pricing\Exception;

final class DepositBelowMinimumException extends \DomainException
{
    public function __construct(public readonly int $depositCents, public readonly int $minimumCents)
    {
        parent::__construct(sprintf('Deposit of %d cents is below the minimum of %d cents.', $depositCents, $minimumCents));
    }
}
