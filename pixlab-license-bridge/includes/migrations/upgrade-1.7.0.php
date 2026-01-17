<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Migration_170 {
    public static function run( \wpdb $wpdb, string $table_keys, string $table_user ): void {
        $table_keys = esc_sql( $table_keys );
        $table_user = esc_sql( $table_user );

        self::drop_unique_index( $wpdb, $table_keys, 'subscription_id' );
        self::drop_unique_index( $wpdb, $table_user, 'uniq_wp_user_id' );

        self::ensure_index( $wpdb, $table_keys, 'subscription_id', "ALTER TABLE `{$table_keys}` ADD INDEX subscription_id (subscription_id)" );
        self::ensure_index( $wpdb, $table_user, 'idx_sub', "ALTER TABLE `{$table_user}` ADD INDEX idx_sub (subscription_id)" );

        self::ensure_unique_index( $wpdb, $table_keys, 'uniq_wp_user_subscription', "ALTER TABLE `{$table_keys}` ADD UNIQUE KEY uniq_wp_user_subscription (wp_user_id, subscription_id)" );
        self::ensure_unique_index( $wpdb, $table_user, 'uniq_wp_user_subscription', "ALTER TABLE `{$table_user}` ADD UNIQUE KEY uniq_wp_user_subscription (wp_user_id, subscription_id)" );
    }

    protected static function drop_unique_index( \wpdb $wpdb, string $table, string $index ): void {
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s AND Non_unique = 0', $index ) );
        if ( ! $exists ) {
            return;
        }

        $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX {$index}" );
    }

    protected static function ensure_unique_index( \wpdb $wpdb, string $table, string $index, string $statement ): void {
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s AND Non_unique = 0', $index ) );
        if ( $exists ) {
            return;
        }

        $wpdb->query( $statement );
    }

    protected static function ensure_index( \wpdb $wpdb, string $table, string $index, string $statement ): void {
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s', $index ) );
        if ( $exists ) {
            return;
        }

        $wpdb->query( $statement );
    }
}
