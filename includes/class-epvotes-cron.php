<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestisce l'importazione automatica in background tramite WP-Cron.
 *
 * Due eventi pianificati, entrambi a piccoli lotti (per non rischiare
 * timeout su hosting condiviso, vedi class-epvotes-importer.php):
 *
 *  - epvotes_cron_import: controlla se ci sono nuove votazioni e le importa
 *    (al massimo poche per esecuzione). La primissima volta importa solo le
 *    2 votazioni più recenti, per verificare subito che tutto funzioni senza
 *    scaricare l'intero storico.
 *  - epvotes_cron_fetch_parties: recupera in background, pochi alla volta,
 *    il partito nazionale dei deputati non ancora noto (dal sito ufficiale
 *    del PE), con un intervallo di rispetto tra una richiesta e l'altra.
 */
class EPVotes_Cron
{
    private const IMPORT_HOOK = 'epvotes_cron_import';
    private const PARTIES_HOOK = 'epvotes_cron_fetch_parties';
    private const LOGOS_HOOK = 'epvotes_cron_fetch_party_logos';
    private const INTERVAL = 'epvotes_fifteen_minutes';

    /** Votazioni nuove importate per ogni esecuzione (dopo il primo avvio). */
    private const VOTES_PER_RUN = 3;

    /** Votazioni importate al primissimo avvio (bootstrap). */
    private const BOOTSTRAP_VOTES = 2;

    /** Deputati per cui recuperare il partito nazionale a ogni esecuzione. */
    private const PARTIES_PER_RUN = 10;

    /** Loghi di partito da scaricare a ogni esecuzione. */
    private const LOGOS_PER_RUN = 15;

    public function __construct()
    {
        add_filter('cron_schedules', [$this, 'register_interval']);
        add_action(self::IMPORT_HOOK, [$this, 'run_import']);
        add_action(self::PARTIES_HOOK, [$this, 'run_fetch_parties']);
        add_action(self::LOGOS_HOOK, [$this, 'run_fetch_party_logos']);
    }

    public function register_interval(array $schedules): array
    {
        $schedules[self::INTERVAL] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __('Ogni 15 minuti (EP Votes)', 'ep-votes'),
        ];

