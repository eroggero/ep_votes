<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Client per l'API pubblica di HowTheyVote.eu (https://howtheyvote.eu/developers).
 *
 * Nota: è un'API sperimentale e non ufficiale (non è la Open Data API del
 * Parlamento europeo), gestita da un progetto indipendente. Endpoint reali
 * verificati nel codice sorgente del backend (votes_api.py):
 *
 *   GET /api/votes           -> elenco votazioni, paginato
 *                                {total, page, page_size, has_prev, has_next, results, facets}
 *   GET /api/votes/{id}      -> dettaglio di una votazione, incluso il voto
 *                                di ogni singolo deputato in "member_votes"
 *
 * Ogni metodo pubblico restituisce sempre un array con la chiave 'ok':
 *   ['ok' => true,  'data' => array(...)]
 *   ['ok' => false, 'error' => 'network_error'|'http_error'|'invalid_json'|'not_found', ...]
 *
 * Nessuna eccezione viene lasciata propagare al chiamante: gli errori di
 * rete sono normali quando si dipende da un servizio esterno e vanno
 * gestiti, non mostrati come fatal error di WordPress.
 */
class EPVotes_API
{
    private string $base_url = 'https://howtheyvote.eu';

    private int $timeout_seconds = 20;

    private int $max_retries = 2;

    /**
     * Esegue una richiesta GET e restituisce la risposta decodificata.
     *
     * @param array<string, scalar> $query
     * @return array{ok: bool, data?: array, error?: string, status?: int}
     */
    private function request(string $endpoint, array $query = []): array
    {
        $url = $this->base_url . $endpoint;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $attempt = 0;
        $response = null;

        while ($attempt <= $this->max_retries) {
            $attempt++;

            $response = wp_remote_get($url, [
                'timeout' => $this->timeout_seconds,
                'headers' => [
                    'Accept'     => 'application/json',
                    'User-Agent' => 'WordPress EPVotes Plugin/' . EPVOTES_VERSION,
                ],
            ]);

            if (!is_wp_error($response)) {
                break;
            }

            $this->log('warning', 'Tentativo di richiesta fallito', [
                'url'     => $url,
                'attempt' => $attempt,
                'message' => $response->get_error_message(),
            ]);

            if ($attempt > $this->max_retries) {
                return [
                    'ok'    => false,
                    'error' => 'network_error',
                ];
            }

            // Attesa progressiva tra i tentativi (backoff semplice), per non
            // martellare un servizio terzo che potrebbe essere temporaneamente
            // sovraccarico.
            usleep(300000 * $attempt);
        }

        /** @var array|WP_Error $response */
        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status === 404) {
            return ['ok' => false, 'error' => 'not_found', 'status' => $status];
        }

        if ($status < 200 || $status >= 300) {
            $this->log('error', 'Risposta HTTP non valida da HowTheyVote', [
                'url'    => $url,
                'status' => $status,
            ]);
            return ['ok' => false, 'error' => 'http_error', 'status' => $status];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $this->log('error', 'JSON non valido restituito da HowTheyVote', [
                'url'        => $url,
                'json_error' => json_last_error_msg(),
            ]);
            return ['ok' => false, 'error' => 'invalid_json'];
        }

        return ['ok' => true, 'data' => $data];
    }

    /**
     * Elenco votazioni (paginato), ordinate dalla più recente.
     *
     * @param array{page?: int, page_size?: int, date_from?: string, date_to?: string} $params
     */
    public function get_votes(array $params = []): array
    {
        $query = [
            'page'       => max(1, (int) ($params['page'] ?? 1)),
            // Limite lato client conservativo: l'API è sperimentale e senza
            // garanzie di disponibilità, meglio non chiedere pagine enormi.
            'page_size'  => max(1, min(50, (int) ($params['page_size'] ?? 20))),
            'sort_by'    => 'date',
            'sort_order' => 'desc',
        ];

        if (!empty($params['date_from'])) {
            $query['date[gte]'] = $params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $query['date[lte]'] = $params['date_to'];
        }

        return $this->request('/api/votes', $query);
    }

    /**
     * Dettaglio di una votazione, incluso il voto di ciascun deputato.
     */
    public function get_vote(int $id): array
    {
        return $this->request('/api/votes/' . $id);
    }

    /**
     * Log minimale su error_log(). Non registra mai il corpo completo delle
     * risposte (potenzialmente voluminoso), solo metadati utili al debug.
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        error_log(sprintf(
            '[EPVotes][%s] %s | %s',
            strtoupper($level),
            $message,
            wp_json_encode($context)
        ));
    }
}
