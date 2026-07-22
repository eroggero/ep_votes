<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper per scritture multi-riga (batch upsert).
 *
 * Perché esiste: la versione precedente dell'importer chiamava
 * $wpdb->replace() una volta per ogni singolo deputato (fino a ~720 per
 * votazione, moltiplicato per decine di votazioni per esecuzione). Su un
 * hosting condiviso, con il limite tipico di 30-60 secondi di esecuzione
 * PHP, questo andava quasi certamente in timeout a metà lavoro, lasciando
 * l'importazione a un solo deputato salvato. Questo helper riduce ogni
 * votazione a UNA sola query per tabella, indipendentemente dal numero di
 * deputati coinvolti.
 */
final class EPVotes_DB_Helper
{
    /**
     * Inserisce/aggiorna più righe in una sola query
     * (INSERT ... ON DUPLICATE KEY UPDATE).
     *
     * @param object   $wpdb            Istanza $wpdb (non tipizzata nominalmente, vedi importer)
     * @param string   $table           Nome completo della tabella (già con prefisso)
     * @param string[] $columns         Nomi delle colonne, nell'ordine dei valori
     * @param array<int, array<string, mixed>> $rows Righe da scrivere (chiave = nome colonna)
     * @param string[] $update_columns  Colonne da aggiornare in caso di conflitto sulla chiave UNIQUE
     */
    public static function batch_upsert(
        object $wpdb,
        string $table,
        array $columns,
        array $rows,
        array $update_columns
    ): bool {
        if (empty($rows) || empty($columns)) {
            return true;
        }

        $row_placeholder = '(' . implode(', ', array_fill(0, count($columns), '%s')) . ')';
        $values_sql = implode(', ', array_fill(0, count($rows), $row_placeholder));

        $flat_values = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                // I valori NULL vanno passati come stringa 'NULL' letterale
                // sarebbe sbagliato: li lasciamo come null così wpdb->prepare
                // li gestisce correttamente con %s -> stringa vuota non va bene
                // per colonne DATETIME nullable, quindi usiamo un marcatore e
                // lo sostituiamo dopo prepare() con NULL reale.
                $flat_values[] = $value === null ? self::NULL_MARKER : (string) $value;
            }
        }

        $escaped_columns = implode(', ', array_map(
            static fn (string $c): string => '`' . str_replace('`', '', $c) . '`',
            $columns
        ));

        $update_sql = implode(', ', array_map(
            static fn (string $c): string => '`' . str_replace('`', '', $c) . '` = VALUES(`' . str_replace('`', '', $c) . '`)',
            $update_columns
        ));

        $safe_table = '`' . str_replace('`', '', $table) . '`';

        $sql = "INSERT INTO {$safe_table} ({$escaped_columns}) VALUES {$values_sql} ON DUPLICATE KEY UPDATE {$update_sql}";

        $prepared = $wpdb->prepare($sql, $flat_values);
        // Sostituisce il marcatore (che prepare() ha racchiuso tra apici
        // come stringa) con il vero NULL SQL.
        $prepared = str_replace("'" . self::NULL_MARKER . "'", 'NULL', $prepared);

        $result = $wpdb->query($prepared);

        return $result !== false;
    }

    private const NULL_MARKER = '__EPVOTES_NULL__';
}
