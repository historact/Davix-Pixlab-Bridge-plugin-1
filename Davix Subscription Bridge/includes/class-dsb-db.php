<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_DB {
    const OPTION_DELETE_ON_UNINSTALL = 'dsb_delete_on_uninstall';
    const OPTION_DB_VERSION          = 'dsb_db_version';
    const DB_VERSION                 = '1.8.1';
    const OPTION_TRIGGERS_STATUS     = 'dsb_triggers_status';

    /** @var \wpdb */
    protected $wpdb;
    /** @var string */
    protected $table_logs;
    /** @var string */
    protected $table_keys;
    /** @var string */
    protected $table_user;
    /** @var string */
    protected $table_purge_queue;
    /** @var string */
    protected $table_provision_queue;

    public function __construct( \wpdb $wpdb ) {
        $this->wpdb       = $wpdb;
        $this->table_logs = $wpdb->prefix . 'davix_bridge_logs';
        $this->table_keys = $wpdb->prefix . 'davix_bridge_keys';
        $this->table_user = $wpdb->prefix . 'davix_bridge_user';
        $this->table_purge_queue = $wpdb->prefix . 'davix_bridge_purge_queue';
        $this->table_provision_queue = $wpdb->prefix . 'davix_bridge_provision_queue';
    }

    public function create_tables(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $this->wpdb->get_charset_collate();

        $sql_logs = "CREATE TABLE {$this->table_logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event varchar(80) NOT NULL,
            customer_email varchar(190) DEFAULT NULL,
            plan_slug varchar(190) DEFAULT NULL,
            subscription_id varchar(190) DEFAULT NULL,
            order_id varchar(190) DEFAULT NULL,
            response_action varchar(80) DEFAULT NULL,
            http_code smallint DEFAULT NULL,
            error_excerpt text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY subscription_id (subscription_id)
        ) $charset_collate;";

        $sql_purge_queue = "CREATE TABLE {$this->table_purge_queue} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED DEFAULT NULL,
            customer_email varchar(190) DEFAULT NULL,
            subscription_id varchar(64) DEFAULT NULL,
            api_key_id BIGINT UNSIGNED DEFAULT NULL,
            reason varchar(32) NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            claim_token varchar(64) DEFAULT NULL,
            locked_until datetime DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            finished_at datetime DEFAULT NULL,
            next_run_at datetime DEFAULT NULL,
            last_error text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY idx_status_locked_until (status, locked_until),
            KEY wp_user_id (wp_user_id),
            KEY customer_email (customer_email),
            KEY subscription_id (subscription_id),
            KEY api_key_id (api_key_id),
            KEY idx_claim_token (claim_token)
        ) $charset_collate;";

        $sql_provision_queue = "CREATE TABLE {$this->table_provision_queue} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id varchar(190) NOT NULL,
            payload longtext NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            next_run_at datetime DEFAULT NULL,
            locked_until datetime DEFAULT NULL,
            claim_token varchar(64) DEFAULT NULL,
            last_error text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            KEY idx_status_next_run (status, next_run_at),
            KEY idx_locked_until (locked_until),
            KEY idx_claim_token (claim_token)
        ) $charset_collate;";

        $sql_keys = "CREATE TABLE {$this->table_keys} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscription_id varchar(190) NOT NULL,
            customer_email varchar(190) NOT NULL,
            wp_user_id BIGINT UNSIGNED DEFAULT NULL,
            customer_name varchar(255) DEFAULT NULL,
            subscription_status varchar(50) DEFAULT NULL,
            plan_slug varchar(190) NOT NULL,
            status varchar(60) NOT NULL,
            key_prefix varchar(20) DEFAULT NULL,
            key_last4 varchar(10) DEFAULT NULL,
            valid_from datetime DEFAULT NULL,
            valid_until datetime DEFAULT NULL,
            node_plan_id varchar(80) DEFAULT NULL,
            node_api_key_id BIGINT UNSIGNED DEFAULT NULL,
            last_action varchar(60) DEFAULT NULL,
            last_http_code smallint DEFAULT NULL,
            last_error text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_wp_user_subscription (wp_user_id, subscription_id),
            KEY wp_user_id (wp_user_id),
            KEY subscription_id (subscription_id),
            UNIQUE KEY node_api_key_id (node_api_key_id),
            KEY customer_email (customer_email)
        ) $charset_collate;";

        $sql_user = "CREATE TABLE {$this->table_user} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL,
            customer_email VARCHAR(190) DEFAULT NULL,
            subscription_id VARCHAR(191) DEFAULT NULL,
            order_id BIGINT UNSIGNED DEFAULT NULL,
            product_id BIGINT UNSIGNED DEFAULT NULL,
            plan_slug VARCHAR(190) DEFAULT NULL,
            status VARCHAR(50) DEFAULT NULL,
            valid_from DATETIME NULL,
            valid_until DATETIME NULL,
            node_api_key_id BIGINT UNSIGNED DEFAULT NULL,
            source VARCHAR(50) DEFAULT 'wps_rest',
            last_sync_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_wp_user_subscription (wp_user_id, subscription_id),
            KEY idx_email (customer_email),
            KEY idx_sub (subscription_id),
            KEY idx_status (status),
            KEY idx_plan (plan_slug),
            KEY idx_node_api_key_id (node_api_key_id)
        ) $charset_collate;";

        dsb_log( 'debug', 'Running dbDelta for davix_bridge_logs', [ 'sql' => $sql_logs ] );
        dbDelta( $sql_logs );
        dsb_log( 'debug', 'dbDelta result for davix_bridge_logs', [ 'last_error' => $this->wpdb->last_error ] );

        dsb_log( 'debug', 'Running dbDelta for davix_bridge_purge_queue', [ 'sql' => $sql_purge_queue ] );
        dbDelta( $sql_purge_queue );
        dsb_log( 'debug', 'dbDelta result for davix_bridge_purge_queue', [ 'last_error' => $this->wpdb->last_error ] );

        dsb_log( 'debug', 'Running dbDelta for davix_bridge_provision_queue', [ 'sql' => $sql_provision_queue ] );
        dbDelta( $sql_provision_queue );
        dsb_log( 'debug', 'dbDelta result for davix_bridge_provision_queue', [ 'last_error' => $this->wpdb->last_error ] );

        dsb_log( 'debug', 'Running dbDelta for davix_bridge_keys', [ 'sql' => $sql_keys ] );
        dbDelta( $sql_keys );
        dsb_log( 'debug', 'dbDelta result for davix_bridge_keys', [ 'last_error' => $this->wpdb->last_error ] );

        dsb_log( 'debug', 'Running dbDelta for davix_bridge_user', [ 'sql' => $sql_user ] );
        dbDelta( $sql_user );
        dsb_log( 'debug', 'dbDelta result for davix_bridge_user', [ 'last_error' => $this->wpdb->last_error ] );

        $this->maybe_create_triggers();
    }

    /**
     * Run plugin database migrations (create/update tables once).
     */
    public function migrate(): void {
        $stored_version = get_option( self::OPTION_DB_VERSION );

        if ( self::DB_VERSION !== $stored_version ) {
            $this->create_tables();

            if ( version_compare( (string) $stored_version, '1.4.0', '<' ) ) {
                require_once DSB_PLUGIN_DIR . 'includes/migrations/upgrade-1.4.0.php';
                DSB_Migration_140::run( $this->wpdb, $this->table_purge_queue );
            }

            if ( version_compare( (string) $stored_version, '1.5.0', '<' ) ) {
                require_once DSB_PLUGIN_DIR . 'includes/migrations/upgrade-1.5.0.php';
                DSB_Migration_150::run( $this->wpdb, $this->table_keys, $this->table_user );
            }

            if ( version_compare( (string) $stored_version, '1.6.0', '<' ) ) {
                require_once DSB_PLUGIN_DIR . 'includes/migrations/upgrade-1.6.0.php';
                DSB_Migration_160::run( $this->wpdb, $this->table_user );
            }

            if ( version_compare( (string) $stored_version, '1.6.1', '<' ) ) {
                require_once DSB_PLUGIN_DIR . 'includes/migrations/upgrade-1.6.1.php';
                DSB_Migration_161::run( $this->wpdb, $this->table_keys );
            }

            if ( version_compare( (string) $stored_version, '1.7.0', '<' ) ) {
                require_once DSB_PLUGIN_DIR . 'includes/migrations/upgrade-1.7.0.php';
                DSB_Migration_170::run( $this->wpdb, $this->table_keys, $this->table_user );
            }

            if ( version_compare( (string) $stored_version, '1.8.1', '<' ) ) {
                require_once DSB_PLUGIN_DIR . 'includes/migrations/upgrade-1.8.1.php';
                DSB_Migration_181::run( $this->wpdb, $this->table_purge_queue );
            }

            update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
        }
    }

    public function drop_tables(): void {
        $this->wpdb->query( "DROP TABLE IF EXISTS {$this->table_logs}" );
        $this->wpdb->query( "DROP TABLE IF EXISTS {$this->table_keys}" );
        $this->wpdb->query( "DROP TABLE IF EXISTS {$this->table_user}" );
        $this->wpdb->query( "DROP TABLE IF EXISTS {$this->table_purge_queue}" );
        $this->wpdb->query( "DROP TABLE IF EXISTS {$this->table_provision_queue}" );
    }

    protected function maybe_create_triggers(): void {
        $db_name = $this->wpdb->dbname ?? DB_NAME;
        $trigger_keys = $this->wpdb->prefix . 'dsb_keys_after_delete';
        $trigger_user = $this->wpdb->prefix . 'dsb_user_after_delete';
        $status = [
            'keys'       => 'missing',
            'user'       => 'missing',
            'last_error' => '',
            'updated_at' => current_time( 'mysql', true ),
        ];
        $errors = [];

        $existing_keys = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = %s AND TRIGGER_NAME = %s',
                $db_name,
                $trigger_keys
            )
        );

        if ( $existing_keys ) {
            $status['keys'] = 'ok';
        } else {
            $sql = "CREATE TRIGGER {$trigger_keys} AFTER DELETE ON {$this->table_keys} FOR EACH ROW "
                . "INSERT INTO {$this->table_purge_queue} (wp_user_id, customer_email, subscription_id, reason, status) "
                . "VALUES (OLD.wp_user_id, OLD.customer_email, OLD.subscription_id, 'manual_key_delete', 'pending')";
            $this->wpdb->query( $sql );
            if ( $this->wpdb->last_error ) {
                $status['keys']   = 'failed';
                $errors[]         = $this->wpdb->last_error;
                dsb_log( 'error', 'Failed to create purge trigger for keys', [ 'error' => $this->wpdb->last_error ] );
            } else {
                $status['keys'] = 'ok';
                dsb_log( 'info', 'Created purge trigger for keys', [ 'last_error' => $this->wpdb->last_error ] );
            }
        }

        $existing_user = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = %s AND TRIGGER_NAME = %s',
                $db_name,
                $trigger_user
            )
        );

        if ( $existing_user ) {
            $status['user'] = 'ok';
        } else {
            $sql = "CREATE TRIGGER {$trigger_user} AFTER DELETE ON {$this->table_user} FOR EACH ROW "
                . "INSERT INTO {$this->table_purge_queue} (wp_user_id, customer_email, subscription_id, reason, status) "
                . "VALUES (OLD.wp_user_id, OLD.customer_email, OLD.subscription_id, 'manual_user_delete', 'pending')";
            $this->wpdb->query( $sql );
            if ( $this->wpdb->last_error ) {
                $status['user']  = 'failed';
                $errors[]        = $this->wpdb->last_error;
                dsb_log( 'error', 'Failed to create purge trigger for user truth', [ 'error' => $this->wpdb->last_error ] );
            } else {
                $status['user'] = 'ok';
                dsb_log( 'info', 'Created purge trigger for user truth', [ 'last_error' => $this->wpdb->last_error ] );
            }
        }

        if ( ! $errors && 'ok' === $status['keys'] && 'ok' === $status['user'] ) {
            $status['last_error'] = '';
        } else {
            $status['last_error'] = substr( implode( ';', array_unique( $errors ) ), 0, 500 );
            if ( ! $status['last_error'] ) {
                $status['last_error'] = 'trigger_creation_unverified';
            }
        }

        update_option( self::OPTION_TRIGGERS_STATUS, $status, false );
    }

    public function enqueue_purge_job( array $args ): int {
        $defaults = [
            'wp_user_id'      => null,
            'customer_email'  => null,
            'subscription_id' => null,
            'subscription_ids'=> [],
            'reason'          => '',
            'api_key_id'      => null,
        ];

        $data = wp_parse_args( $args, $defaults );

        $wp_user_id     = $data['wp_user_id'] ? absint( $data['wp_user_id'] ) : null;
        $customer_email = $data['customer_email'] ? sanitize_email( $data['customer_email'] ) : null;
        $reason         = sanitize_key( $data['reason'] );
        $subscription_id = $data['subscription_id'] ? sanitize_text_field( $data['subscription_id'] ) : null;
        $subscription_ids = is_array( $data['subscription_ids'] ) ? array_filter( array_map( 'sanitize_text_field', $data['subscription_ids'] ) ) : [];
        $api_key_id = $data['api_key_id'] ? absint( $data['api_key_id'] ) : null;

        if ( empty( $subscription_id ) && ! empty( $subscription_ids ) ) {
            $subscription_id = reset( $subscription_ids );
        }

        if ( ! $api_key_id ) {
            $api_key_id = $this->find_api_key_id_for_identity(
                [
                    'wp_user_id'       => $wp_user_id,
                    'customer_email'   => $customer_email,
                    'subscription_id'  => $subscription_id,
                    'subscription_ids' => $subscription_ids,
                ]
            );
        }

        $window_minutes = 30;
        $params         = [ 'pending', $reason ];
        $identity_parts = [];

        if ( $wp_user_id ) {
            $identity_parts[] = 'wp_user_id = %d';
            $params[]         = $wp_user_id;
        }

        if ( $customer_email ) {
            $identity_parts[] = 'customer_email = %s';
            $params[]         = $customer_email;
        }

        if ( $subscription_id ) {
            $identity_parts[] = 'subscription_id = %s';
            $params[]         = $subscription_id;
        }

        if ( $api_key_id ) {
            $identity_parts[] = 'api_key_id = %d';
            $params[]         = $api_key_id;
        }

        if ( $identity_parts ) {
            $identity_sql = implode( ' OR ', $identity_parts );
            $where_sql    = "status = %s AND reason = %s AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$window_minutes} MINUTE) AND ({$identity_sql})";
            $existing     = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT id FROM {$this->table_purge_queue} WHERE {$where_sql} LIMIT 1", ...$params ) );
            if ( $existing ) {
                return (int) $existing;
            }
        }

        $this->wpdb->insert(
            $this->table_purge_queue,
            [
                'wp_user_id'      => $wp_user_id ?: null,
                'customer_email'  => $customer_email ?: null,
                'subscription_id' => $subscription_id ?: null,
                'api_key_id'      => $api_key_id ?: null,
                'reason'          => $reason ?: 'manual',
                'status'          => 'pending',
            ]
        );

        return (int) $this->wpdb->insert_id;
    }

    public function claim_pending_purge_jobs( int $limit, string $claim_token, int $lease_seconds ): array {
        $limit         = max( 1, min( 100, $limit ) );
        $lease_seconds = max( 1, $lease_seconds );

        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table_purge_queue}
                SET status = %s,
                    claim_token = %s,
                    locked_until = DATE_ADD( UTC_TIMESTAMP(), INTERVAL %d SECOND ),
                    started_at = IFNULL( started_at, UTC_TIMESTAMP() ),
                    attempts = attempts + 1
                WHERE status = %s
                    AND ( next_run_at IS NULL OR next_run_at <= UTC_TIMESTAMP() )
                    AND ( locked_until IS NULL OR locked_until < UTC_TIMESTAMP() )
                ORDER BY id ASC
                LIMIT %d",
                'processing',
                $claim_token,
                $lease_seconds,
                'pending',
                $limit
            )
        );

        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_purge_queue} WHERE claim_token = %s ORDER BY id ASC",
                $claim_token
            ),
            ARRAY_A
        );
    }

    public function enqueue_provision_job( array $payload ): int {
        $event_id = isset( $payload['event_id'] ) ? sanitize_text_field( $payload['event_id'] ) : '';
        if ( ! $event_id ) {
            return 0;
        }

        $existing = $this->wpdb->get_var(
            $this->wpdb->prepare( "SELECT id FROM {$this->table_provision_queue} WHERE event_id = %s LIMIT 1", $event_id )
        );
        if ( $existing ) {
            return (int) $existing;
        }

        $encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
        $this->wpdb->insert(
            $this->table_provision_queue,
            [
                'event_id' => $event_id,
                'payload'  => $encoded,
                'status'   => 'pending',
            ]
        );

        return (int) $this->wpdb->insert_id;
    }

    public function claim_pending_provision_jobs( int $limit, string $claim_token, int $lease_seconds ): array {
        $limit         = max( 1, min( 100, $limit ) );
        $lease_seconds = max( 1, $lease_seconds );

        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table_provision_queue}
                SET status = %s,
                    claim_token = %s,
                    locked_until = DATE_ADD( UTC_TIMESTAMP(), INTERVAL %d SECOND )
                WHERE status IN (%s, %s)
                    AND ( next_run_at IS NULL OR next_run_at <= UTC_TIMESTAMP() )
                    AND ( locked_until IS NULL OR locked_until < UTC_TIMESTAMP() )
                ORDER BY id ASC
                LIMIT %d",
                'processing',
                $claim_token,
                $lease_seconds,
                'pending',
                'retry',
                $limit
            )
        );

        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_provision_queue} WHERE claim_token = %s ORDER BY id ASC",
                $claim_token
            ),
            ARRAY_A
        );
    }

    public function mark_provision_job_done( int $id ): void {
        $this->wpdb->update(
            $this->table_provision_queue,
            [
                'status'       => 'done',
                'last_error'   => null,
                'claim_token'  => null,
                'locked_until' => null,
                'next_run_at'  => null,
            ],
            [ 'id' => $id ]
        );
    }

    public function mark_provision_job_error( array $job, string $error, int $max_attempts, int $next_delay_seconds ): void {
        $id       = (int) ( $job['id'] ?? 0 );
        if ( ! $id ) {
            return;
        }

        if ( function_exists( 'dsb_mask_string' ) ) {
            $error = dsb_mask_string( $error );
        }

        $attempts = (int) ( $job['attempts'] ?? 0 ) + 1;
        $next_status = $attempts >= $max_attempts ? 'failed' : 'retry';

        $next_run_at = 'retry' === $next_status
            ? gmdate( 'Y-m-d H:i:s', time() + max( 1, $next_delay_seconds ) )
            : null;

        $this->wpdb->update(
            $this->table_provision_queue,
            [
                'status'       => $next_status,
                'attempts'     => $attempts,
                'last_error'   => wp_strip_all_tags( $error ),
                'claim_token'  => null,
                'locked_until' => null,
                'next_run_at'  => $next_run_at,
            ],
            [ 'id' => $id ]
        );
    }

    public function get_provision_jobs_for_identity( array $identity, int $limit = 25 ): array {
        $limit = max( 1, min( 100, $limit ) );
        $rows  = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_provision_queue}
                WHERE status IN (%s, %s, %s, %s)
                ORDER BY updated_at DESC
                LIMIT %d",
                'pending',
                'processing',
                'retry',
                'failed',
                $limit
            ),
            ARRAY_A
        );

        if ( ! $rows ) {
            return [];
        }

        $email = isset( $identity['customer_email'] ) ? sanitize_email( $identity['customer_email'] ) : '';
        $subscription_id = isset( $identity['subscription_id'] ) ? sanitize_text_field( $identity['subscription_id'] ) : '';
        $wp_user_id = isset( $identity['wp_user_id'] ) ? absint( $identity['wp_user_id'] ) : 0;

        $matches = [];
        foreach ( $rows as $row ) {
            $payload = json_decode( $row['payload'] ?? '', true );
            if ( ! is_array( $payload ) ) {
                continue;
            }

            $payload_email = isset( $payload['customer_email'] ) ? sanitize_email( $payload['customer_email'] ) : '';
            $payload_sub   = isset( $payload['subscription_id'] ) ? sanitize_text_field( $payload['subscription_id'] ) : '';
            $payload_user  = isset( $payload['wp_user_id'] ) ? absint( $payload['wp_user_id'] ) : 0;

            $matches_identity = false;
            if ( $subscription_id && $payload_sub && $subscription_id === $payload_sub ) {
                $matches_identity = true;
            } elseif ( $email && $payload_email && $email === $payload_email ) {
                $matches_identity = true;
            } elseif ( $wp_user_id && $payload_user && $wp_user_id === $payload_user ) {
                $matches_identity = true;
            }

            if ( $matches_identity ) {
                $row['payload_decoded'] = $payload;
                $matches[] = $row;
            }
        }

        return $matches;
    }

    public function mark_job_done( int $id ): void {
        $this->wpdb->update(
            $this->table_purge_queue,
            [
                'status'       => 'done',
                'last_error'   => null,
                'claim_token'  => null,
                'locked_until' => null,
                'finished_at'  => current_time( 'mysql', true ),
                'next_run_at'  => null,
            ],
            [ 'id' => $id ]
        );
    }

    public function update_purge_job_api_key_id( int $id, int $api_key_id ): void {
        $id         = absint( $id );
        $api_key_id = absint( $api_key_id );
        if ( ! $id || ! $api_key_id ) {
            return;
        }

        $this->wpdb->update(
            $this->table_purge_queue,
            [
                'api_key_id' => $api_key_id,
            ],
            [ 'id' => $id ]
        );
    }

    public function mark_job_error( array $job, string $error, int $max_attempts ): void {
        $id       = (int) ( $job['id'] ?? 0 );
        $attempts = (int) ( $job['attempts'] ?? 0 );
        if ( ! $id ) {
            return;
        }

        $next_status = $attempts >= $max_attempts ? 'error' : 'pending';

        $backoff_minutes = $attempts > 0 ? min( pow( 2, $attempts - 1 ) * 5, 360 ) : 5;
        $next_run_at     = $next_status === 'pending'
            ? gmdate( 'Y-m-d H:i:s', time() + ( $backoff_minutes * MINUTE_IN_SECONDS ) )
            : null;

        $this->wpdb->update(
            $this->table_purge_queue,
            [
                'status'       => $next_status,
                'attempts'     => $attempts,
                'last_error'   => wp_strip_all_tags( $error ),
                'claim_token'  => null,
                'locked_until' => null,
                'finished_at'  => current_time( 'mysql', true ),
                'next_run_at'  => $next_run_at,
            ],
            [ 'id' => $id ]
        );
    }

    public function get_identities_for_wp_user_id( int $wp_user_id ): array {
        $wp_user_id = absint( $wp_user_id );
        if ( ! $wp_user_id ) {
            return [ 'emails' => [], 'subscription_ids' => [] ];
        }

        $emails = [];
        $subs   = [];

        $user_rows = $this->wpdb->get_results(
            $this->wpdb->prepare( "SELECT customer_email, subscription_id FROM {$this->table_user} WHERE wp_user_id = %d", $wp_user_id ),
            ARRAY_A
        );

        foreach ( $user_rows as $row ) {
            if ( ! empty( $row['customer_email'] ) ) {
                $emails[] = sanitize_email( $row['customer_email'] );
            }
            if ( ! empty( $row['subscription_id'] ) ) {
                $subs[] = sanitize_text_field( (string) $row['subscription_id'] );
            }
        }

        $key_rows = $this->wpdb->get_results(
            $this->wpdb->prepare( "SELECT customer_email, subscription_id FROM {$this->table_keys} WHERE wp_user_id = %d", $wp_user_id ),
            ARRAY_A
        );

        foreach ( $key_rows as $row ) {
            if ( ! empty( $row['customer_email'] ) ) {
                $emails[] = sanitize_email( $row['customer_email'] );
            }
            if ( ! empty( $row['subscription_id'] ) ) {
                $subs[] = sanitize_text_field( (string) $row['subscription_id'] );
            }
        }

        $emails = array_values( array_filter( array_unique( $emails ) ) );
        $subs   = array_values( array_filter( array_unique( $subs ) ) );

        return [ 'emails' => $emails, 'subscription_ids' => $subs ];
    }

    public function find_api_key_id_for_identity( array $identity ): int {
        $wp_user_id = isset( $identity['wp_user_id'] ) ? absint( $identity['wp_user_id'] ) : 0;
        $customer_email = '';
        $subscription_id = '';

        if ( ! empty( $identity['customer_email'] ) ) {
            $customer_email = sanitize_email( $identity['customer_email'] );
        } elseif ( ! empty( $identity['emails'] ) && is_array( $identity['emails'] ) ) {
            $email = reset( $identity['emails'] );
            $customer_email = $email ? sanitize_email( $email ) : '';
        }

        if ( ! empty( $identity['subscription_id'] ) ) {
            $subscription_id = sanitize_text_field( $identity['subscription_id'] );
        } elseif ( ! empty( $identity['subscription_ids'] ) && is_array( $identity['subscription_ids'] ) ) {
            $sub = reset( $identity['subscription_ids'] );
            $subscription_id = $sub ? sanitize_text_field( $sub ) : '';
        }

        if ( $wp_user_id ) {
            $found = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT node_api_key_id FROM {$this->table_keys} WHERE wp_user_id = %d AND node_api_key_id IS NOT NULL ORDER BY updated_at DESC LIMIT 1",
                    $wp_user_id
                )
            );
            if ( $found ) {
                return (int) $found;
            }
        }

        if ( $customer_email ) {
            $found = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT node_api_key_id FROM {$this->table_keys} WHERE customer_email = %s AND node_api_key_id IS NOT NULL ORDER BY updated_at DESC LIMIT 1",
                    $customer_email
                )
            );
            if ( $found ) {
                return (int) $found;
            }
        }

        if ( $subscription_id ) {
            $found = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT node_api_key_id FROM {$this->table_keys} WHERE subscription_id = %s AND node_api_key_id IS NOT NULL ORDER BY updated_at DESC LIMIT 1",
                    $subscription_id
                )
            );
            if ( $found ) {
                return (int) $found;
            }
        }

        return 0;
    }

    public function delete_user_rows_local( int $wp_user_id, array $emails = [], array $subscription_ids = [] ): void {
        $wp_user_id     = absint( $wp_user_id );
        $emails         = array_values( array_filter( array_map( 'sanitize_email', $emails ) ) );
        $subscription_ids = array_values( array_filter( array_map( 'sanitize_text_field', $subscription_ids ) ) );

        if ( $subscription_ids ) {
            $placeholders = implode( ',', array_fill( 0, count( $subscription_ids ), '%s' ) );
            $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->table_logs} WHERE subscription_id IN ($placeholders)", ...$subscription_ids ) );
        }

        if ( $emails ) {
            $placeholders = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
            $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->table_logs} WHERE customer_email IN ($placeholders)", ...$emails ) );
        }

        if ( $wp_user_id ) {
            $this->wpdb->delete( $this->table_keys, [ 'wp_user_id' => $wp_user_id ], [ '%d' ] );
            $this->wpdb->delete( $this->table_user, [ 'wp_user_id' => $wp_user_id ], [ '%d' ] );
        } else {
            if ( $emails ) {
                $placeholders = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
                $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->table_keys} WHERE customer_email IN ($placeholders)", ...$emails ) );
                $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->table_user} WHERE customer_email IN ($placeholders)", ...$emails ) );
            }
            if ( $subscription_ids ) {
                $placeholders = implode( ',', array_fill( 0, count( $subscription_ids ), '%s' ) );
                $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->table_keys} WHERE subscription_id IN ($placeholders)", ...$subscription_ids ) );
                $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->table_user} WHERE subscription_id IN ($placeholders)", ...$subscription_ids ) );
            }
        }
    }

    public function delete_users_not_in_ids( array $wp_user_ids ): int {
        $wp_user_ids = array_values( array_filter( array_map( 'absint', $wp_user_ids ) ) );
        if ( empty( $wp_user_ids ) ) {
            return 0;
        }

        $placeholders = implode( ',', array_fill( 0, count( $wp_user_ids ), '%d' ) );
        return (int) $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->table_user} WHERE wp_user_id NOT IN ($placeholders)", ...$wp_user_ids ) );
    }

    public function delete_users_by_node_ids_not_in( array $remote_node_ids, bool $allow_empty_remote = false ): int {
        $remote_node_ids = array_values( array_filter( array_map( 'absint', $remote_node_ids ) ) );

        if ( empty( $remote_node_ids ) ) {
            if ( ! $allow_empty_remote ) {
                return 0;
            }

            return (int) $this->wpdb->query( "DELETE FROM {$this->table_user} WHERE node_api_key_id IS NOT NULL" );
        }

        $placeholders = implode( ',', array_fill( 0, count( $remote_node_ids ), '%d' ) );
        return (int) $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->table_user} WHERE node_api_key_id IS NOT NULL AND node_api_key_id NOT IN ($placeholders)", ...$remote_node_ids ) );
    }

    public function delete_keys_by_node_ids_not_in( array $remote_node_ids, int $batch_size = 500, bool $allow_empty_remote = false ): int {
        $remote_node_ids = array_values( array_filter( array_map( 'absint', $remote_node_ids ) ) );

        // Only delete rows that have node_api_key_id; leave NULL rows untouched for safety.
        $deleted = 0;
        $last_id = 0;

        if ( empty( $remote_node_ids ) && $allow_empty_remote ) {
            return (int) $this->wpdb->query( "DELETE FROM {$this->table_keys} WHERE node_api_key_id IS NOT NULL" );
        }

        do {
            $local_ids = $this->wpdb->get_col( $this->wpdb->prepare( "SELECT node_api_key_id FROM {$this->table_keys} WHERE node_api_key_id IS NOT NULL AND node_api_key_id > %d ORDER BY node_api_key_id ASC LIMIT %d", $last_id, $batch_size ) );
            $local_ids = array_values( array_filter( array_map( 'absint', (array) $local_ids ) ) );

            if ( empty( $local_ids ) ) {
                break;
            }

            $last_id = max( $local_ids );
            $missing = array_diff( $local_ids, $remote_node_ids );
            if ( empty( $missing ) ) {
                continue;
            }

            $placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
            $deleted     += (int) $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->table_keys} WHERE node_api_key_id IN ($placeholders)", ...$missing ) );
        } while ( true );

        return $deleted;
    }

    public function delete_keys_without_node_id_not_in_subs( array $remote_subscription_ids ): int {
        $remote_subscription_ids = array_values( array_filter( array_map( 'sanitize_text_field', $remote_subscription_ids ) ) );

        if ( empty( $remote_subscription_ids ) ) {
            return 0;
        }

        $placeholders = implode( ',', array_fill( 0, count( $remote_subscription_ids ), '%s' ) );
        return (int) $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->table_keys} WHERE node_api_key_id IS NULL AND subscription_id NOT IN ($placeholders)", ...$remote_subscription_ids ) );
    }

    public function delete_users_without_node_id_not_in_subs( array $remote_subscription_ids ): int {
        $remote_subscription_ids = array_values( array_filter( array_map( 'sanitize_text_field', $remote_subscription_ids ) ) );

        if ( empty( $remote_subscription_ids ) ) {
            return 0;
        }

        $placeholders = implode( ',', array_fill( 0, count( $remote_subscription_ids ), '%s' ) );
        return (int) $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->table_user} WHERE node_api_key_id IS NULL AND subscription_id IS NOT NULL AND subscription_id NOT IN ($placeholders)", ...$remote_subscription_ids ) );
    }

    public function get_tracked_user_ids(): array {
        $ids = $this->wpdb->get_col( "SELECT wp_user_id FROM {$this->table_user}" );
        return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
    }

    public function get_all_key_node_ids( int $batch_size = 500, int $offset = 0 ): array {
        $ids = $this->wpdb->get_col( $this->wpdb->prepare( "SELECT node_api_key_id FROM {$this->table_keys} WHERE node_api_key_id IS NOT NULL LIMIT %d OFFSET %d", $batch_size, $offset ) );
        return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
    }

    public function upsert_user( array $data ): array {
        $defaults = [
            'wp_user_id'      => 0,
            'customer_email'  => null,
            'subscription_id' => null,
            'order_id'        => null,
            'product_id'      => null,
            'plan_slug'       => null,
            'status'          => null,
            'valid_from'      => null,
            'valid_until'     => null,
            'node_api_key_id' => null,
            'source'          => 'wps_rest',
            'last_sync_at'    => current_time( 'mysql', true ),
        ];

        $data = wp_parse_args( $data, $defaults );

        $data['wp_user_id']      = absint( $data['wp_user_id'] );
        $data['customer_email']  = $data['customer_email'] ? sanitize_email( $data['customer_email'] ) : null;
        $data['subscription_id'] = $data['subscription_id'] ? sanitize_text_field( (string) $data['subscription_id'] ) : null;
        $data['order_id']        = $data['order_id'] ? absint( $data['order_id'] ) : null;
        $data['product_id']      = $data['product_id'] ? absint( $data['product_id'] ) : null;
        $data['plan_slug']       = $data['plan_slug'] ? sanitize_text_field( dsb_normalize_plan_slug( $data['plan_slug'] ) ) : null;
        $data['status']          = $data['status'] ? sanitize_text_field( $data['status'] ) : null;
        $data['valid_from']      = $data['valid_from'] ? sanitize_text_field( $data['valid_from'] ) : null;
        $data['valid_until']     = $data['valid_until'] ? sanitize_text_field( $data['valid_until'] ) : null;
        $data['node_api_key_id'] = $data['node_api_key_id'] ? absint( $data['node_api_key_id'] ) : null;
        $data['source']          = $data['source'] ? sanitize_text_field( $data['source'] ) : 'wps_rest';
        $data['last_sync_at']    = $data['last_sync_at'] ? sanitize_text_field( $data['last_sync_at'] ) : current_time( 'mysql', true );

        $result = [
            'status'           => 'skipped',
            'wp_user_id'       => $data['wp_user_id'],
            'subscription_id'  => $data['subscription_id'],
            'conflict_type'    => null,
            'conflict_local'   => null,
            'conflict_remote'  => null,
        ];

        if ( ! $data['wp_user_id'] || '' === (string) $data['subscription_id'] ) {
            $result['status'] = 'legacy';
            return $result;
        }

        $conflict = $this->detect_pair_conflict( $this->table_user, $data['wp_user_id'], (string) $data['subscription_id'] );
        if ( $conflict ) {
            $result['status']          = 'conflict';
            $result['conflict_type']   = $conflict['type'];
            $result['conflict_local']  = $conflict['local'];
            $result['conflict_remote'] = [ 'wp_user_id' => $data['wp_user_id'], 'subscription_id' => $data['subscription_id'] ];
            return $result;
        }

        $existing = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->table_user} WHERE wp_user_id = %d AND subscription_id = %s",
                $data['wp_user_id'],
                $data['subscription_id']
            ),
            ARRAY_A
        );

        if ( $existing ) {
            if ( empty( $data['node_api_key_id'] ) && ! empty( $existing['node_api_key_id'] ) ) {
                $data['node_api_key_id'] = absint( $existing['node_api_key_id'] );
            }
            $this->wpdb->update( $this->table_user, $data, [ 'id' => $existing['id'] ] );
            dsb_log( 'info', 'Updated user truth row', [ 'wp_user_id' => $data['wp_user_id'], 'subscription_id' => $data['subscription_id'] ] );
            $result['status'] = 'updated';
        } else {
            $this->wpdb->insert( $this->table_user, $data );
            dsb_log( 'info', 'Inserted user truth row', [ 'wp_user_id' => $data['wp_user_id'], 'subscription_id' => $data['subscription_id'] ] );
            $result['status'] = 'inserted';
        }

        if ( $this->wpdb->last_error ) {
            $result['status'] = 'error';
            dsb_log( 'error', 'User upsert failed', [
                'error'             => $this->wpdb->last_error,
                'wp_user_id'        => $data['wp_user_id'],
                'subscription_id'   => $data['subscription_id'],
            ] );
        }

        return $result;
    }

    /**
     * Retrieve a truth-table row for a given WordPress user ID.
     */
    public function get_user_truth_by_wp_user_id( $wp_user_id ): ?array {
        $wp_user_id = absint( $wp_user_id );

        if ( ! $wp_user_id ) {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table_user} WHERE wp_user_id = %d", $wp_user_id ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        return [
            'wp_user_id'      => isset( $row['wp_user_id'] ) ? absint( $row['wp_user_id'] ) : 0,
            'customer_email'  => $row['customer_email'] ?? null,
            'subscription_id' => $row['subscription_id'] ?? null,
            'order_id'        => $row['order_id'] ?? null,
            'product_id'      => isset( $row['product_id'] ) ? absint( $row['product_id'] ) : null,
            'plan_slug'       => $row['plan_slug'] ?? null,
            'status'          => $row['status'] ?? null,
            'valid_from'      => $row['valid_from'] ?? null,
            'valid_until'     => $row['valid_until'] ?? null,
            'source'          => $row['source'] ?? null,
            'last_sync_at'    => $row['last_sync_at'] ?? null,
        ];
    }

    public function log_event( array $data ): void {
        $record = [
            'event'           => sanitize_text_field( $data['event'] ?? '' ),
            'customer_email'  => isset( $data['customer_email'] ) ? sanitize_email( $data['customer_email'] ) : null,
            'plan_slug'       => sanitize_text_field( $data['plan_slug'] ?? '' ),
            'subscription_id' => sanitize_text_field( $data['subscription_id'] ?? '' ),
            'order_id'        => sanitize_text_field( $data['order_id'] ?? '' ),
            'response_action' => sanitize_text_field( $data['response_action'] ?? '' ),
            'http_code'       => isset( $data['http_code'] ) ? absint( $data['http_code'] ) : null,
            'error_excerpt'   => isset( $data['error_excerpt'] ) ? wp_strip_all_tags( $data['error_excerpt'] ) : null,
        ];

        if ( function_exists( 'dsb_mask_secrets' ) ) {
            $record = dsb_mask_secrets( $record );
        } elseif ( function_exists( 'dsb_mask_string' ) ) {
            if ( isset( $record['response_action'] ) && is_string( $record['response_action'] ) ) {
                $record['response_action'] = dsb_mask_string( $record['response_action'] );
            }
            if ( isset( $record['error_excerpt'] ) && is_string( $record['error_excerpt'] ) ) {
                $record['error_excerpt'] = dsb_mask_string( $record['error_excerpt'] );
            }
        }

        $this->wpdb->insert( $this->table_logs, $record );

        $count = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->table_logs}" ) );
        if ( $count > 200 ) {
            $to_delete = $count - 200;
            $ids       = $this->wpdb->get_col( $this->wpdb->prepare( "SELECT id FROM {$this->table_logs} ORDER BY id ASC LIMIT %d", $to_delete ) );
            if ( $ids ) {
                $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->table_logs} WHERE id IN ($placeholders)", ...$ids ) );
            }
        }
    }

    public function get_logs( int $limit = 200 ): array {
        $limit = max( 1, min( 200, $limit ) );
        return $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->table_logs} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
    }

    public function get_keys( int $per_page, int $page ): array {
        $offset = ( $page - 1 ) * $per_page;
        return $this->wpdb->get_results(
            $this->wpdb->prepare( "SELECT * FROM {$this->table_keys} ORDER BY updated_at DESC LIMIT %d OFFSET %d", $per_page, $offset ),
            ARRAY_A
        );
    }

    public function count_keys(): int {
        return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_keys}" );
    }

    public function upsert_key( array $data ): array {
        $defaults = [
            'subscription_id'     => '',
            'customer_email'      => '',
            'wp_user_id'          => null,
            'customer_name'       => null,
            'subscription_status' => null,
            'plan_slug'           => '',
            'status'              => 'unknown',
            'key_prefix'          => null,
            'key_last4'           => null,
            'valid_from'          => null,
            'valid_until'         => null,
            'node_plan_id'        => null,
            'node_api_key_id'     => null,
            'last_action'         => null,
            'last_http_code'      => null,
            'last_error'          => null,
        ];
        $data = wp_parse_args( $data, $defaults );

        $data['subscription_id']     = sanitize_text_field( $data['subscription_id'] );
        $data['customer_email']      = sanitize_email( $data['customer_email'] );
        $data['wp_user_id']          = $data['wp_user_id'] ? absint( $data['wp_user_id'] ) : null;
        $data['customer_name']       = $data['customer_name'] ? sanitize_text_field( $data['customer_name'] ) : null;
        $data['subscription_status'] = $data['subscription_status'] ? sanitize_text_field( $data['subscription_status'] ) : null;
        $data['plan_slug']           = sanitize_text_field( dsb_normalize_plan_slug( $data['plan_slug'] ) );
        $data['status']              = sanitize_text_field( $data['status'] );
        $data['key_prefix']          = $data['key_prefix'] ? sanitize_text_field( $data['key_prefix'] ) : null;
        $data['key_last4']           = $data['key_last4'] ? sanitize_text_field( $data['key_last4'] ) : null;
        $data['node_plan_id']        = $data['node_plan_id'] ? sanitize_text_field( (string) $data['node_plan_id'] ) : null;
        $data['node_api_key_id']     = $data['node_api_key_id'] ? absint( $data['node_api_key_id'] ) : null;
        $data['last_action']         = $data['last_action'] ? sanitize_text_field( $data['last_action'] ) : null;
        $data['last_http_code']      = $data['last_http_code'] ? absint( $data['last_http_code'] ) : null;
        $data['last_error']          = $data['last_error'] ? wp_strip_all_tags( (string) $data['last_error'] ) : null;

        $result = [
            'status'           => 'skipped',
            'wp_user_id'       => $data['wp_user_id'],
            'subscription_id'  => $data['subscription_id'],
            'conflict_type'    => null,
            'conflict_local'   => null,
            'conflict_remote'  => null,
        ];

        if ( ! $data['wp_user_id'] || '' === $data['subscription_id'] ) {
            $result['status'] = 'legacy';
            return $result;
        }

        $conflict = $this->detect_pair_conflict( $this->table_keys, $data['wp_user_id'], $data['subscription_id'] );
        if ( $conflict ) {
            $result['status']          = 'conflict';
            $result['conflict_type']   = $conflict['type'];
            $result['conflict_local']  = $conflict['local'];
            $result['conflict_remote'] = [ 'wp_user_id' => $data['wp_user_id'], 'subscription_id' => $data['subscription_id'] ];
            return $result;
        }

        $existing = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->table_keys} WHERE wp_user_id = %d AND subscription_id = %s",
                $data['wp_user_id'],
                $data['subscription_id']
            ),
            ARRAY_A
        );

        if ( $existing ) {
            if ( empty( $data['node_api_key_id'] ) && ! empty( $existing['node_api_key_id'] ) ) {
                $data['node_api_key_id'] = absint( $existing['node_api_key_id'] );
            }
            $this->wpdb->update( $this->table_keys, $data, [ 'id' => $existing['id'] ] );
            dsb_log( 'info', 'Updated key record via strict mirror', [ 'id' => $existing['id'], 'wp_user_id' => $data['wp_user_id'], 'subscription_id' => $data['subscription_id'] ] );
            $result['status'] = 'updated';
        } else {
            $this->wpdb->insert( $this->table_keys, $data );
            dsb_log( 'info', 'Inserted key record via strict mirror', [ 'node_api_key_id' => $data['node_api_key_id'], 'subscription_id' => $data['subscription_id'], 'wp_user_id' => $data['wp_user_id'] ] );
            $result['status'] = 'inserted';
        }

        if ( $this->wpdb->last_error ) {
            $result['status'] = 'error';
            dsb_log( 'error', 'Key upsert failed', [
                'error'      => $this->wpdb->last_error,
                'data'       => [
                    'subscription_id' => $data['subscription_id'],
                    'node_api_key_id' => $data['node_api_key_id'],
                    'wp_user_id'      => $data['wp_user_id'],
                ],
            ] );
        }

        return $result;
    }

    /**
     * Backward-compatible alias for strict mirror upsert.
     */
    public function upsert_key_strict( array $data ): array {
        return $this->upsert_key( $data );
    }

    public function find_key( string $subscription_id ): ?array {
        $row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table_keys} WHERE subscription_id = %s", $subscription_id ), ARRAY_A );
        return $row ?: null;
    }

    public function get_key_by_subscription_id( string $subscription_id ): ?array {
        return $this->find_key( $subscription_id );
    }

    public function delete_keys_not_in_pairs( array $remote_pairs, int $batch_size = 500, bool $allow_empty_remote = false ): int {
        $remote_pairs = $this->normalize_pair_map( $remote_pairs );

        if ( empty( $remote_pairs ) && ! $allow_empty_remote ) {
            return 0;
        }

        $deleted = 0;
        $last_id = 0;

        do {
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT id, wp_user_id, subscription_id FROM {$this->table_keys} WHERE wp_user_id > 0 AND subscription_id IS NOT NULL AND subscription_id <> '' AND id > %d ORDER BY id ASC LIMIT %d",
                    $last_id,
                    $batch_size
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $last_id = max( $last_id, (int) $row['id'] );
                $pair    = $row['wp_user_id'] . '|' . $row['subscription_id'];
                if ( isset( $remote_pairs[ $pair ] ) ) {
                    continue;
                }

                if ( empty( $remote_pairs ) && ! $allow_empty_remote ) {
                    continue;
                }

                $this->wpdb->delete( $this->table_keys, [ 'id' => $row['id'] ], [ '%d' ] );
                $deleted += (int) $this->wpdb->rows_affected;
            }
        } while ( count( $rows ) === $batch_size );

        return $deleted;
    }

    public function delete_users_not_in_pairs( array $remote_pairs, int $batch_size = 500, bool $allow_empty_remote = false ): int {
        $remote_pairs = $this->normalize_pair_map( $remote_pairs );

        if ( empty( $remote_pairs ) && ! $allow_empty_remote ) {
            return 0;
        }

        $deleted = 0;
        $last_id = 0;

        do {
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT id, wp_user_id, subscription_id FROM {$this->table_user} WHERE wp_user_id > 0 AND subscription_id IS NOT NULL AND subscription_id <> '' AND id > %d ORDER BY id ASC LIMIT %d",
                    $last_id,
                    $batch_size
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $last_id = max( $last_id, (int) $row['id'] );
                $pair    = $row['wp_user_id'] . '|' . $row['subscription_id'];
                if ( isset( $remote_pairs[ $pair ] ) ) {
                    continue;
                }

                if ( empty( $remote_pairs ) && ! $allow_empty_remote ) {
                    continue;
                }

                $this->wpdb->delete( $this->table_user, [ 'id' => $row['id'] ], [ '%d' ] );
                $deleted += (int) $this->wpdb->rows_affected;
            }
        } while ( count( $rows ) === $batch_size );

        return $deleted;
    }

    protected function detect_pair_conflict( string $table, int $wp_user_id, string $subscription_id ): ?array {
        $table           = esc_sql( $table );
        $subscription_id = sanitize_text_field( $subscription_id );
        $wp_user_id      = absint( $wp_user_id );

        $local_user_mismatch = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT wp_user_id, subscription_id FROM {$table} WHERE subscription_id = %s AND subscription_id <> '' AND wp_user_id <> %d AND wp_user_id > 0 LIMIT 1",
                $subscription_id,
                $wp_user_id
            ),
            ARRAY_A
        );

        if ( $local_user_mismatch ) {
            return [
                'type'  => 'subscription_mismatch',
                'local' => [
                    'wp_user_id'      => absint( $local_user_mismatch['wp_user_id'] ),
                    'subscription_id' => $local_user_mismatch['subscription_id'],
                ],
            ];
        }

        $local_subscription_mismatch = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT wp_user_id, subscription_id FROM {$table} WHERE wp_user_id = %d AND wp_user_id > 0 AND subscription_id IS NOT NULL AND subscription_id <> '' AND subscription_id <> %s LIMIT 1",
                $wp_user_id,
                $subscription_id
            ),
            ARRAY_A
        );

        if ( $local_subscription_mismatch ) {
            return [
                'type'  => 'user_mismatch',
                'local' => [
                    'wp_user_id'      => absint( $local_subscription_mismatch['wp_user_id'] ),
                    'subscription_id' => $local_subscription_mismatch['subscription_id'],
                ],
            ];
        }

        return null;
    }

    protected function normalize_pair_map( array $pairs ): array {
        $map = [];
        foreach ( $pairs as $pair ) {
            if ( ! is_string( $pair ) ) {
                continue;
            }
            $parts = explode( '|', $pair, 2 );
            if ( 2 !== count( $parts ) ) {
                continue;
            }

            $wp_user_id      = absint( $parts[0] );
            $subscription_id = sanitize_text_field( (string) $parts[1] );
            if ( $wp_user_id <= 0 || '' === $subscription_id ) {
                continue;
            }

            $map[ $wp_user_id . '|' . $subscription_id ] = true;
        }

        return $map;
    }
}

/*
 * Test checklist:
 * 1) Place subscription, confirm initial activated send.
 * 2) Confirm filter capture log appears at least once when viewing subscriptions list OR when WPS calculates expiry.
 * 3) Confirm _dsb_wps_valid_until meta is set on subscription.
 * 4) Confirm second “activated” send is allowed only if key.valid_until is NULL and payload has valid_until.
 * 5) Confirm davix_bridge_keys.valid_until becomes non-NULL.
 */
