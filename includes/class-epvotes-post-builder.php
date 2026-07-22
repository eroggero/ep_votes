<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Costruisce l'HTML del contenuto dell'articolo per una votazione.
 *
 * Scelta progettuale: niente <script> inline nel contenuto del post. Quando
 * wp_insert_post()/wp_update_post() viene eseguito da WP-Cron (nessun utente
 * loggato), WordPress filtra l'HTML con kses e rimuove i tag <script> e gli
 * attributi "on*" — è un comportamento di sicurezza normale di WordPress, non
 * un bug. La tabella viene quindi generata come puro HTML con attributi
 * data-* (nessun problema con kses), mentre l'interattività (ordinamento,
 * filtri, statistiche sul sottoinsieme filtrato) è realizzata da un unico
 * file JS condiviso, caricato solo sugli articoli di questa categoria
 * (vedi class-epvotes-frontend.php).
 *
 * Scelta progettuale su gruppo/partito: niente loghi ufficiali per i GRUPPI
 * politici europei — il logo del gruppo EPP, ad esempio, è esplicitamente
 * protetto ("cannot be used without our express authorisation" sul sito
 * ufficiale del gruppo) — quindi per i gruppi restano le etichette colorate
 * con sigla (mappa fissa, 9 gruppi). Per i PARTITI NAZIONALI usiamo invece
 * il logo reale quando disponibile in cache locale (vedi
 * EPVotes_Party_Logos e class-epvotes-cron.php, che lo scarica in
 * background da un catalogo open source), con la sigla auto-generata come
 * ripiego per i partiti senza logo noto o non ancora scaricato.
 */
final class EPVotes_Post_Builder
{
    private const RESULT_LABELS = [
        'ADOPTED'  => 'Adottata',
        'REJECTED' => 'Respinta',
    ];

    private const POSITION_LABELS = [
        'FOR'          => 'Favorevole',
        'AGAINST'      => 'Contrario',
        'ABSTENTION'   => 'Astenuto',
        'DID_NOT_VOTE' => 'Assente',
    ];

    /**
     * Sigla breve + colore pastello per ciascun gruppo politico europeo.
     * I codici corrispondono a quelli restituiti da HowTheyVote.eu.
     */
    private const GROUP_META = [
        'EPP'       => ['label' => 'EPP',    'color' => 'blue'],
        'SD'        => ['label' => 'S&D',    'color' => 'rose'],
        'RENEW'     => ['label' => 'Renew',  'color' => 'yellow'],
        'GREEN_EFA' => ['label' => 'Greens', 'color' => 'green'],
        'GUE_NGL'   => ['label' => 'Left',   'color' => 'terracotta'],
        'ECR'       => ['label' => 'ECR',    'color' => 'indigo'],
        'PFE'       => ['label' => 'PfE',    'color' => 'lavender'],
        'ESN'       => ['label' => 'ESN',    'color' => 'slate'],
        'NI'        => ['label' => 'NI',     'color' => 'gray'],
    ];

    /** Parole da ignorare nel derivare la sigla di un partito nazionale. */
    private const PARTY_ABBR_STOPWORDS = [
        'di', 'de', 'del', 'della', 'dello', 'dei', 'degli', 'delle', 'da', 'du', 'des',
        'the', 'of', 'and', 'für', 'and', 'y', 'und', 'la', 'le', 'lo', 'il', 'i', 'gli',
        'et', 'for', 'part', 'en', 'het', 'van', 'voor',
    ];

