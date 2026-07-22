<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Le votazioni ora sono veri articoli WordPress (creati dall'importer),
 * quindi non serve più uno shortcode per mostrarle: appaiono normalmente
 * nel blog, negli archivi di categoria e nell'RSS.
 *
 * Questa classe si occupa di tre cose:
 *  - caricare CSS/JS della tabella interattiva solo sugli articoli della
 *    categoria "Votazioni Parlamento europeo";
 *  - disattivare wpautop() su quegli stessi articoli: è il filtro che
 *    WordPress applica di default al contenuto per trasformare gli "a capo"
 *    in paragrafi, ma su un HTML complesso come il nostro (tabella, filtri,
 *    menu personalizzato) inserisce paragrafi vuoti spuri in mezzo ai nostri
 *    contenitori — con un layout a CSS grid, un <p></p> vuoto conta come una
 *    cella della griglia e sposta tutto in disordine;
 *  - ripulire le nostre tabelle quando un articolo viene eliminato
 *    definitivamente da WordPress, così una votazione il cui articolo è
 *    stato cancellato torna "nuova" e può essere reimportata, invece di
 *    restare per sempre segnata come "già fatta" nel nostro database.
 */
class EPVotes_Frontend
{
    public function __construct()
    {
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_assets']);
        add_action('wp', [$this, 'maybe_disable_wpautop']);
        add_action('before_delete_post', [$this, 'cleanup_on_post_delete']);
    }

    public function maybe_enqueue_assets(): void
    {
        if (!is_singular('post') || !has_category('votazioni-parlamento-europeo')) {
            return;
        }

        wp_enqueue_style(
            'epvotes-table',
            EPVOTES_PLUGIN_URL . 'css/epvotes-table.css',
            [],
            EPVOTES_VERSION
        );

        wp_enqueue_script(
            'epvotes-table',
            EPVOTES_PLUGIN_URL . 'js/epvotes-table.js',
            [],
            EPVOTES_VERSION,
            true
        );
    }

    /**
     * Rimuove wpautop() dalla catena di filtri di "the_content" quando si
     * sta visitando uno dei nostri articoli. Va agganciato sull'hook 'wp'
     * (query già risolta, contenuto non ancora renderizzato) e non prima,
     * altrimenti is_singular()/has_category() non avrebbero ancora
     * l'informazione giusta su quale pagina si sta visitando. Essendo una
     * pagina di singolo articolo, disattivarlo qui non tocca nessun altro
     * contenuto del sito nella stessa richiesta.
     */
    public function maybe_disable_wpautop(): void
    {
        if (!is_singular('post') || !has_category('votazioni-parlamento-europeo')) {
            return;
        }

        remove_filter('the_content', 'wpautop');
    }

    /**
     * Quando un articolo di una votazione viene eliminato DEFINITIVAMENTE
     * (non solo spostato nel cestino), rimuove anche la riga corrispondente
     * dalle nostre tabelle. Senza questo, il database continuerebbe a
     * considerare quella votazione "già importata" per sempre, anche se
     * l'articolo non esiste più.
     */
    public function cleanup_on_post_delete(int $postId): void
    {
        global $wpdb;

        $voteId = get_post_meta($postId, '_epvotes_vote_id', true);
        if (empty($voteId)) {
            return;
        }

        $votes_table = $wpdb->prefix . 'epvotes_votes';
        $member_votes_table = $wpdb->prefix . 'epvotes_member_votes';

        // Non tocchiamo l'anagrafica deputati (epvotes_members): è dato
        // condiviso, referenziato anche da altre votazioni.
        $wpdb->delete($member_votes_table, ['vote_external_id' => (int) $voteId], ['%d']);
        $wpdb->delete($votes_table, ['external_id' => (int) $voteId], ['%d']);
    }
}
