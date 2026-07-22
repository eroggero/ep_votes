<?php
/**
 * Plugin Name: EP Votes
 * EU Parliament Vote Reporter
 * ============================
 * Automatically imports voting data from HowTheyVote.eu and generates
 * detailed articles for each vote, complete with an interactive table
 * showing how every MEP voted.
 * 
 * Features:
 * - Fetches voting records directly from HowTheyVote.eu API
 * - Generates a dedicated article for each parliamentary vote
 * - Displays interactive, sortable tables with all MEP votes
 * - Includes political group breakdowns and vote summaries
 * 
 * Plugin URI: www.arums.org/eventi
 * Description: Importa automaticamente le votazioni del Parlamento Europeo da HowTheyVote.eu e crea un articolo per ciascuna, con tabella interattiva dei voti di ogni deputato.
 * Version: 0.6.0
 * Author: Elisabetta Roggero July 2026
 * License: GPL3
 * 
 */


if (!defined('ABSPATH')) {
    exit;
}


/*
|--------------------------------------------------------------------------
| Costanti plugin
|--------------------------------------------------------------------------
*/

define('EPVOTES_VERSION', '0.6.0');
define('EPVOTES_PLUGIN_FILE', __FILE__);
define('EPVOTES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EPVOTES_PLUGIN_URL', plugin_dir_url(__FILE__));


/*
|--------------------------------------------------------------------------
| Caricamento classi
|--------------------------------------------------------------------------
*/

require_once EPVOTES_PLUGIN_DIR . 'includes/class-epvotes-installer.php';
require_once EPVOTES_PLUGIN_DIR . 'includes/class-epvotes-api.php';
require_once EPVOTES_PLUGIN_DIR . 'includes/class-epvotes-db-helper.php';
require_once EPVOTES_PLUGIN_DIR . 'includes/class-epvotes-rebel-calculator.php';
require_once EPVOTES_PLUGIN_DIR . 'includes/class-epvotes-national-party-fetcher.php';
require_once EPVOTES_PLUGIN_DIR . 'includes/class-epvotes-official-summary-fetcher.php';
require_once EPVOTES_PLUGIN_DIR . 'includes/class-epvotes-mep-seed.php';
require_once EPVOTES_PLUGIN_DIR . 'includes/class-epvotes-party-logos.php';
require_once EPVOTES_PLUGIN_DIR . 'includes/class-epvotes-post-builder.php';
require_once EPVOTES_PLUGIN_DIR . 'includes/class-epvotes-importer.php';
require_once EPVOTES_PLUGIN_DIR . 'includes/class-epvotes-cron.php';
require_once EPVOTES_PLUGIN_DIR . 'includes/class-epvotes-admin.php';
require_once EPVOTES_PLUGIN_DIR . 'includes/class-epvotes-frontend.php';

/*
|--------------------------------------------------------------------------
| Classe principale
|--------------------------------------------------------------------------
*/

class EPVotes
{

    public function __construct()
    {

        register_activation_hook(
            EPVOTES_PLUGIN_FILE,
            [$this, 'activate']
        );


        register_deactivation_hook(
            EPVOTES_PLUGIN_FILE,
            [$this, 'deactivate']
        );


        add_action(
            'plugins_loaded',
            [$this, 'init']
        );

    }



    /**
     * Avvio plugin
     */
    public function init()
    {

        // Applica automaticamente gli aggiornamenti allo schema del database
        // anche quando il plugin viene aggiornato senza disattivarlo/riattivarlo
        // (l'hook di attivazione di WordPress scatta solo alla riattivazione
        // esplicita, non a ogni caricamento dei file).
        EPVotes_Installer::maybe_upgrade();

        // Stesso discorso per la pianificazione del cron: se per qualsiasi
        // motivo risulta "non pianificata" (es. i file sono stati sostituiti
        // senza disattivare/riattivare il plugin, o un altro plugin ha
        // ripulito i cron di WordPress), la ripristiniamo qui a ogni
        // caricamento. EPVotes_Cron::activate() verifica da sé se è già
        // pianificato prima di riprogrammare, quindi è sicuro chiamarlo a
        // ogni richiesta: non crea eventi duplicati.
        EPVotes_Cron::activate();

        new EPVotes_Cron();
        new EPVotes_Admin();
        new EPVotes_Frontend();

    }



    /**
     * Attivazione plugin: crea le tabelle e pianifica l'importazione
     * automatica (non serve più cliccare nulla manualmente).
     */
    public function activate()
	{
		EPVotes_Installer::install();
		EPVotes_Cron::activate();

		flush_rewrite_rules();
	}



    /**
     * Disattivazione plugin: ferma i cron pianificati (le tabelle e i dati
     * restano, per non perdere quanto già importato in caso di riattivazione).
     */
    public function deactivate()
    {

        EPVotes_Cron::deactivate();

        flush_rewrite_rules();

    }

}


/*
|--------------------------------------------------------------------------
| Avvio plugin
|--------------------------------------------------------------------------
*/

new EPVotes();
