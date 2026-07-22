<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Recupera il partito nazionale di un deputato dalla sua pagina ufficiale
 * sul sito del Parlamento europeo (HowTheyVote non fornisce questo dato).
 *
 * Esempio di pagina: https://www.europarl.europa.eu/meps/en/257155
 * Il blocco informativo si presenta, nel testo della pagina, nella forma:
 *
 *   Italy
 *   - Movimento 5 Stelle (Italy)
 *   Date of birth : ...
 *
 * cioè: nome del Paese, poi "- Nome del partito (stesso Paese)". Il parsing
 * lavora sul testo semplice (dopo strip_tags) invece che su selettori CSS
 * specifici, per restare più resistente a piccole modifiche di markup del
 * sito; se il sito cambia struttura più profondamente, il metodo restituisce
 * semplicemente null (nessun dato) invece di un risultato scorretto.
 *
 * Questa è una fonte ufficiale ma non un'API strutturata: il parsing è
 * quindi un'euristica best-effort, non una garanzia assoluta per ogni
 * deputato (es. per i non iscritti a un partito nazionale riconosciuto).
 */
final class EPVotes_National_Party_Fetcher
{
    private const BASE_URL = 'https://www.europarl.europa.eu/meps/en/';

    /**
     * @return array{ok: bool, national_party?: string|null, error?: string}
     */
    public function fetch(int $memberId): array
    {
        $url = self::BASE_URL . $memberId;

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'User-Agent' => 'WordPress EPVotes Plugin/' . EPVOTES_VERSION,
            ],
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => 'network_error'];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'error' => 'http_error'];
        }

        $html = wp_remote_retrieve_body($response);
        $party = self::extract_national_party($html);

        return ['ok' => true, 'national_party' => $party];
    }

    /**
     * Estrae il partito nazionale dal testo della pagina. Pubblico e
     * statico per poter essere testato in isolamento, senza rete.
     */
    public static function extract_national_party(string $html): ?string
    {
        // Riduce l'HTML a testo semplice, preservando gli "a capo" fra i
        // blocchi (altrimenti "Italy" e "- Partito (Italy)" finirebbero
        // incollati senza separatore).
        $withBreaks = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\s*\/?>/i', "\n", $html);
        $text = html_entity_decode(strip_tags((string) $withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Cerca "NomePaese ... - NomePartito (NomePaese)" con NomePaese
        // ripetuto identico (il sito mostra il Paese una volta come
        // intestazione e una seconda volta tra parentesi accanto al partito).
        $pattern = '/([A-ZÀ-Ý][A-Za-zÀ-ÿ\'\.\s]{2,40}?)\s*[\r\n]+\s*-\s*([^\r\n(){}]{2,150}?)\s*\(\s*\1\s*\)/u';

        if (preg_match($pattern, $text, $matches) === 1) {
            $party = trim($matches[2]);
            return $party !== '' ? $party : null;
        }

        return null;
    }
}
