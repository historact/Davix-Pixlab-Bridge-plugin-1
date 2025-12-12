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
            plan_slug varchar(190) NOT NULL,
            status varchar(60) NOT NULL,
            key_prefix varchar(20) DEFAULT NULL,
            key_last4 varchar(10) DEFAULT NULL,
            node_plan_id varchar(80) DEFAULT NULL,
            last_action varchar(60) DEFAULT NULL,
            last_http_code smallint DEFAULT NULL,
            last_error text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY subscription_id (subscription_id),
            KEY customer_email (customer_email)
        ) $charset_collate;";

        dbDelta( $sql_logs );
        dbDelta( $sql_keys );
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
            'plan_slug'       => '',
            'status'          => 'unknown',
            'key_prefix'      => null,
            'key_last4'       => null,
            'node_plan_id'    => null,
            'last_action'     => null,
            'last_http_code'  => null,
            'last_error'      => null,
        ];
        $data     = wp_parse_args( $data, $defaults );

        $existing = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT id FROM {$this->table_keys} WHERE subscription_id = %s", $data['subscription_id'] ) );

        if ( $existing ) {
            $this->wpdb->update( $this->table_keys, $data, [ 'subscription_id' => $data['subscription_id'] ] );
        } else {
            $this->wpdb->insert( $this->table_keys, $data );
        }
    }

    public function find_key( string $subscription_id ): ?array {
        $row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table_keys} WHERE subscription_id = %s", $subscription_id ), ARRAY_A );
        return $row ?: null;
    }
}
