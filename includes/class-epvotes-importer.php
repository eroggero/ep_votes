<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Importa le votazioni da HowTheyVote.eu e crea un articolo WordPress per
 * ciascuna. Pensato per essere richiamato da WP-Cron a piccoli lotti (vedi
 * class-epvotes-cron.php), non da un singolo click che elabora tutto in una
 * sola richiesta HTTP: è proprio quest'ultimo pattern che causava il
 * timeout e l'importazione parziale osservata in produzione.
 */
class EPVotes_Importer
{
    private EPVotes_API $api;

    public function __construct(?EPVotes_API $api = null)
    {
        $this->api = $api ?? new EPVotes_API();
    }

    /**
     * Importa al massimo $max_votes votazioni NUOVE (non già presenti nel
     * database), partendo dalle più recenti. Pensato per essere chiamato
     * spesso (ogni pochi minuti) con un numero piccolo: se ci sono più
     * votazioni nuove di quante ne processiamo in un colpo, le restanti
     * verranno prese alla chiamata successiva.
     *
     * @return array{ok: bool, imported: int[], errors: string[]}
     */
    /**
     * Importa al massimo $max_votes votazioni NUOVE (non già presenti nel
     * database), partendo dalle più recenti e proseguendo cronologicamente
     * più indietro finché non ne trova abbastanza (o finché l'API non ha
     * più risultati). Pensato per essere chiamato spesso (ogni pochi minuti)
     * con un numero piccolo dal cron: se ci sono più votazioni nuove di
     * quante ne processiamo in un colpo, le restanti verranno prese alla
     * chiamata successiva. Se invece viene richiesto un numero grande
     * (importazione manuale), scandisce più pagine finché non lo soddisfa.
     *
     * @return array{ok: bool, imported: int[], errors: string[]}
     */
    public function import_latest(int $max_votes = 2, int $page_size = 50, int $max_pages = 60): array
    {
        global $wpdb;

        $errors = [];
        $imported_vote_ids = [];
        $page_size = max(1, min(50, $page_size)); // 50 è il massimo pratico dell'API

        for ($page = 1; $page <= $max_pages; $page++) {
            if (count($imported_vote_ids) >= $max_votes) {
                break;
            }

            $list = $this->api->get_votes([
                'page'      => $page,
                'page_size' => $page_size,
            ]);

            if (!$list['ok']) {
                $errors[] = "Pagina {$page}: impossibile recuperare l'elenco votazioni ({$list['error']})";
                break;
            }

            $results = $list['data']['results'] ?? [];

            if (empty($results)) {
                break; // non ci sono più votazioni da recuperare
            }

            foreach ($results as $summary) {
                if (count($imported_vote_ids) >= $max_votes) {
                    break;
                }

                $voteId = (int) ($summary['id'] ?? 0);
                if ($voteId <= 0) {
                    continue;
                }

                if ($this->vote_already_imported($wpdb, $voteId)) {
                    // Non ci fermiamo qui: continuiamo a scandire (anche
                    // pagine successive) per andare a cercare le votazioni
                    // precedenti non ancora importate, invece di considerare
                    // "già fatto tutto" solo perché le più recenti lo sono già.
                    continue;
                }

                $detail = $this->api->get_vote($voteId);
                if (!$detail['ok']) {
                    $errors[] = "Votazione {$voteId}: {$detail['error']}";
                    continue;
                }

                try {
                    $this->import_single_vote($wpdb, $detail['data']);
                    $imported_vote_ids[] = $voteId;
                } catch (Throwable $exception) {
                    $errors[] = "Votazione {$voteId}: errore durante il salvataggio ({$exception->getMessage()})";
                }
            }

            if (empty($list['data']['has_next'])) {
                break; // l'API conferma che non ci sono altre pagine
            }
        }

        return [
            'ok'       => empty($errors) || !empty($imported_vote_ids),
            'imported' => $imported_vote_ids,
            'errors'   => $errors,
        ];
    }