    /**
     * Costruisce il blocco "punti chiave", quando disponibili, con
     * l'attribuzione richiesta dal Legal Notice ufficiale del Parlamento
     * europeo (https://www.europarl.europa.eu/legal-notice/en): riproduzione
     * autorizzata "a condizione che l'intero elemento sia riprodotto e la
     * fonte sia riconosciuta". L'attribuzione è generata insieme al
     * contenuto e non è mai omissibile: se non c'è un summary_source_url
     * valido, semplicemente non mostriamo alcun punto chiave.
     */
    private static function build_official_summary(object $vote): string
    {
        if (empty($vote->official_summary_bullets) || empty($vote->summary_source_url)) {
            return '';
        }

        $bullets = json_decode($vote->official_summary_bullets, true);
        if (!is_array($bullets) || empty($bullets)) {
            return '';
        }

        $year = '';
        if (!empty($vote->vote_timestamp)) {
            $timestamp = strtotime($vote->vote_timestamp);
            if ($timestamp) {
                $year = date('Y', $timestamp);
            }
        }

        $html = '<div class="epvotes-official-summary">';
        $html .= '<h3>Punti principali</h3>';
        $html .= '<ul class="epvotes-official-summary-list">';
        foreach ($bullets as $bullet) {
            $html .= '<li>' . esc_html($bullet) . '</li>';
        }
        $html .= '</ul>';

        // Attribuzione obbligatoria: formato richiesto dal Legal Notice del
        // PE ("© European Union, [anno] – Source: European Parliament"),
        // con link alla pagina di provenienza.
        $html .= '<p class="epvotes-official-summary-attribution">'
            . '© European Union' . ($year !== '' ? ', ' . esc_html($year) : '') . ' – Source: '
            . '<a href="' . esc_url($vote->summary_source_url) . '" target="_blank" rel="noopener noreferrer">European Parliament</a>'
            . '</p>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Tipi di procedura legislativa dell'UE, in codici ufficiali (fonte:
     * nomenclatura pubblica dell'Osservatorio legislativo del PE). Sono
     * codici/categorie standard, non testo creativo di terzi.
     */
    private const PROCEDURE_TYPE_LABELS = [
        'COD' => 'legislativa ordinaria (codecisione)',
        'CNS' => 'di consultazione',
        'APP' => 'di approvazione',
        'INI' => 'd\'iniziativa',
        'INL' => 'd\'iniziativa legislativa',
        'RSP' => 'di risoluzione su tema d\'attualità',
        'BUD' => 'di bilancio',
        'DEC' => 'di decisione',
        'NLE' => 'legislativa non ordinaria',
        'REG' => 'di regolamento interno',
        'DEA' => 'relativa ad atti delegati',
        'IMM' => 'relativa a immunità parlamentare',
    ];

    /**
     * Costruisce una breve descrizione a partire da soli dati fattuali
     * (commissione competente, tipo di procedura): nessun testo creativo di
     * terzi, quindi nessun rischio di copyright. Meno ricca di una sintesi
     * giornalistica, ma sempre disponibile e generabile automaticamente per
     * ogni nuova votazione importata dal cron.
     */
    private static function build_factual_description(object $vote): string
    {
        $parts = [];

        if (!empty($vote->committee_label)) {
            $committee = $vote->committee_label;
            if (!empty($vote->committee_code)) {
                $committee .= ' (' . $vote->committee_code . ')';
            }
            $parts[] = 'di competenza della commissione ' . $committee;
        }

        if (!empty($vote->procedure_type) || !empty($vote->procedure_reference)) {
            $typeLabel = self::PROCEDURE_TYPE_LABELS[$vote->procedure_type ?? ''] ?? null;
            $procPart = 'nell\'ambito della procedura' . ($typeLabel ? ' ' . $typeLabel : '');
            if (!empty($vote->procedure_reference)) {
                $procPart .= ' ' . $vote->procedure_reference;
            }
            $parts[] = $procPart;
        }

        if (empty($parts)) {
            return '';
        }

        return 'Provvedimento ' . implode(', ', $parts) . '.';
    }

    /**
     * @param object   $vote        Riga da epvotes_votes
     * @param object[] $memberVotes Righe (join member_votes + members)
     */
    public static function build(object $vote, array $memberVotes): string
    {
        $html = '<div class="epvotes-post">';
        $html .= self::build_summary($vote);
        $html .= self::build_table($memberVotes);
        $html .= '</div>';

        return $html;
    }

    private static function build_summary(object $vote): string
    {
        $result_label = self::RESULT_LABELS[$vote->result] ?? ($vote->result ?: 'non disponibile');

        $date_display = '';
        if (!empty($vote->vote_timestamp)) {
            $timestamp = strtotime($vote->vote_timestamp);
            if ($timestamp) {
                $date_display = date_i18n('j F Y, H:i', $timestamp);
            }
        }

        $html = '<div class="epvotes-summary">';

        if (!empty($vote->reference)) {
            $html .= '<p class="epvotes-reference"><strong>Riferimento ufficiale:</strong> ' . esc_html($vote->reference) . '</p>';
        }

        if ($date_display !== '') {
            $html .= '<p class="epvotes-date"><strong>Data:</strong> ' . esc_html($date_display) . '</p>';
        }

        $html .= '<p class="epvotes-result"><strong>Esito:</strong> ' . esc_html($result_label) . '</p>';

        $officialSummaryHtml = self::build_official_summary($vote);
        if ($officialSummaryHtml !== '') {
            $html .= $officialSummaryHtml;
        } else {
            // Nessun punto chiave ufficiale disponibile per questa
            // votazione: mostriamo la descrizione fattuale generica come
            // ripiego (sempre disponibile, generata da soli metadati).
            $factual = self::build_factual_description($vote);
            if ($factual !== '') {
                $html .= '<p class="epvotes-factual-description">' . esc_html($factual) . '</p>';
            }
        }

        if (!empty($vote->summary_source_url)) {
            $source_labels = [
                'PRESS_RELEASE' => 'il comunicato stampa ufficiale del Parlamento europeo',
                'LEGISLATIVE_OBSERVATORY' => 'la scheda della procedura sull\'Osservatorio legislativo',
            ];
            $source_label = $source_labels[$vote->summary_source_type ?? ''] ?? 'la fonte ufficiale';
            $html .= '<p class="epvotes-summary-link">Per una sintesi completa di questo provvedimento, leggi '
                . '<a href="' . esc_url($vote->summary_source_url) . '" target="_blank" rel="noopener noreferrer">'
                . esc_html($source_label) . '</a>.</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * @param object[] $memberVotes
     */
    private static function build_table(array $memberVotes): string
    {
        if (empty($memberVotes)) {
            return '<p class="epvotes-empty">Il dettaglio nominale non è ancora disponibile per questa votazione.</p>';
        }

        $groups = [];
        $countries = [];
        // partyCode => ['label' => sigla, 'full' => nome completo, 'country' => codice Stato]
        $parties = [];

        foreach ($memberVotes as $mv) {
            if (!empty($mv->group_code_at_vote)) {
                $groups[$mv->group_code_at_vote] = true;
            }
            if (!empty($mv->country_code)) {
                $countries[$mv->country_code] = $mv->country_name ?: $mv->country_code;
            }
            if (!empty($mv->national_party)) {
                $key = self::party_key($mv->national_party);
                $parties[$key] = [
                    'label'   => self::abbreviate_party($mv->national_party),
                    'full'    => $mv->national_party,
                    'country' => $mv->country_code ?: '',
                    'logo'    => EPVotes_Party_Logos::local_url($mv->national_party),
                ];
            }
        }
        asort($countries);
        ksort($parties);

        // Statistiche complessive (usate dal JS come base per il pannello
        // dinamico, che le ricalcola sul sottoinsieme filtrato).
        $totalCounts = ['FOR' => 0, 'AGAINST' => 0, 'ABSTENTION' => 0, 'DID_NOT_VOTE' => 0];
        foreach ($memberVotes as $mv) {
            $pos = $mv->position ?: 'DID_NOT_VOTE';
            if (isset($totalCounts[$pos])) {
                $totalCounts[$pos]++;
            }
        }

        $html = '<div class="epvotes-table-wrap" data-epvotes-table>';

        $html .= self::build_filters($groups, $countries, $parties);

        $html .= self::build_stats_panel(count($memberVotes), $totalCounts);

        $html .= '<table class="epvotes-table">';
        $html .= '<colgroup>';
        $html .= '<col style="width:48px">';   // Voto (pallino)
        $html .= '<col style="width:26%">';    // Deputato (foto + nome)
        $html .= '<col style="width:14%">';    // Gruppo
        $html .= '<col style="width:16%">';    // Partito
        $html .= '<col style="width:70px">';   // Ribelle
        $html .= '<col style="width:16%">';    // Stato
        $html .= '</colgroup>';
        $html .= '<thead><tr>';
        $html .= '<th data-sort-key="position" data-sortable>Voto</th>';
        $html .= '<th data-sort-key="name" data-sortable>Deputato</th>';
        $html .= '<th data-sort-key="group" data-sortable>Gruppo</th>';
        $html .= '<th data-sort-key="party" data-sortable>Partito</th>';
        $html .= '<th data-sort-key="rebel" data-sortable>Ribelle</th>';
        $html .= '<th data-sort-key="country" data-sortable>Stato</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody data-epvotes-rows>';

        foreach ($memberVotes as $mv) {
            $html .= self::build_row($mv);
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Pannello statistiche: mostra di default i totali generali; il JS lo
     * ricalcola in tempo reale sul sottoinsieme visibile quando si applica
     * un filtro (vedi js/epvotes-table.js).
     */
    private static function build_stats_panel(int $total, array $counts): string
    {
        $html = '<div class="epvotes-stats-panel" data-epvotes-stats>';
        $html .= '<div class="epvotes-stats-total" data-stats-total>' . (int) $total . ' deputati</div>';
        $html .= '<div class="epvotes-stats-breakdown">';
        $html .= self::stat_pill('Favorevoli', $counts['FOR'], 'for');
        $html .= self::stat_pill('Contrari', $counts['AGAINST'], 'against');
        $html .= self::stat_pill('Astenuti', $counts['ABSTENTION'], 'abstention');
        $html .= self::stat_pill('Assenti', $counts['DID_NOT_VOTE'], 'absent');
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array{label: string, full: string, country: string, logo: string|null} $info
     */
    private static function build_party_option(string $key, array $info): string
    {
        $html = '<div class="epvotes-party-option" data-party-option'
            . ' data-value="' . esc_attr($key) . '"'
            . ' data-country="' . esc_attr($info['country']) . '"'
            . ' data-label="' . esc_attr($info['full']) . '">';

        if ($info['logo']) {
            $html .= '<img class="epvotes-party-logo-small" src="' . esc_url($info['logo']) . '" alt="">';
        } else {
            $html .= '<span class="epvotes-badge epvotes-badge--party">' . esc_html($info['label']) . '</span>';
        }

        $html .= '<span class="epvotes-party-option-name">' . esc_html($info['full']) . '</span>';
        $html .= '</div>';

        return $html;
    }

    private static function stat_pill(string $label, int $value, string $modifier): string
    {
        return sprintf(
            '<span class="epvotes-pill epvotes-pill--%s"><span class="epvotes-pill-value" data-stats-count="%s">%d</span> %s</span>',
            esc_attr($modifier),
            esc_attr($modifier),
            $value,
            esc_html($label)
        );
    }

    /**
     * @param array<string, bool>   $groups
     * @param array<string, string> $countries
     * @param array<string, array{label: string, full: string, country: string}> $parties
     */
    private static function build_filters(array $groups, array $countries, array $parties): string
    {
        $html = '<div class="epvotes-filters" data-epvotes-filters>';

        $html .= '<label>Nome<input type="text" data-filter-key="name" placeholder="Cerca per nome..."></label>';

        $html .= '<label>Gruppo politico<select data-filter-key="group"><option value="">Tutti</option>';
        foreach (self::GROUP_META as $code => $meta) {
            if (!isset($groups[$code])) {
                continue;
            }
            $html .= '<option value="' . esc_attr($code) . '">' . esc_html($meta['label']) . '</option>';
        }
        $html .= '</select></label>';

        // Il menu partito è personalizzato (non un <select> nativo) perché
        // un <option> nativo non può contenere un'immagine: nessun browser
        // la mostrerebbe. Vedi js/epvotes-table.js per l'interattività
        // (apertura, ricerca, cascata sul Paese).
        $html .= '<div class="epvotes-party-filter" data-party-filter>';
        $html .= '<label class="epvotes-party-filter-label">Partito nazionale</label>';
        $html .= '<button type="button" class="epvotes-party-trigger" data-party-trigger aria-haspopup="listbox">';
        $html .= '<span data-party-trigger-label>Tutti</span></button>';
        $html .= '<input type="hidden" data-filter-key="party" value="">';
        $html .= '<div class="epvotes-party-panel" data-party-panel hidden>';
        $html .= '<input type="text" class="epvotes-party-search" data-party-search placeholder="Cerca partito...">';
        $html .= '<div class="epvotes-party-options" data-party-options>';
        $html .= '<div class="epvotes-party-option" data-party-option data-value="" data-country="" data-label="Tutti">Tutti</div>';
        foreach ($parties as $key => $info) {
            $html .= self::build_party_option($key, $info);
        }
        $html .= '</div></div></div>';

        $html .= '<label>Voto<select data-filter-key="position"><option value="">Tutti</option>';
        foreach (self::POSITION_LABELS as $code => $label) {
            $html .= '<option value="' . esc_attr($code) . '">' . esc_html($label) . '</option>';
        }
        $html .= '</select></label>';

        $html .= '<label>Ribelle<select data-filter-key="rebel"><option value="">Tutti</option><option value="1">Solo ribelli</option><option value="0">Solo allineati</option></select></label>';

        $html .= '<label>Stato membro<select data-filter-key="country" data-country-select><option value="">Tutti</option>';
        foreach ($countries as $code => $label) {
            $html .= '<option value="' . esc_attr($code) . '">' . esc_html($label) . '</option>';
        }
        $html .= '</select></label>';

        $html .= '</div>';

        return $html;
    }

    private static function build_row(object $mv): string
    {
        $position = $mv->position ?: 'DID_NOT_VOTE';
        $position_label = self::POSITION_LABELS[$position] ?? $position;
        $dot_modifier = strtolower(str_replace('_', '-', $position));

        $rebel = $mv->is_rebel;
        $rebel_attr = $rebel === null ? '' : (string) (int) $rebel;

        $party = $mv->national_party ?: '';
        $partyKey = $party !== '' ? self::party_key($party) : '';

        $full_name = $mv->full_name ?: '';

        $groupCode = $mv->group_code_at_vote ?: '';
        $groupMeta = self::GROUP_META[$groupCode] ?? null;

        $html = '<tr'
            . ' data-name="' . esc_attr(mb_strtolower($full_name)) . '"'
            . ' data-group="' . esc_attr($groupCode) . '"'
            . ' data-party="' . esc_attr($partyKey) . '"'
            . ' data-position="' . esc_attr($position) . '"'
            . ' data-rebel="' . esc_attr($rebel_attr) . '"'
            . ' data-country="' . esc_attr($mv->country_code ?: '') . '"'
            . '>';

        $html .= '<td class="epvotes-cell-vote"><span class="epvotes-dot epvotes-dot--' . esc_attr($dot_modifier) . '" title="' . esc_attr($position_label) . '"></span></td>';

        $html .= '<td class="epvotes-cell-member">';
        if (!empty($mv->thumb_url)) {
            $photo_url = str_starts_with($mv->thumb_url, 'http') ? $mv->thumb_url : 'https://howtheyvote.eu' . $mv->thumb_url;
            $html .= '<img class="epvotes-photo" src="' . esc_url($photo_url) . '" alt="" loading="lazy" width="40" height="40">';
        }
        if (!empty($mv->member_id)) {
            $profile_url = 'https://www.europarl.europa.eu/meps/en/' . (int) $mv->member_id;
            $html .= '<a class="epvotes-name" href="' . esc_url($profile_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($full_name) . '</a>';
        } else {
            $html .= '<span class="epvotes-name">' . esc_html($full_name) . '</span>';
        }
        $html .= '</td>';

        $html .= '<td>';
        if ($groupMeta) {
            $html .= '<span class="epvotes-badge epvotes-badge--' . esc_attr($groupMeta['color']) . '" title="' . esc_attr($mv->group_name_at_vote ?: '') . '">' . esc_html($groupMeta['label']) . '</span>';
        } elseif ($groupCode !== '') {
            $html .= '<span class="epvotes-badge epvotes-badge--gray" title="' . esc_attr($mv->group_name_at_vote ?: '') . '">' . esc_html($groupCode) . '</span>';
        }
        $html .= '</td>';

        $html .= '<td>';
        if ($party !== '') {
            $logoUrl = EPVotes_Party_Logos::local_url($party);
            if ($logoUrl) {
                $html .= '<img class="epvotes-party-logo" src="' . esc_url($logoUrl) . '" alt="" title="' . esc_attr($party) . '">';
            } else {
                $html .= '<span class="epvotes-badge epvotes-badge--party" title="' . esc_attr($party) . '">' . esc_html(self::abbreviate_party($party)) . '</span>';
            }
        }
        $html .= '</td>';

        $html .= '<td class="epvotes-cell-rebel">';
        if ($rebel === true) {
            $html .= '<span class="epvotes-dot epvotes-dot--rebel" title="Voto difforme dalla maggioranza del proprio gruppo"></span>';
        }
        $html .= '</td>';

        $html .= '<td>' . esc_html($mv->country_name ?: '') . '</td>';

        $html .= '</tr>';

        return $html;
    }

    /**
     * Chiave stabile e "safe" per un nome di partito (usata come value delle
     * option e come attributo data-party, per un confronto esatto invece di
     * un "contiene" testuale).
     */
    private static function party_key(string $partyName): string
    {
        $key = mb_strtolower(trim($partyName));
        $key = preg_replace('/[^a-z0-9]+/u', '-', $key);
        return trim((string) $key, '-');
    }

    /**
     * Deriva una sigla breve (best-effort, non ufficiale) dal nome di un
     * partito nazionale, prendendo l'iniziale delle parole significative.
     * Esempio: "Movimento 5 Stelle" -> "M5S".
     *
     * Non è garantita corrispondere alla sigla realmente usata dal partito
     * (specialmente per nomi con apostrofi/contrazioni): per questo il nome
     * completo resta sempre visibile nell'attributo title.
     */
    private static function abbreviate_party(string $partyName): string
    {
        $words = preg_split('/\s+/u', trim($partyName)) ?: [];
        $initials = '';

        foreach ($words as $word) {
            // Contrazioni tipo "d'Italia", "l'Uomo Qualunque": le sigle reali
            // di solito mantengono la lettera della contrazione in minuscolo
            // seguita dall'iniziale della parola successiva (es. "FdI" per
            // "Fratelli d'Italia", non "FI" che è la sigla di un altro
            // partito). Le gestiamo come caso speciale invece di ignorarle.
            if (preg_match("/^([a-zA-Zàèéìòù]+)'(.+)$/u", $word, $m) === 1) {
                $initials .= mb_strtolower(mb_substr($m[1], 0, 1)) . mb_strtoupper(mb_substr($m[2], 0, 1));
                if (mb_strlen($initials) >= 5) {
                    break;
                }
                continue;
            }

            $lower = mb_strtolower($word);
            if (in_array($lower, self::PARTY_ABBR_STOPWORDS, true)) {
                continue;
            }

            $initials .= mb_strtoupper(mb_substr($word, 0, 1));

            if (mb_strlen($initials) >= 5) {
                break;
            }
        }

        if (mb_strlen($initials) < 2) {
            // Nome troppo corto o composto solo da parole ignorate: usiamo le
            // prime lettere del nome originale come ripiego.
            return mb_strtoupper(mb_substr(preg_replace('/\s+/u', '', $partyName), 0, 4));
        }

        return $initials;
    }
}

