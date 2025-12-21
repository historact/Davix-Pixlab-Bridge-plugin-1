<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_DB {
    const OPTION_DELETE_ON_UNINSTALL = 'dsb_delete_on_uninstall';
    const OPTION_DB_VERSION          = 'dsb_db_version';
    const DB_VERSION                 = '1.4.0';

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

    public function __construct( \wpdb $wpdb ) {
        $this->wpdb       = $wpdb;
        $this->table_logs = $wpdb->prefix . 'davix_bridge_logs';
        $this->table_keys = $wpdb->prefix . 'davix_bridge_keys';
        $this->table_user = $wpdb->prefix . 'davix_bridge_user';
        $this->table_purge_queue = $wpdb->prefix . 'davix_bridge_purge_queue';
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
            last_action varchar(60) DEFAULT NULL,
            last_http_code smallint DEFAULT NULL,
            last_error text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY subscription_id (subscription_id),
            UNIQUE KEY wp_user_id (wp_user_id),
            KEY customer_email (customer_email)
        ) $charset_collate;";

        $sql_user = "CREATE TABLE {$this->table_user} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL,
            customer_email VARCHAR(190) DEFAULT NULL,
            subscription_id BIGINT UNSIGNED DEFAULT NULL,
            order_id BIGINT UNSIGNED DEFAULT NULL,
            product_id BIGINT UNSIGNED DEFAULT NULL,
            plan_slug VARCHAR(190) DEFAULT NULL,
            status VARCHAR(50) DEFAULT NULL,
            valid_from DATETIME NULL,
            valid_until DATETIME NULL,
            source VARCHAR(50) DEFAULT 'wps_rest',
            last_sync_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_wp_user_id (wp_user_id),
            KEY idx_email (customer_email),
            KEY idx_sub (subscription_id),
            KEY idx_status (status),
            KEY idx_plan (plan_slug)
        ) $charset_collate;";

        dsb_log( 'debug', 'Running dbDelta for davix_bridge_logs', [ 'sql' => $sql_logs ] );
        dbDelta( $sql_logs );
        dsb_log( 'debug', 'dbDelta result for davix_bridge_logs', [ 'last_error' => $this->wpdb->last_error ] );

        dsb_log( 'debug', 'Running dbDelta for davix_bridge_purge_queue', [ 'sql' => $sql_purge_queue ] );
        dbDelta( $sql_purge_queue );
        dsb_log( 'debug', 'dbDelta result for davix_bridge_purge_queue', [ 'last_error' => $this->wpdb->last_error ] );

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

            update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
        }
    }

    public function drop_tables(): void {
        $this->wpdb->query( "DROP TABLE IF EXISTS {$this->table_logs}" );
        $this->wpdb->query( "DROP TABLE IF EXISTS {$this->table_keys}" );
        $this->wpdb->query( "DROP TABLE IF EXISTS {$this->table_user}" );
        $this->wpdb->query( "DROP TABLE IF EXISTS {$this->table_purge_queue}" );
    }

    protected function maybe_create_triggers(): void {
        $db_name = $this->wpdb->dbname ?? DB_NAME;
        $trigger_keys = $this->wpdb->prefix . 'dsb_keys_after_delete';
        $trigger_user = $this->wpdb->prefix . 'dsb_user_after_delete';

        $existing_keys = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = %s AND TRIGGER_NAME = %s',
                $db_name,
                $trigger_keys
            )
        );

        if ( ! $existing_keys ) {
            $sql = "CREATE TRIGGER {$trigger_keys} AFTER DELETE ON {$this->table_keys} FOR EACH ROW "
                . "INSERT INTO {$this->table_purge_queue} (wp_user_id, customer_email, subscription_id, reason, status) "
                . "VALUES (OLD.wp_user_id, OLD.customer_email, OLD.subscription_id, 'manual_key_delete', 'pending')";
            $this->wpdb->query( $sql );
            dsb_log( 'info', 'Created purge trigger for keys', [ 'last_error' => $this->wpdb->last_error ] );
        }

        $existing_user = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = %s AND TRIGGER_NAME = %s',
                $db_name,
                $trigger_user
            )
        );

        if ( ! $existing_user ) {
            $sql = "CREATE TRIGGER {$trigger_user} AFTER DELETE ON {$this->table_user} FOR EACH ROW "
                . "INSERT INTO {$this->table_purge_queue} (wp_user_id, customer_email, subscription_id, reason, status) "
                . "VALUES (OLD.wp_user_id, OLD.customer_email, OLD.subscription_id, 'manual_user_delete', 'pending')";
            $this->wpdb->query( $sql );
            dsb_log( 'info', 'Created purge trigger for user truth', [ 'last_error' => $this->wpdb->last_error ] );
        }
    }

    public function enqueue_purge_job( array $args ): int {
        $defaults = [
            'wp_user_id'      => null,
            'customer_email'  => null,
            'subscription_id' => null,
            'subscription_ids'=> [],
            'reason'          => '',
        ];

        $data = wp_parse_args( $args, $defaults );

        $wp_user_id     = $data['wp_user_id'] ? absint( $data['wp_user_id'] ) : null;
        $customer_email = $data['customer_email'] ? sanitize_email( $data['customer_email'] ) : null;
        $reason         = sanitize_key( $data['reason'] );
        $subscription_id = $data['subscription_id'] ? sanitize_text_field( $data['subscription_id'] ) : null;
        $subscription_ids = is_array( $data['subscription_ids'] ) ? array_filter( array_map( 'sanitize_text_field', $data['subscription_ids'] ) ) : [];

        if ( empty( $subscription_id ) && ! empty( $subscription_ids ) ) {
            $subscription_id = reset( $subscription_ids );
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

    public function delete_users_not_in( array $wp_user_ids ): int {
        $wp_user_ids = array_values( array_filter( array_map( 'absint', $wp_user_ids ) ) );
        if ( empty( $wp_user_ids ) ) {
            return 0;
        }

        $placeholders = implode( ',', array_fill( 0, count( $wp_user_ids ), '%d' ) );
        return (int) $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->table_user} WHERE wp_user_id NOT IN ($placeholders)", ...$wp_user_ids ) );
    }

    public function delete_keys_not_in( array $wp_user_ids, array $emails, array $subscription_ids ): int {
        $wp_user_ids      = array_values( array_filter( array_map( 'absint', $wp_user_ids ) ) );
        $emails           = array_values( array_filter( array_map( 'sanitize_email', $emails ) ) );
        $subscription_ids = array_values( array_filter( array_map( 'sanitize_text_field', $subscription_ids ) ) );

        if ( empty( $wp_user_ids ) && empty( $emails ) && empty( $subscription_ids ) ) {
            return 0;
        }

        $clauses = [];
        $params  = [];

        if ( $wp_user_ids ) {
            $placeholders  = implode( ',', array_fill( 0, count( $wp_user_ids ), '%d' ) );
            $clauses[]     = "(wp_user_id IS NULL OR wp_user_id NOT IN ($placeholders))";
            $params        = array_merge( $params, $wp_user_ids );
        }

        if ( $emails ) {
            $placeholders  = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
            $clauses[]     = "(customer_email IS NULL OR customer_email NOT IN ($placeholders))";
            $params        = array_merge( $params, $emails );
        }

        if ( $subscription_ids ) {
            $placeholders  = implode( ',', array_fill( 0, count( $subscription_ids ), '%s' ) );
            $clauses[]     = "(subscription_id IS NULL OR subscription_id NOT IN ($placeholders))";
            $params        = array_merge( $params, $subscription_ids );
        }

        if ( empty( $clauses ) ) {
            return 0;
        }

        $sql = 'DELETE FROM ' . $this->table_keys . ' WHERE ' . implode( ' AND ', $clauses );

        return (int) $this->wpdb->query( $this->wpdb->prepare( $sql, ...$params ) );
    }

    public function get_tracked_user_ids(): array {
        $ids = $this->wpdb->get_col( "SELECT wp_user_id FROM {$this->table_user}" );
        return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
    }

    public function upsert_user( array $data ): void {
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
            'source'          => 'wps_rest',
            'last_sync_at'    => current_time( 'mysql', true ),
        ];

        $data = wp_parse_args( $data, $defaults );

        $data['wp_user_id']      = absint( $data['wp_user_id'] );
        $data['customer_email']  = $data['customer_email'] ? sanitize_email( $data['customer_email'] ) : null;
        $data['subscription_id'] = $data['subscription_id'] ? absint( $data['subscription_id'] ) : null;
        $data['order_id']        = $data['order_id'] ? absint( $data['order_id'] ) : null;
        $data['product_id']      = $data['product_id'] ? absint( $data['product_id'] ) : null;
        $data['plan_slug']       = $data['plan_slug'] ? sanitize_text_field( dsb_normalize_plan_slug( $data['plan_slug'] ) ) : null;
        $data['status']          = $data['status'] ? sanitize_text_field( $data['status'] ) : null;
        $data['valid_from']      = $data['valid_from'] ? sanitize_text_field( $data['valid_from'] ) : null;
        $data['valid_until']     = $data['valid_until'] ? sanitize_text_field( $data['valid_until'] ) : null;
        $data['source']          = $data['source'] ? sanitize_text_field( $data['source'] ) : 'wps_rest';
        $data['last_sync_at']    = $data['last_sync_at'] ? sanitize_text_field( $data['last_sync_at'] ) : current_time( 'mysql', true );

        if ( ! $data['wp_user_id'] ) {
            return;
        }

        $existing = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table_user} WHERE wp_user_id = %d", $data['wp_user_id'] ), ARRAY_A );

        if ( $existing ) {
            if ( ( null === $data['valid_until'] || '' === $data['valid_until'] ) && ! empty( $existing['valid_until'] ) ) {
                $data['valid_until'] = $existing['valid_until'];
            }

            if ( ( null === $data['valid_from'] || '' === $data['valid_from'] ) && ! empty( $existing['valid_from'] ) ) {
                $data['valid_from'] = $existing['valid_from'];
            }

            foreach ( [ 'customer_email', 'subscription_id', 'order_id', 'product_id', 'plan_slug', 'status', 'source' ] as $field ) {
                if ( ( null === $data[ $field ] || '' === $data[ $field ] ) && isset( $existing[ $field ] ) ) {
                    $data[ $field ] = $existing[ $field ];
                }
            }

            $this->wpdb->update( $this->table_user, $data, [ 'id' => $existing['id'] ] );
            dsb_log( 'info', 'Updated user truth row', [ 'wp_user_id' => $data['wp_user_id'] ] );
        } else {
            $this->wpdb->insert( $this->table_user, $data );
            dsb_log( 'info', 'Inserted user truth row', [ 'wp_user_id' => $data['wp_user_id'] ] );
        }
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

    public function upsert_key( array $data ): void {
        $defaults = [
            'subscription_id' => '',
            'customer_email'  => '',
            'wp_user_id'      => null,
            'customer_name'   => null,
            'subscription_status' => null,
            'plan_slug'       => '',
            'status'          => 'unknown',
            'key_prefix'      => null,
            'key_last4'       => null,
            'valid_from'      => null,
            'valid_until'     => null,
            'node_plan_id'    => null,
            'last_action'     => null,
            'last_http_code'  => null,
            'last_error'      => null,
        ];
        $data     = wp_parse_args( $data, $defaults );

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
        $data['last_action']         = $data['last_action'] ? sanitize_text_field( $data['last_action'] ) : null;
        $data['last_http_code']      = $data['last_http_code'] ? absint( $data['last_http_code'] ) : null;
        $data['last_error']          = $data['last_error'] ? wp_strip_all_tags( (string) $data['last_error'] ) : null;

        $identity  = 'subscription_id';
        $existing  = null;
        $identity_value = $data['subscription_id'];

        if ( $data['wp_user_id'] ) {
            $existing       = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table_keys} WHERE wp_user_id = %d", $data['wp_user_id'] ), ARRAY_A );
            $identity       = 'wp_user_id';
            $identity_value = $data['wp_user_id'];
        }

        if ( ! $existing && $data['customer_email'] ) {
            $existing       = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table_keys} WHERE customer_email = %s", $data['customer_email'] ), ARRAY_A );
            $identity       = 'customer_email';
            $identity_value = $data['customer_email'];
        }

        if ( ! $existing && $data['subscription_id'] ) {
            $existing       = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table_keys} WHERE subscription_id = %s", $data['subscription_id'] ), ARRAY_A );
            $identity       = 'subscription_id';
            $identity_value = $data['subscription_id'];
        }

        if ( $existing ) {
            if ( ( null === $data['valid_until'] || '' === $data['valid_until'] ) && ! empty( $existing['valid_until'] ) ) {
                dsb_log(
                    'debug',
                    'Key valid_until retained',
                    [
                        'subscription_id' => $existing['subscription_id'],
                        'old_valid_until' => $existing['valid_until'],
                        'new_valid_until' => $data['valid_until'],
                    ]
                );
                $data['valid_until'] = $existing['valid_until'];
            } elseif ( empty( $existing['valid_until'] ) && ! empty( $data['valid_until'] ) ) {
                dsb_log(
                    'info',
                    'Key valid_until updated',
                    [
                        'subscription_id' => $existing['subscription_id'],
                        'old_valid_until' => $existing['valid_until'],
                        'new_valid_until' => $data['valid_until'],
                    ]
                );
            }

            if ( ( null === $data['valid_from'] || '' === $data['valid_from'] ) && ! empty( $existing['valid_from'] ) ) {
                dsb_log(
                    'debug',
                    'Key valid_from retained',
                    [
                        'subscription_id' => $existing['subscription_id'],
                        'old_valid_from'  => $existing['valid_from'],
                        'new_valid_from'  => $data['valid_from'],
                    ]
                );
                $data['valid_from'] = $existing['valid_from'];
            } elseif ( empty( $existing['valid_from'] ) && ! empty( $data['valid_from'] ) ) {
                dsb_log(
                    'info',
                    'Key valid_from updated',
                    [
                        'subscription_id' => $existing['subscription_id'],
                        'old_valid_from'  => $existing['valid_from'],
                        'new_valid_from'  => $data['valid_from'],
                    ]
                );
            }

            foreach ( [ 'key_prefix', 'key_last4' ] as $key_field ) {
                if ( ( null === $data[ $key_field ] || '' === $data[ $key_field ] ) && ! empty( $existing[ $key_field ] ) ) {
                    $data[ $key_field ] = $existing[ $key_field ];
                }
            }

            if ( empty( $data['subscription_id'] ) ) {
                $data['subscription_id'] = $existing['subscription_id'];
            }

            foreach ( [ 'customer_email', 'customer_name', 'subscription_status', 'plan_slug' ] as $field ) {
                if ( empty( $data[ $field ] ) && ! empty( $existing[ $field ] ) ) {
                    $data[ $field ] = $existing[ $field ];
                }
            }

            $this->wpdb->update( $this->table_keys, $data, [ 'id' => $existing['id'] ] );
            dsb_log( 'info', 'Updated key record via identity match', [ 'identity' => $identity, 'value' => $identity_value, 'id' => $existing['id'] ] );
        } else {
            $this->wpdb->insert( $this->table_keys, $data );
            dsb_log( 'info', 'Inserted key record', [ 'identity' => $identity, 'value' => $identity_value ] );
        }
    }

    public function find_key( string $subscription_id ): ?array {
        $row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table_keys} WHERE subscription_id = %s", $subscription_id ), ARRAY_A );
        return $row ?: null;
    }

    public function get_key_by_subscription_id( string $subscription_id ): ?array {
        return $this->find_key( $subscription_id );
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