    private function vote_already_imported(object $wpdb, int $voteId): bool
    {
        $table = $wpdb->prefix . 'epvotes_votes';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE external_id = %d",
            $voteId
        ));

        return $existing !== null;
    }

    /**
     * Importa una singola votazione già scaricata (dettaglio completo) e
     * crea l'articolo WordPress corrispondente.
     *
     * @param array<string, mixed> $v
     */
    private function import_single_vote(object $wpdb, array $v): void
    {
        $votes_table = $wpdb->prefix . 'epvotes_votes';
        $members_table = $wpdb->prefix . 'epvotes_members';
        $member_votes_table = $wpdb->prefix . 'epvotes_member_votes';

        $voteId = (int) $v['id'];
        $stats = $v['stats']['total'] ?? [];
        $memberVotesRaw = is_array($v['member_votes'] ?? null) ? $v['member_votes'] : [];

        // Argomenti (es. "Economy and budget"): sono un'etichetta di
        // classificazione, non testo creativo, quindi non pone i problemi di
        // copyright del riassunto vero e proprio (che non riproduciamo mai,
        // vedi snippet_source_url più sotto).
        $topics = array_map(
            static fn (array $t): string => $t['label'] ?? '',
            is_array($v['topics'] ?? null) ? $v['topics'] : []
        );
        $topics = array_values(array_filter($topics));

        $snippet = $v['snippet'] ?? null;
        $summarySourceUrl = is_array($snippet) ? ($snippet['source_url'] ?? null) : null;
        $summarySourceType = is_array($snippet) ? ($snippet['source_type'] ?? null) : null;

        // Dati puramente fattuali (commissione competente, tipo/riferimento
        // di procedura): usati per generare una breve descrizione senza
        // riprodurre alcun testo protetto da copyright (vedi
        // EPVotes_Post_Builder::build_factual_description()).
        $committees = is_array($v['responsible_committees'] ?? null) ? $v['responsible_committees'] : [];
        $committeeLabel = $committees[0]['label'] ?? null;
        $committeeCode = $committees[0]['code'] ?? ($committees[0]['abbreviation'] ?? null);

        $procedure = is_array($v['procedure'] ?? null) ? $v['procedure'] : [];
        $procedureType = $procedure['type'] ?? null;
        $procedureReference = $procedure['reference'] ?? null;

        // Punti chiave dal comunicato stampa ufficiale del PE (non da
        // howtheyvote.eu): riutilizzo autorizzato dal Legal Notice ufficiale
        // del PE a condizione di riprodurre l'intero elemento e citare la
        // fonte (l'attribuzione viene aggiunta sempre da
        // EPVotes_Post_Builder, non è mai omissibile). Se il fetch fallisce
        // o il link non è di europarl.europa.eu, semplicemente non avremo
        // punti chiave per questa votazione: non blocchiamo mai l'import.
        $officialBullets = [];
        if (!empty($summarySourceUrl)) {
            $summaryFetch = (new EPVotes_Official_Summary_Fetcher())->fetch($summarySourceUrl);
            if ($summaryFetch['ok']) {
                $officialBullets = $summaryFetch['bullets'];
            }
        }

        // --- 1. Calcolo "ribelle" (in memoria, sui dati appena scaricati) ---
        $forRebelCalc = array_map(static function (array $mv): array {
            return [
                'group_code' => $mv['member']['group']['code'] ?? '',
                'position'   => $mv['position'] ?? '',
            ];
        }, $memberVotesRaw);
        $rebelFlags = EPVotes_Rebel_Calculator::compute($forRebelCalc);

        // --- 2. Upsert della votazione (una riga) ---
        $wpdb->replace($votes_table, [
            'external_id'          => $voteId,
            'title'                => $v['display_title'] ?? '',
            'description'          => $v['description'] ?? '',
            'reference'            => $v['reference'] ?? '',
            'vote_timestamp'       => $this->to_mysql_datetime($v['timestamp'] ?? null),
            'result'               => $v['result'] ?? '',
            'count_for'            => (int) ($stats['FOR'] ?? 0),
            'count_against'        => (int) ($stats['AGAINST'] ?? 0),
            'count_abstention'     => (int) ($stats['ABSTENTION'] ?? 0),
            'count_did_not_vote'   => (int) ($stats['DID_NOT_VOTE'] ?? 0),
            'topics'               => implode('|', $topics),
            'summary_source_url'   => $summarySourceUrl,
            'summary_source_type'  => $summarySourceType,
            'committee_label'      => $committeeLabel,
            'committee_code'       => $committeeCode,
            'procedure_type'       => $procedureType,
            'procedure_reference'  => $procedureReference,
            'official_summary_bullets' => !empty($officialBullets) ? wp_json_encode($officialBullets) : null,
            'raw_json'             => wp_json_encode($v),
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

        // --- 3. Upsert in batch di deputati e voti individuali ---
        $memberRows = [];
        $memberVoteRows = [];

        foreach ($memberVotesRaw as $index => $mv) {
            $m = $mv['member'] ?? null;
            if (empty($m['id'])) {
                continue;
            }

            $group = $m['group'] ?? null;
            $country = $m['country'] ?? [];
            $memberId = (int) $m['id'];

            $memberRows[$memberId] = [
                'member_id'    => $memberId,
                'first_name'   => $m['first_name'] ?? '',
                'last_name'    => $m['last_name'] ?? '',
                'full_name'    => $m['full_name'] ?? '',
                'country_code' => $country['code'] ?? '',
                'country_name' => $country['label'] ?? '',
                'group_code'   => $group['code'] ?? '',
                'group_name'   => $group['label'] ?? '',
                'photo_url'    => $m['photo_url'] ?? '',
                'thumb_url'    => $m['thumb_url'] ?? '',
            ];

            $rebel = $rebelFlags[$index] ?? null;

            $memberVoteRows[] = [
                'vote_external_id'     => $voteId,
                'member_id'            => $memberId,
                'position'             => $mv['position'] ?? '',
                'is_rebel'             => $rebel === null ? null : ($rebel ? 1 : 0),
                'group_code_at_vote'   => $group['code'] ?? '',
                'group_name_at_vote'   => $group['label'] ?? '',
                'country_code_at_vote' => $country['code'] ?? '',
            ];
        }

        if (!empty($memberRows)) {
            EPVotes_DB_Helper::batch_upsert(
                $wpdb,
                $members_table,
                ['member_id', 'first_name', 'last_name', 'full_name', 'country_code', 'country_name', 'group_code', 'group_name', 'photo_url', 'thumb_url'],
                array_values($memberRows),
                // Non sovrascriviamo national_party/national_party_fetched_at qui:
                // sono gestiti dal blocco seguente (seed) o dal recupero in
                // background (vedi class-epvotes-cron.php).
                ['first_name', 'last_name', 'full_name', 'country_code', 'country_name', 'group_code', 'group_name', 'photo_url', 'thumb_url']
            );

            // Applica subito il partito nazionale noto dall'istantanea fornita
            // (data/meps-seed.php), invece di aspettare il recupero
            // progressivo in background: molto più rapido per i deputati
            // già presenti in quell'elenco. IMPORTANTE: questo blocco deve
            // restare DOPO l'upsert principale qui sopra, così la riga del
            // deputato esiste già e questo aggiorna soltanto due colonne
            // invece di rischiare un insert parziale.
            $seed = EPVotes_Mep_Seed::data();
            $seedRows = [];
            foreach (array_keys($memberRows) as $memberId) {
                if (!isset($seed[$memberId]) || $seed[$memberId]['national_party'] === '') {
                    continue;
                }
                $seedRows[] = [
                    'member_id'                 => $memberId,
                    'national_party'            => $seed[$memberId]['national_party'],
                    'national_party_fetched_at' => current_time('mysql'),
                ];
            }
            if (!empty($seedRows)) {
                EPVotes_DB_Helper::batch_upsert(
                    $wpdb,
                    $members_table,
                    ['member_id', 'national_party', 'national_party_fetched_at'],
                    $seedRows,
                    ['national_party', 'national_party_fetched_at']
                );
            }
        }

        if (!empty($memberVoteRows)) {
            EPVotes_DB_Helper::batch_upsert(
                $wpdb,
                $member_votes_table,
                ['vote_external_id', 'member_id', 'position', 'is_rebel', 'group_code_at_vote', 'group_name_at_vote', 'country_code_at_vote'],
                $memberVoteRows,
                ['position', 'is_rebel', 'group_code_at_vote', 'group_name_at_vote', 'country_code_at_vote']
            );
        }

        // --- 4. Creazione dell'articolo WordPress ---
        $this->create_post_for_vote($wpdb, $voteId);
    }

    /**
     * Rigenera l'articolo di una votazione già importata (stessi dati,
     * ricalcolati dalle tabelle). Usato anche quando il partito nazionale di
     * un deputato arriva DOPO che l'articolo è già stato pubblicato (il
     * contenuto è HTML statico, non si aggiorna da solo): vedi
     * class-epvotes-cron.php, che richiama questo metodo per le votazioni
     * recenti dopo ogni recupero di partiti nazionali.
     */
    public function rebuild_post_for_vote(int $voteId): void
    {
        global $wpdb;
        $this->create_post_for_vote($wpdb, $voteId);
    }

    private function create_post_for_vote(object $wpdb, int $voteId): void
    {
        $votes_table = $wpdb->prefix . 'epvotes_votes';
        $members_table = $wpdb->prefix . 'epvotes_members';
        $member_votes_table = $wpdb->prefix . 'epvotes_member_votes';

        $vote = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$votes_table} WHERE external_id = %d",
            $voteId
        ));

        if (!$vote) {
            return;
        }

        $memberVotes = $wpdb->get_results($wpdb->prepare(
            "
            SELECT mv.position, mv.is_rebel, mv.group_code_at_vote, mv.group_name_at_vote,
                   m.member_id, m.full_name, m.country_code, m.country_name, m.national_party, m.thumb_url
            FROM {$member_votes_table} mv
            INNER JOIN {$members_table} m ON m.member_id = mv.member_id
            WHERE mv.vote_external_id = %d
            ORDER BY m.last_name ASC, m.full_name ASC
            ",
            $voteId
        ));

        $content = EPVotes_Post_Builder::build($vote, $memberVotes ?: []);

        $category_id = $this->get_or_create_category();

        $tags = !empty($vote->topics) ? array_filter(explode('|', $vote->topics)) : [];

        $post_data = [
            'post_title'   => $vote->title ?: sprintf('Votazione %d', $voteId),
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'post',
            'tags_input'   => $tags,
            'post_date'    => $vote->vote_timestamp ?: current_time('mysql'),
            'post_category' => $category_id ? [$category_id] : [],
        ];

        // Il post_id nella nostra tabella è la via rapida per trovare
        // l'articolo già esistente; il post meta "_epvotes_vote_id" è un
        // secondo aggancio indipendente dalle nostre tabelle personalizzate
        // (che un futuro aggiornamento di schema potrebbe ricreare da zero),
        // così non rischiamo di creare un articolo duplicato per la stessa
        // votazione anche in quel caso.
        $existing_post_id = (int) ($vote->post_id ?? 0);

        if (!$existing_post_id) {
            $existing_post_id = $this->find_post_by_vote_id($voteId);
        }

        if ($existing_post_id) {
            $post_data['ID'] = $existing_post_id;
            wp_update_post($post_data);
            $post_id = $existing_post_id;
        } else {
            $post_id = wp_insert_post($post_data, true);
        }

        if (!is_wp_error($post_id) && $post_id) {
            update_post_meta($post_id, '_epvotes_vote_id', $voteId);

            $wpdb->update(
                $votes_table,
                ['post_id' => $post_id],
                ['external_id' => $voteId],
                ['%d'],
                ['%d']
            );
        }
    }

    /**
     * Cerca un articolo già creato per questa votazione tramite post meta,
     * indipendentemente dal contenuto della nostra tabella epvotes_votes.
     */
    private function find_post_by_vote_id(int $voteId): int
    {
        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => 'any',
            'meta_key'       => '_epvotes_vote_id',
            'meta_value'     => $voteId,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        return !empty($posts) ? (int) $posts[0] : 0;
    }

    private function get_or_create_category(): int
    {
        $existing = get_category_by_slug('votazioni-parlamento-europeo');
        if ($existing) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term('Votazioni Parlamento europeo', 'category', [
            'slug' => 'votazioni-parlamento-europeo',
        ]);

        if (is_wp_error($created)) {
            return 0;
        }

        return (int) ($created['term_id'] ?? 0);
    }

    private function to_mysql_datetime(?string $isoTimestamp): ?string
    {
        if (empty($isoTimestamp)) {
            return null;
        }

        try {
            $date = new DateTimeImmutable($isoTimestamp);
            return $date->format('Y-m-d H:i:s');
        } catch (Exception $exception) {
            return null;
        }
    }
}
