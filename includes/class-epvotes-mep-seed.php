<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Accesso all'istantanea statica dei deputati (data/meps-seed.php),
 * generata una tantum dal file XML fornito dall'utente. Serve a popolare
 * subito il partito nazionale invece di aspettare il recupero in
 * background dal sito ufficiale del PE (vedi class-epvotes-importer.php).
 */
final class EPVotes_Mep_Seed
{
    private static ?array $data = null;

    /**
     * @return array<int, array{full_name: string, country: string, national_party: string}>
     */
    public static function data(): array
    {
        if (self::$data === null) {
            self::$data = require EPVOTES_PLUGIN_DIR . 'data/meps-seed.php';
        }
        return self::$data;
    }
}
