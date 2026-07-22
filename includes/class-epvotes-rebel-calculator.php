<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calcola, per una singola votazione, se ciascun deputato ha votato in modo
 * difforme dalla maggioranza del proprio gruppo politico ("ribelle").
 *
 * Scelta progettuale: invece di raschiare un sito terzo (mepwatch.eu) per
 * questo dato — fragile, e senza una mappatura affidabile tra i suoi ID
 * voto e quelli di HowTheyVote — lo calcoliamo direttamente dai dati che
 * abbiamo già importato. È lo stesso principio che usano siti come
 * VoteWatch/mepwatch: maggioranza = posizione più frequente nel gruppo per
 * quella votazione (tra chi ha effettivamente votato).
 *
 * Un deputato "assente" (DID_NOT_VOTE) o senza gruppo politico non viene
 * classificato come ribelle o leale: il concetto non si applica (valore null).
 * Se un gruppo è diviso esattamente a metà (nessuna maggioranza netta), non
 * classifichiamo nessuno come ribelle in quel gruppo per quella votazione,
 * per non presentare un risultato ambiguo come se fosse certo.
 */
final class EPVotes_Rebel_Calculator
{
    private const VOTING_POSITIONS = ['FOR', 'AGAINST', 'ABSTENTION'];

    /**
     * @param array<int, array{group_code: string, position: string}> $memberVotes
     * @return array<int, bool|null> Stesso indice di $memberVotes: true = ribelle,
     *                                false = allineato, null = non applicabile
     */
    public static function compute(array $memberVotes): array
    {
        $counts = [];

        foreach ($memberVotes as $mv) {
            $group = $mv['group_code'] ?? '';
            $position = $mv['position'] ?? '';

            if ($group === '' || !in_array($position, self::VOTING_POSITIONS, true)) {
                continue;
            }

            $counts[$group][$position] = ($counts[$group][$position] ?? 0) + 1;
        }

        $majorityByGroup = [];
        foreach ($counts as $group => $positionCounts) {
            arsort($positionCounts);
            $values = array_values($positionCounts);
            $keys = array_keys($positionCounts);

            // Maggioranza netta solo se il valore più alto è strettamente
            // maggiore del secondo (altrimenti è un pareggio: non decidiamo).
            if (count($values) === 1 || $values[0] > $values[1]) {
                $majorityByGroup[$group] = $keys[0];
            }
        }

        $result = [];
        foreach ($memberVotes as $index => $mv) {
            $group = $mv['group_code'] ?? '';
            $position = $mv['position'] ?? '';

            if ($group === '' || !in_array($position, self::VOTING_POSITIONS, true)) {
                $result[$index] = null;
                continue;
            }

            if (!isset($majorityByGroup[$group])) {
                // Gruppo in pareggio: non classifichiamo nessuno come ribelle.
                $result[$index] = null;
                continue;
            }

            $result[$index] = $position !== $majorityByGroup[$group];
        }

        return $result;
    }
}
