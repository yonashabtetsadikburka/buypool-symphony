<?php

namespace App\Tests\Unit\Campaign;

use App\Campaign\Application\QuantityAllocator;
use PHPUnit\Framework\TestCase;

final class QuantityAllocatorTest extends TestCase
{
    public function testQuantitiesAreSplitProportionallyAndSumToTheAvailableUnits(): void
    {
        $result = (new QuantityAllocator())->allocate([
            $this->request('a', 6, '2026-11-01'),
            $this->request('b', 3, '2026-11-02'),
            $this->request('c', 1, '2026-11-03'),
        ], 5);

        self::assertSame(['a' => 3, 'b' => 2, 'c' => 0], $result);
    }

    public function testLeftoverUnitsGoToTheLargestRemainderThenTheOldestParticipation(): void
    {
        $result = (new QuantityAllocator())->allocate([
            $this->request('new', 1, '2026-11-05'),
            $this->request('old', 1, '2026-11-01'),
            $this->request('mid', 1, '2026-11-03'),
        ], 2);

        self::assertSame(['new' => 0, 'old' => 1, 'mid' => 1], $result);
    }

    public function testNothingIsAllocatedWithoutRequestsOrUnits(): void
    {
        self::assertSame([], (new QuantityAllocator())->allocate([], 5));
        self::assertSame([], (new QuantityAllocator())->allocate([$this->request('a', 2, '2026-11-01')], 0));
    }

    public function testAllocationIsDeterministicAndNeverNegative(): void
    {
        mt_srand(7);
        $allocator = new QuantityAllocator();
        for ($i = 0; $i < 500; ++$i) {
            $requests = [];
            for ($j = 0, $n = mt_rand(1, 8); $j < $n; ++$j) {
                $requests[] = $this->request('r'.$j, mt_rand(1, 20), sprintf('2026-11-%02d', mt_rand(1, 28)));
            }
            $available = mt_rand(1, 100);

            $result = $allocator->allocate($requests, $available);

            self::assertSame($available, array_sum($result));
            $reversed = $allocator->allocate(array_reverse($requests), $available);
            ksort($result);
            ksort($reversed);
            self::assertSame($result, $reversed, 'order of input must not matter');
            self::assertSame([], array_filter($result, static fn (int $q): bool => $q < 0));
        }
    }

    /** @return array{id: string, quantity: int, createdAt: \DateTimeImmutable} */
    private function request(string $id, int $quantity, string $date): array
    {
        return ['id' => $id, 'quantity' => $quantity, 'createdAt' => new \DateTimeImmutable($date)];
    }
}
