<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Installazione e aggiornamento dello schema del database.
 *
 * v3 aggiunge:
 *  - epvotes_votes.post_id: collega ogni votazione all'articolo WordPress
 *    creato automaticamente per essa (evita di ricrearlo due volte).
 *  - epvotes_members.national_party / national_party_fetched_at: cache del
 *    partito nazionale, recuperato in background dal sito ufficiale del PE
 *    (HowTheyVote non fornisce questo dato).
 *  - epvotes_member_votes.is_rebel: 1 se il deputato ha votato diversamente
 *    dalla maggioranza del proprio gruppo in quella votazione, calcolato da
 *    noi al momento dell'importazione (vedi class-epvotes-importer.php).
 */
class EPVotes_Installer
{
    private const SCHEMA_VERSION = 6;

    public static function maybe_upgrade(): void
    {
        if ((int) get_option('epvotes_db_version', 0) === self::SCHEMA_VERSION) {
            return;
        }

        self::install();
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $installed_version = (int) get_option('epvotes_db_version', 0);

        if ($installed_version > 0 && $installed_version < self::SCHEMA_VERSION) {
            self::drop_tables();
        }

        $charset = $wpdb->get_charset_collate();

        $votes = $wpdb->prefix . 'epvotes_votes';
        $members = $wpdb->prefix . 'epvotes_members';
        $member_votes = $wpdb->prefix . 'epvotes_member_votes';

        $sql = [];

        $sql[] = "
        CREATE TABLE {$votes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            external_id BIGINT UNSIGNED NOT NULL,
            title TEXT,
            description TEXT,
            reference VARCHAR(100),
            vote_timestamp DATETIME NULL,
            result VARCHAR(30),
            count_for INT UNSIGNED DEFAULT 0,
            count_against INT UNSIGNED DEFAULT 0,
            count_abstention INT UNSIGNED DEFAULT 0,
            count_did_not_vote INT UNSIGNED DEFAULT 0,
            topics VARCHAR(500) NULL,
            summary_source_url VARCHAR(500) NULL,
            summary_source_type VARCHAR(50) NULL,
            committee_label VARCHAR(255) NULL,
            committee_code VARCHAR(20) NULL,
            procedure_type VARCHAR(20) NULL,
            procedure_reference VARCHAR(50) NULL,
            official_summary_bullets TEXT NULL,
            post_id BIGINT UNSIGNED NULL,
            raw_json LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY external_id (external_id),
            KEY vote_timestamp (vote_timestamp),
            KEY post_id (post_id)
        ) {$charset};
        ";

        $sql[] = "
        CREATE TABLE {$members} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id BIGINT UNSIGNED NOT NULL,
            first_name VARCHAR(150),
            last_name VARCHAR(150),
            full_name VARCHAR(255),
            country_code VARCHAR(8),
            country_name VARCHAR(100),
            group_code VARCHAR(30),
            group_name VARCHAR(255),
            national_party VARCHAR(255) NULL,
            national_party_fetched_at DATETIME NULL,
            photo_url TEXT,
            thumb_url TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY member_id (member_id),
            KEY country_code (country_code),
            KEY group_code (group_code),
            KEY national_party_fetched_at (national_party_fetched_at)
        ) {$charset};
        ";

        $sql[] = "
        CREATE TABLE {$member_votes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vote_external_id BIGINT UNSIGNED NOT NULL,
            member_id BIGINT UNSIGNED NOT NULL,
            position VARCHAR(30),
            is_rebel TINYINT(1) NULL,
            group_code_at_vote VARCHAR(30),
            group_name_at_vote VARCHAR(255),
            country_code_at_vote VARCHAR(8),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY vote_member (vote_external_id, member_id),
            KEY vote_external_id (vote_external_id),
            KEY member_id (member_id),
            KEY position (position)
        ) {$charset};
        ";

        foreach ($sql as $query) {
            dbDelta($query);
        }

        update_option('epvotes_db_version', self::SCHEMA_VERSION);
    }

    private static function drop_tables(): void
    {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'epvotes_member_votes',
            $wpdb->prefix . 'epvotes_members',
            $wpdb->prefix . 'epvotes_votes',
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
    }
}
