<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Migration_181 {
    public static function run( \wpdb $wpdb, string $table_purge_queue ): void {
        $table_purge_queue = esc_sql( $table_purge_queue );
        self::ensure_api_key_id_column( $wpdb, $table_purge_queue );
        self::ensure_api_key_id_index( $wpdb, $table_purge_queue );
    }

    protected static function ensure_api_key_id_column( \wpdb $wpdb, string $table_purge_queue ): void {
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW COLUMNS FROM ' . $table_purge_queue . ' LIKE %s',
                'api_key_id'
            )
        );

        if ( $exists ) {
            return;
        }

        $wpdb->query( "ALTER TABLE `{$table_purge_queue}` ADD COLUMN api_key_id BIGINT UNSIGNED DEFAULT NULL AFTER subscription_id" );
    }

    protected static function ensure_api_key_id_index( \wpdb $wpdb, string $table_purge_queue ): void {
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM ' . $table_purge_queue . ' WHERE Key_name = %s', 'api_key_id' ) );
        if ( $exists ) {
            return;
        }

        $wpdb->query( "ALTER TABLE `{$table_purge_queue}` ADD INDEX api_key_id (api_key_id)" );
    }
}
