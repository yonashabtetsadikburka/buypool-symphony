<?php

namespace App\Campaign\Application;

/**
 * Ripartizione delle unità disponibili tra le adesioni (porta di lib/ripartizione.php).
 * Funzione pura: quota proporzionale arrotondata per difetto, le unità residue vanno
 * alle quote con il resto maggiore (a parità, all'adesione più vecchia).
 * Garanzie: la somma restituita è esattamente $available, nessun valore negativo, risultato deterministico.
 */
final class QuantityAllocator
{
    /**
     * @param list<array{id: string, quantity: int, createdAt: \DateTimeImmutable}> $requests
     *
     * @return array<string, int> id adesione → unità assegnate
     */
    public function allocate(array $requests, int $available): array
    {
        $total = array_sum(array_column($requests, 'quantity'));
        if ($total <= 0 || $available <= 0) {
            return [];
        }

        $rows = [];
        $assigned = 0;
        foreach ($requests as $request) {
            $product = $request['quantity'] * $available;
            $base = intdiv($product, $total);
            $assigned += $base;
            $rows[] = ['id' => $request['id'], 'quantity' => $base, 'remainder' => $product % $total, 'createdAt' => $request['createdAt']];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['remainder'], $a['createdAt'], $a['id']] <=> [$a['remainder'], $b['createdAt'], $b['id']]);

        for ($i = 0, $left = $available - $assigned; $i < $left; ++$i) {
            ++$rows[$i]['quantity'];
        }

        $byId = array_column($rows, 'quantity', 'id');

        // stesso ordine delle richieste in ingresso
        return array_replace(array_fill_keys(array_column($requests, 'id'), 0), $byId);
    }
}
