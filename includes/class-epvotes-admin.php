<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pagina di amministrazione: stato dell'importazione automatica (via
 * WP-Cron), diagnostica sul cron stesso, e un controllo manuale con
 * numero di votazioni a scelta per test immediati.
 */
class EPVotes_Admin
{
    /** Soglia oltre la quale avvisiamo che l'operazione potrebbe richiedere tempo, ma NON blocchiamo: è solo un consiglio. */
    private const RECOMMENDED_MANUAL_VOTES = 15;

    /** Limite tecnico di sicurezza (non un consiglio): evita solo errori di battitura assurdi tipo "99999999". */
    private const HARD_TECHNICAL_LIMIT = 500;

    public function __construct()
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_epvotes_run_now', [$this, 'run_now']);
        add_action('admin_post_epvotes_cleanup_orphans', [$this, 'cleanup_orphans']);
    }

    public function menu(): void
    {
        add_menu_page(
            'EP Votes',
            'EP Votes',
            'manage_options',
            'ep-votes',
            [$this, 'page'],
            'dashicons-list-view'
        );
    }

    public function page(): void
    {
        global $wpdb;

        $members_table = $wpdb->prefix . 'epvotes_members';
        $votes_table = $wpdb->prefix . 'epvotes_votes';

        $total_votes = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$votes_table}");
        $total_members = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$members_table}");
        $missing_parties = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$members_table} WHERE national_party_fetched_at IS NULL");
        $orphan_count = $this->count_orphans($wpdb, $votes_table);

        $last_run = get_option('epvotes_last_import_run');
        $next_import = wp_next_scheduled('epvotes_cron_import');
        $next_parties = wp_next_scheduled('epvotes_cron_fetch_parties');
        $next_logos = wp_next_scheduled('epvotes_cron_fetch_party_logos');

        $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        ?>
        <div class="wrap">
            <h1>EP Votes</h1>

            <p>
                L'importazione è automatica: un controllo ogni 15 minuti crea un
                articolo per ogni nuova votazione pubblicata dal Parlamento
                europeo. Il partito nazionale e i loghi dei partiti vengono
                recuperati in background, un po' alla volta.
            </p>

            <?php if ($wp_cron_disabled) : ?>
                <div class="notice notice-warning">
                    <p>
                        <strong>Attenzione:</strong> il tuo sito ha <code>DISABLE_WP_CRON</code>
                        attivo in <code>wp-config.php</code>. Questo significa che il cron di
                        WordPress non parte da solo: deve essere richiamato da un vero cron di
                        sistema (es. una riga in crontab che chiama
                        <code><?php echo esc_html(site_url('wp-cron.php')); ?></code> ogni
                        15 minuti). Senza questo, gli orari "pianificati" qui sotto non
                        scatteranno mai da soli.
                    </p>
                </div>
            <?php else : ?>
                <div class="notice notice-info">
                    <p>
                        <strong>Come funziona davvero il cron di WordPress:</strong> non è un
                        vero processo in background, ma scatta a ogni visita del sito dopo
                        l'orario pianificato (è il comportamento standard di WordPress, non
                        una scelta di questo plugin). Su un sito con traffico regolare
                        funziona in modo affidabile; su un sito con pochissime visite può
                        ritardare. Se vuoi la massima puntualità, puoi impostare un cron di
                        sistema reale che richiama
                        <code><?php echo esc_html(site_url('wp-cron.php')); ?></code>.
                    </p>
                </div>
            <?php endif; ?>

            <table class="widefat" style="max-width:700px">
                <tbody>
                    <tr>
                        <td><strong>Votazioni importate</strong></td>
                        <td><?php echo esc_html((string) $total_votes); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Deputati in anagrafica</strong></td>
                        <td><?php echo esc_html((string) $total_members); ?></td>
                    </tr>
                    <tr>
                        <td><strong>In attesa del partito nazionale</strong></td>
                        <td><?php echo esc_html((string) $missing_parties); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Prossima importazione pianificata</strong></td>
                        <td><?php echo $next_import ? esc_html(date_i18n('j F Y H:i', $next_import)) : 'non pianificata (verrà ripristinata automaticamente al prossimo caricamento del plugin)'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Prossimo recupero partiti pianificato</strong></td>
                        <td><?php echo $next_parties ? esc_html(date_i18n('j F Y H:i', $next_parties)) : 'non pianificato'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Prossimo recupero loghi pianificato</strong></td>
                        <td><?php echo $next_logos ? esc_html(date_i18n('j F Y H:i', $next_logos)) : 'non pianificato'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Votazioni "orfane" (articolo cancellato manualmente)</strong></td>
                        <td><?php echo esc_html((string) $orphan_count); ?></td>
                    </tr>
                </tbody>
            </table>

            <?php if ($orphan_count > 0) : ?>
                <div class="notice notice-warning">
                    <p>
                        Ci sono <?php echo (int) $orphan_count; ?> votazioni nel database il cui
                        articolo WordPress è stato cancellato manualmente prima che questa
                        versione del plugin esistesse. Finché restano, il plugin le considera
                        "già importate" e non le riproporrà mai più automaticamente. Ripulendole,
                        torneranno disponibili per una nuova importazione (manuale o al prossimo
                        giro del cron).
                    </p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('epvotes_cleanup_orphans'); ?>
                        <input type="hidden" name="action" value="epvotes_cleanup_orphans">
                        <?php submit_button('Ripulisci votazioni orfane', 'secondary'); ?>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (!empty($last_run['time'])) : ?>
                <h2>Ultima esecuzione (<?php echo esc_html($last_run['time']); ?>)</h2>
                <p>
                    Votazioni importate in quel giro:
                    <?php echo esc_html((string) count($last_run['result']['imported'] ?? [])); ?>
                </p>
                <?php if (!empty($last_run['result']['errors'])) : ?>
                    <p><strong>Errori riscontrati:</strong></p>
                    <ul>
                        <?php foreach ($last_run['result']['errors'] as $error) : ?>
                            <li><?php echo esc_html($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>

            <h2>Importazione manuale</h2>
            <p>
                Sicura da rieseguire quante volte vuoi: una votazione già
                importata non viene mai duplicata (né come riga nel database,
                né come articolo) — viene semplicemente saltata o aggiornata.
                Se le votazioni più recenti sono già tutte importate, il
                sistema va automaticamente a cercare quelle precedenti in
                ordine cronologico, finché non trova abbastanza novità o
                finisce lo storico disponibile.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('epvotes_run_now'); ?>
                <input type="hidden" name="action" value="epvotes_run_now">
                <p>
                    <label for="epvotes_count">Quante votazioni importare (nessun limite imposto; sopra le <?php echo (int) self::RECOMMENDED_MANUAL_VOTES; ?> l'operazione può richiedere qualche minuto e, su hosting molto limitati, rischiare il timeout):</label><br>
                    <input type="number" id="epvotes_count" name="count" min="1" max="<?php echo (int) self::HARD_TECHNICAL_LIMIT; ?>" value="5">
                </p>
                <?php submit_button('Importa ora'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Esegue subito un'importazione, con il numero di votazioni scelto
     * dall'utente (non più legato alla logica di bootstrap del cron
     * automatico, che qui viene esplicitamente scavalcata). Il numero non è
     * limitato oltre una soglia tecnica di sicurezza: valori alti
     * comportano solo un'esecuzione più lunga (più pagine dell'API
     * interrogate per andare a cercare le votazioni precedenti).
     */
    public function run_now(): void
    {
        check_admin_referer('epvotes_run_now');

        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato.');
        }

        $count = isset($_POST['count']) ? (int) $_POST['count'] : 5;
        $count = max(1, min(self::HARD_TECHNICAL_LIMIT, $count));

        $importer = new EPVotes_Importer();
        $result = $importer->import_latest($count);

        // Un'importazione manuale esplicita conta come "bootstrap avvenuto":
        // i prossimi giri del cron useranno il ritmo normale, non ripartiranno da 2.
        update_option('epvotes_bootstrapped', true);
        update_option('epvotes_last_import_run', [
            'time'   => current_time('mysql'),
            'result' => $result,
        ]);

        echo '<div class="wrap"><h1>Risultato</h1>';
        printf(
            '<p>Votazioni importate: %d</p>',
            count($result['imported'])
        );
        if (!empty($result['errors'])) {
            echo '<ul>';
            foreach ($result['errors'] as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul>';
        }
        printf(
            '<p><a href="%s">&larr; Torna indietro</a></p>',
            esc_url(admin_url('admin.php?page=ep-votes'))
        );
        echo '</div>';
        exit;
    }

    /**
     * Conta le votazioni il cui post_id punta a un articolo WordPress che
     * non esiste più (cancellato manualmente prima che esistesse l'aggancio
     * automatico su before_delete_post, oppure svuotando il cestino).
     */
    private function count_orphans(object $wpdb, string $votes_table): int
    {
        $postIds = $wpdb->get_col("SELECT post_id FROM {$votes_table} WHERE post_id IS NOT NULL");

        $orphans = 0;
        foreach ($postIds as $postId) {
            if (get_post((int) $postId) === null) {
                $orphans++;
            }
        }

        return $orphans;
    }

    /**
     * Rimuove dalle nostre tabelle le votazioni orfane (vedi count_orphans()),
     * così tornano disponibili per una nuova importazione.
     */
    public function cleanup_orphans(): void
    {
        check_admin_referer('epvotes_cleanup_orphans');

        if (!current_user_can('manage_options')) {
            wp_die('Non autorizzato.');
        }

        global $wpdb;

        $votes_table = $wpdb->prefix . 'epvotes_votes';
        $member_votes_table = $wpdb->prefix . 'epvotes_member_votes';

        $rows = $wpdb->get_results("SELECT external_id, post_id FROM {$votes_table} WHERE post_id IS NOT NULL");

        $removed = 0;
        foreach ($rows as $row) {
            if (get_post((int) $row->post_id) !== null) {
                continue; // l'articolo esiste ancora, non è orfana
            }

            $wpdb->delete($member_votes_table, ['vote_external_id' => (int) $row->external_id], ['%d']);
            $wpdb->delete($votes_table, ['external_id' => (int) $row->external_id], ['%d']);
            $removed++;
        }

        echo '<div class="wrap"><h1>Pulizia completata</h1>';
        printf('<p>Votazioni orfane rimosse: %d. Torneranno disponibili alla prossima importazione.</p>', $removed);
        printf(
            '<p><a href="%s">&larr; Torna indietro</a></p>',
            esc_url(admin_url('admin.php?page=ep-votes'))
        );
        echo '</div>';
        exit;
    }
}
