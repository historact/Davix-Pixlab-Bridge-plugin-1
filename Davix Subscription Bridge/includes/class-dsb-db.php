<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_DB {
    const OPTION_DELETE_ON_UNINSTALL = 'dsb_delete_on_uninstall';

    /** @var \wpdb */
    protected $wpdb;
    /** @var string */
    protected $table_logs;
    /** @var string */
    protected $table_keys;

    public function __construct( \wpdb $wpdb ) {
        $this->wpdb       = $wpdb;
        $this->table_logs = $wpdb->prefix . 'davix_bridge_logs';
        $this->table_keys = $wpdb->prefix . 'davix_bridge_keys';
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
            UNIQUE KEY subscription_id (subscription_id),
            UNIQUE KEY wp_user_id (wp_user_id),
            KEY customer_email (customer_email)
        ) $charset_collate;";

        dbDelta( $sql_logs );
        dbDelta( $sql_keys );
    }

    public function migrate(): void {
        $this->create_tables();
    }

    public function drop_tables(): void {
        $this->wpdb->query( "DROP TABLE IF EXISTS {$this->table_logs}" );
        $this->wpdb->query( "DROP TABLE IF EXISTS {$this->table_keys}" );
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
        $data['plan_slug']           = sanitize_text_field( $data['plan_slug'] );
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
            if ( null === $data['valid_until'] && null !== $existing['valid_until'] ) {
                $data['valid_until'] = $existing['valid_until'];
                dsb_log( 'debug', 'Retaining existing valid_until on upsert', [ 'subscription_id' => $existing['subscription_id'] ] );
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
