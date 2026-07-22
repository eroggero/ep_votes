<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mappa un nome di partito nazionale al proprio logo, e gestisce la cache
 * locale delle immagini (scaricate una volta sola, mai richieste al volo
 * a mepwatch.eu a ogni visita del sito).
 *
 * Fonte della mappatura: data/party-logos.php (derivato dal dataset open
 * source di mepwatch.eu, 163 partiti su 211 presenti nell'elenco fornito
 * dall'utente — per gli altri non esiste un logo noto e si usa il badge
 * con sigla, che funziona sempre come ripiego).
 */
final class EPVotes_Party_Logos
{
    private static ?array $catalog = null;

    private static function catalog(): array
    {
        if (self::$catalog === null) {
            self::$catalog = require EPVOTES_PLUGIN_DIR . 'data/party-logos.php';
        }
        return self::$catalog;
    }

    /**
     * Stessa normalizzazione usata per generare data/party-logos.php:
     * minuscolo, solo lettere e numeri, per rendere il confronto robusto a
     * differenze di spaziatura/accenti/punteggiatura tra le due fonti dati.
     */
    public static function normalize_key(string $partyName): string
    {
        $ascii = remove_accents($partyName);
        $lower = mb_strtolower($ascii);
        return (string) preg_replace('/[^a-z0-9]+/', '', $lower);
    }

    /**
     * Restituisce i metadati del logo noto per un partito (nome ufficiale,
     * sigla, Paese, URL sorgente su mepwatch), o null se non presente nel
     * catalogo.
     */
    public static function lookup(string $partyName): ?array
    {
        $key = self::normalize_key($partyName);
        $catalog = self::catalog();
        return $catalog[$key] ?? null;
    }

    /**
     * Percorso del file locale dove il logo di un partito viene (o verrà)
     * salvato, indipendentemente dal fatto che esista già.
     */
    public static function local_path(string $partyKey): string
    {
        return EPVOTES_PLUGIN_DIR . 'assets/party_logos/' . $partyKey . '.jpg';
    }

    /**
     * URL pubblico del logo locale se già scaricato, altrimenti null (il
     * chiamante deve gestire il fallback al badge con sigla).
     */
    public static function local_url(string $partyName): ?string
    {
        $key = self::normalize_key($partyName);
        if ($key === '' || !is_file(self::local_path($key))) {
            return null;
        }
        return EPVOTES_PLUGIN_URL . 'assets/party_logos/' . $key . '.jpg';
    }

    /**
     * Elenco di tutte le chiavi di partito che hanno un logo noto nel
     * catalogo ma non ancora scaricato localmente (usato dal cron per
     * decidere cosa scaricare).
     *
     * @param string[] $partyNamesInUse Nomi di partito realmente presenti in DB
     * @return array<string, array> chiave normalizzata => metadati del catalogo
     */
    public static function missing_logos(array $partyNamesInUse): array
    {
        $missing = [];
        foreach ($partyNamesInUse as $partyName) {
            $meta = self::lookup($partyName);
            if ($meta === null) {
                continue; // nessun logo noto per questo partito: resterà il badge
            }
            $key = self::normalize_key($partyName);
            if (!is_file(self::local_path($key))) {
                $missing[$key] = $meta;
            }
        }
        return $missing;
    }
}
