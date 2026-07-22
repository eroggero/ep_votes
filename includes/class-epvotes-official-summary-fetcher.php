<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Recupera ed estrae i "punti chiave" dai comunicati stampa ufficiali del
 * Parlamento europeo (europarl.europa.eu/news/.../press-room/...).
 *
 * Base legale per il riutilizzo: il Legal Notice ufficiale del PE
 * (https://www.europarl.europa.eu/legal-notice/en) autorizza esplicitamente
 * la riproduzione dei contenuti testuali di proprietà dell'UE "a condizione
 * che l'intero elemento sia riprodotto e la fonte sia riconosciuta". Per
 * questo:
 *  - estraiamo SEMPRE l'intero blocco (tutti i punti, non una selezione);
 *  - l'attribuzione con link alla fonte è generata insieme al contenuto e
 *    non è mai omessa (vedi EPVotes_Post_Builder).
 *
 * Questo NON si applica a howtheyvote.eu (il cui "Database Contents
 * License" esclude esplicitamente le sintesi dei voti): questa classe
 * quindi si rifiuta di operare su URL che non siano del dominio ufficiale
 * europarl.europa.eu.
 */
final class EPVotes_Official_Summary_Fetcher
{
    /**
     * Classi CSS note per il blocco "punti chiave" nei comunicati stampa del
     * PE. Più varianti per tolleranza a piccole differenze di markup.
     */
    private const TARGET_CLASSES = ['ep-a_facts', 'ep-a_text', 'ep_a_facts', 'ep_a_text'];

    /**
     * @return array{ok: bool, bullets?: string[], error?: string}
     */
    public function fetch(string $pressReleaseUrl): array
    {
        if (!class_exists('DOMDocument')) {
            return ['ok' => false, 'error' => 'dom_extension_missing'];
        }

        $host = parse_url($pressReleaseUrl, PHP_URL_HOST);
        if ($host === null || !str_ends_with($host, 'europarl.europa.eu')) {
            // Deliberato: questa funzionalità si basa sul Legal Notice di
            // europarl.europa.eu. Non la estendiamo ad altri domini (es.
            // howtheyvote.eu) solo perché l'URL è presente in un campo
            // generico "fonte".
            return ['ok' => false, 'error' => 'not_europarl_domain'];
        }

        $response = wp_remote_get($pressReleaseUrl, [
            'timeout' => 20,
            'headers' => ['User-Agent' => 'WordPress EPVotes Plugin/' . EPVOTES_VERSION],
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => 'network_error'];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return ['ok' => false, 'error' => 'http_error'];
        }

        $html = wp_remote_retrieve_body($response);
        $bullets = self::extract_bullets($html);

        if (empty($bullets)) {
            return ['ok' => false, 'error' => 'content_not_found'];
        }

        return ['ok' => true, 'bullets' => $bullets];
    }

    /**
     * Estrae i punti chiave dall'HTML della pagina. Pubblico e statico per
     * essere testabile senza rete. Usa DOMDocument/DOMXPath (non regex: con
     * <div> annidati una regex non può stabilire in modo affidabile dove
     * finisce il blocco cercato).
     *
     * Nota importante: la pagina può contenere PIÙ blocchi con la stessa
     * classe (es. un piccolo box "in sintesi" da una riga in cima
     * all'articolo, che riusa lo stesso stile del vero elenco completo più
     * sotto). Prendere semplicemente il primo trovato può quindi restituire
     * un estratto incompleto. Per questo esaminiamo TUTTI i blocchi
     * candidati (su tutte le classi note) e scegliamo quello con più punti
     * elenco (<li>), assumendo che il vero elenco completo ne abbia diversi
     * mentre un eventuale teaser ne abbia al massimo uno.
     *
     * @return string[] Frasi già ripulite (senza bullet, con punto finale)
     */
    public static function extract_bullets(string $html): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        // L'header XML forza l'interpretazione UTF-8 (altrimenti
        // DOMDocument può interpretare male gli accenti).
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $bestNode = null;
        $bestListItems = null;
        $bestCount = -1;

        foreach (self::TARGET_CLASSES as $class) {
            $candidates = $xpath->query(
                "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]"
            );
            if ($candidates === false) {
                continue;
            }

            foreach ($candidates as $candidate) {
                $listItems = $xpath->query('.//li', $candidate);
                $count = ($listItems !== false) ? $listItems->length : 0;

                if ($count > $bestCount) {
                    $bestCount = $count;
                    $bestNode = $candidate;
                    $bestListItems = $listItems;
                }
            }
        }

        if ($bestNode === null) {
            return [];
        }

        $bullets = [];

        if ($bestListItems !== null && $bestListItems->length > 0) {
            foreach ($bestListItems as $li) {
                $bullet = self::clean_sentence($li->textContent);
                if ($bullet !== '') {
                    $bullets[] = $bullet;
                }
            }
        } else {
            // Nessun elenco puntato nel blocco scelto (nessuno dei
            // candidati aveva <li>): lo trattiamo come un unico paragrafo.
            $paragraph = self::clean_sentence($bestNode->textContent);
            if ($paragraph !== '') {
                $bullets[] = $paragraph;
            }
        }

        return $bullets;
    }

    /**
     * Ripulisce il testo di un punto: spazi multipli, e aggiunge un punto
     * finale se manca (le frasi originali nell'HTML non sempre ce l'hanno,
     * essendo elementi di un elenco puntato piuttosto che frasi a sé stanti).
     */
    private static function clean_sentence(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return '';
        }
        if (!preg_match('/[.!?]$/u', $text)) {
            $text .= '.';
        }
        return $text;
    }
}
