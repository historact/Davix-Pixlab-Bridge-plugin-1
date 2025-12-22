<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Migration_161 {
    public static function run( \wpdb $wpdb, string $table_keys ): void {
        $table_keys = esc_sql( $table_keys );
        self::drop_unique_wp_user_id( $wpdb, $table_keys );
        self::ensure_wp_user_index( $wpdb, $table_keys );
    }

    protected static function drop_unique_wp_user_id( \wpdb $wpdb, string $table_keys ): void {
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM ' . $table_keys . ' WHERE Key_name = %s AND Non_unique = 0', 'wp_user_id' ) );
        if ( ! $exists ) {
            return;
        }

        $wpdb->query( "ALTER TABLE `{$table_keys}` DROP INDEX wp_user_id" );
    }

    protected static function ensure_wp_user_index( \wpdb $wpdb, string $table_keys ): void {
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM ' . $table_keys . ' WHERE Key_name = %s', 'wp_user_id' ) );
        if ( $exists ) {
            return;
        }

        $wpdb->query( "ALTER TABLE `{$table_keys}` ADD INDEX wp_user_id (wp_user_id)" );
    }
}
