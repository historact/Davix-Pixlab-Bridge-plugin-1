<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Migration_150 {
    public static function run( \wpdb $wpdb, string $table_keys, string $table_user ): void {
        $table_keys = esc_sql( $table_keys );
        $table_user = esc_sql( $table_user );

        self::maybe_add_column( $wpdb, $table_keys, 'node_api_key_id', "ALTER TABLE `{$table_keys}` ADD COLUMN node_api_key_id BIGINT UNSIGNED DEFAULT NULL AFTER node_plan_id" );
        self::maybe_add_index( $wpdb, $table_keys, 'node_api_key_id', "ALTER TABLE `{$table_keys}` ADD UNIQUE KEY node_api_key_id (node_api_key_id)" );

        self::maybe_add_column( $wpdb, $table_user, 'subscription_id_str', "ALTER TABLE `{$table_user}` ADD COLUMN subscription_id_str VARCHAR(191) DEFAULT NULL AFTER subscription_id" );
        self::maybe_add_column( $wpdb, $table_user, 'node_api_key_id', "ALTER TABLE `{$table_user}` ADD COLUMN node_api_key_id BIGINT UNSIGNED DEFAULT NULL AFTER valid_until" );
        self::maybe_add_index( $wpdb, $table_user, 'idx_node_api_key_id', "ALTER TABLE `{$table_user}` ADD INDEX idx_node_api_key_id (node_api_key_id)" );
        self::maybe_add_index( $wpdb, $table_user, 'idx_subscription_id_str', "ALTER TABLE `{$table_user}` ADD INDEX idx_subscription_id_str (subscription_id_str)" );
    }

    protected static function maybe_add_column( \wpdb $wpdb, string $table, string $column, string $statement ): void {
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', $column ) );
        if ( $exists ) {
            return;
        }
        $wpdb->query( $statement );
    }

    protected static function maybe_add_index( \wpdb $wpdb, string $table, string $index, string $statement ): void {
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s', $index ) );
        if ( $exists ) {
            return;
        }
        $wpdb->query( $statement );
    }
}