        return $schedules;
    }

    public static function activate(): void
    {
        if (!wp_next_scheduled(self::IMPORT_HOOK)) {
            wp_schedule_event(time(), self::INTERVAL, self::IMPORT_HOOK);
        }
        if (!wp_next_scheduled(self::PARTIES_HOOK)) {
            wp_schedule_event(time() + 300, self::INTERVAL, self::PARTIES_HOOK);
        }
        if (!wp_next_scheduled(self::LOGOS_HOOK)) {
            wp_schedule_event(time() + 600, self::INTERVAL, self::LOGOS_HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::IMPORT_HOOK);
        wp_clear_scheduled_hook(self::PARTIES_HOOK);
        wp_clear_scheduled_hook(self::LOGOS_HOOK);
    }

    /**
     * Esegue un ciclo di importazione. Pubblico per poter essere richiamato
     * anche manualmente dalla pagina di amministrazione ("Controlla ora").
     *
     * @return array{ok: bool, imported: int[], errors: string[]}
     */
    public function run_import(): array
    {
        $bootstrapped = get_option('epvotes_bootstrapped', false);
        $maxVotes = $bootstrapped ? self::VOTES_PER_RUN : self::BOOTSTRAP_VOTES;

        $importer = new EPVotes_Importer();
        $result = $importer->import_latest($maxVotes);

        if (!$bootstrapped) {
            update_option('epvotes_bootstrapped', true);
        }

        update_option('epvotes_last_import_run', [
            'time'   => current_time('mysql'),
            'result' => $result,
        ]);

        return $result;
    }

    /**
     * Recupera il partito nazionale per un piccolo numero di deputati che
     * non lo hanno ancora (in ordine di prima apparizione), con una piccola
     * pausa tra una richiesta e l'altra per rispetto verso il sito del PE.
     *
     * @return array{ok: bool, fetched: int, errors: string[]}
     */
    public function run_fetch_parties(): array
    {
        global $wpdb;

        $members_table = $wpdb->prefix . 'epvotes_members';
        $member_votes_table = $wpdb->prefix . 'epvotes_member_votes';
        $votes_table = $wpdb->prefix . 'epvotes_votes';

        $members = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT member_id FROM {$members_table} WHERE national_party_fetched_at IS NULL LIMIT %d",
                self::PARTIES_PER_RUN
            )
        );

        $fetcher = new EPVotes_National_Party_Fetcher();
        $fetched = 0;
        $errors = [];
        $updatedMemberIds = [];

        foreach ($members as $member) {
            $result = $fetcher->fetch((int) $member->member_id);

            if (!$result['ok']) {
                $errors[] = "Deputato {$member->member_id}: {$result['error']}";
                // Anche in caso di errore aggiorniamo il timestamp, altrimenti
                // un deputato la cui pagina dà costantemente errore verrebbe
                // ritentato ad ogni esecuzione, rallentando il recupero degli
                // altri. Riproveremo comunque più avanti (vedi nota sotto).
            }

            $wpdb->update(
                $members_table,
                [
                    'national_party'            => $result['national_party'] ?? null,
                    'national_party_fetched_at' => current_time('mysql'),
                ],
                ['member_id' => $member->member_id],
                ['%s', '%s'],
                ['%d']
            );

            if (!empty($result['national_party'])) {
                $updatedMemberIds[] = (int) $member->member_id;
            }

            $fetched++;

            // Piccola pausa di cortesia tra una richiesta e l'altra verso il
            // sito ufficiale del PE (non è un'API pensata per raffiche di
            // richieste automatiche).
            usleep(400000);
        }

        // Il contenuto degli articoli è HTML statico generato al momento
        // dell'importazione: se il partito nazionale di un deputato arriva
        // solo ora (in background, dopo che l'articolo è già pubblicato),
        // va rigenerato. Lo facciamo solo per le votazioni recenti (30
        // giorni) in cui compare uno dei deputati appena aggiornati, per
        // tenere il costo limitato invece di ricostruire tutto lo storico.
        if (!empty($updatedMemberIds)) {
            $this->refresh_recent_posts_for_members($updatedMemberIds);
        }

        return ['ok' => empty($errors), 'fetched' => $fetched, 'errors' => $errors];
    }

    /**
     * Scarica in locale (assets/party_logos/) i loghi dei partiti nazionali
     * effettivamente presenti nel database e non ancora scaricati, un
     * piccolo lotto alla volta (stesso motivo delle altre esecuzioni a
     * lotti: niente timeout, niente raffiche verso il sito sorgente).
     *
     * @return array{ok: bool, downloaded: int, errors: string[]}
     */
    public function run_fetch_party_logos(): array
    {
        global $wpdb;

        $members_table = $wpdb->prefix . 'epvotes_members';

        $partyNames = $wpdb->get_col(
            "SELECT DISTINCT national_party FROM {$members_table} WHERE national_party IS NOT NULL AND national_party <> ''"
        );

        $missing = EPVotes_Party_Logos::missing_logos($partyNames);
        $missing = array_slice($missing, 0, self::LOGOS_PER_RUN, true);

        $downloaded = 0;
        $errors = [];
        $downloadedKeys = [];

        foreach ($missing as $key => $meta) {
            $response = wp_remote_get($meta['source_url'], [
                'timeout' => 15,
                'headers' => ['User-Agent' => 'WordPress EPVotes Plugin/' . EPVOTES_VERSION],
            ]);

            if (is_wp_error($response)) {
                $errors[] = "Logo {$meta['name']}: {$response->get_error_message()}";
                usleep(300000);
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $contentType = wp_remote_retrieve_header($response, 'content-type');

            // Verifica del tipo MIME prima di salvare, come richiesto dai
            // requisiti di sicurezza del progetto: non fidarsi solo
            // dell'estensione .jpg nell'URL sorgente.
            $isImage = is_string($contentType) && str_starts_with($contentType, 'image/');

            if ($status !== 200 || !$isImage || strlen($body) < 100) {
                $errors[] = "Logo {$meta['name']}: risposta non valida (status {$status}, content-type " . (string) $contentType . ')';
                usleep(300000);
                continue;
            }

            $path = EPVotes_Party_Logos::local_path($key);
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }

            if (file_put_contents($path, $body) !== false) {
                $downloaded++;
                $downloadedKeys[] = $key;
            } else {
                $errors[] = "Logo {$meta['name']}: impossibile scrivere il file locale";
            }

            usleep(300000);
        }

        if (!empty($downloadedKeys)) {
            $affectedMemberIds = $wpdb->get_col(
                "SELECT member_id FROM {$members_table} WHERE national_party IS NOT NULL AND national_party <> ''"
            );
            // Filtriamo in PHP (non in SQL) i deputati il cui partito
            // corrisponde a uno dei loghi appena scaricati, usando la stessa
            // normalizzazione del catalogo (vedi EPVotes_Party_Logos).
            $partyByMember = $wpdb->get_results(
                "SELECT member_id, national_party FROM {$members_table} WHERE national_party IS NOT NULL AND national_party <> ''"
            );
            $matchedMemberIds = [];
            foreach ($partyByMember as $row) {
                $key = EPVotes_Party_Logos::normalize_key($row->national_party);
                if (in_array($key, $downloadedKeys, true)) {
                    $matchedMemberIds[] = (int) $row->member_id;
                }
            }
            if (!empty($matchedMemberIds)) {
                $this->refresh_recent_posts_for_members($matchedMemberIds);
            }
        }

        return ['ok' => empty($errors), 'downloaded' => $downloaded, 'errors' => $errors];
    }

    /**
     * Rigenera il contenuto degli articoli (già pubblicati) delle votazioni
     * recenti (ultimi 30 giorni) in cui compare uno dei deputati indicati.
     * Usato sia dopo il recupero del partito nazionale sia dopo lo
     * scaricamento di nuovi loghi: in entrambi i casi un dato arrivato in
     * ritardo deve riflettersi negli articoli già pubblicati.
     *
     * @param int[] $memberIds
     */
    private function refresh_recent_posts_for_members(array $memberIds): void
    {
        global $wpdb;

        $member_votes_table = $wpdb->prefix . 'epvotes_member_votes';
        $votes_table = $wpdb->prefix . 'epvotes_votes';

        $placeholders = implode(',', array_fill(0, count($memberIds), '%d'));
        $voteIds = $wpdb->get_results($wpdb->prepare(
            "
            SELECT DISTINCT v.external_id
            FROM {$member_votes_table} mv
            INNER JOIN {$votes_table} v ON v.external_id = mv.vote_external_id
            WHERE mv.member_id IN ({$placeholders})
              AND v.vote_timestamp >= (NOW() - INTERVAL 30 DAY)
            ",
            $memberIds
        ));

        $importer = new EPVotes_Importer();
        foreach ($voteIds as $row) {
            $importer->rebuild_post_for_vote((int) $row->external_id);
        }
    }
}
