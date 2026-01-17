<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Migration_160 {
    public static function run( \wpdb $wpdb, string $table_user ): void {
        $table_user = esc_sql( $table_user );

        // Change subscription_id to VARCHAR(191) and drop subscription_id_str when present.
        self::maybe_alter_subscription_id( $wpdb, $table_user );
        self::maybe_backfill_subscription_id( $wpdb, $table_user );
        self::maybe_drop_subscription_id_str( $wpdb, $table_user );
    }

    protected static function maybe_alter_subscription_id( \wpdb $wpdb, string $table_user ): void {
        $column = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table_user . ' LIKE %s', 'subscription_id' ) );
        $type   = $column->Type ?? '';

        if ( preg_match( '/varchar/i', $type ) ) {
            return;
        }

        $wpdb->query( "ALTER TABLE `{$table_user}` MODIFY COLUMN subscription_id VARCHAR(191) DEFAULT NULL" );
    }

    protected static function maybe_backfill_subscription_id( \wpdb $wpdb, string $table_user ): void {
        $has_str_column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table_user . ' LIKE %s', 'subscription_id_str' ) );
        if ( ! $has_str_column ) {
            return;
        }

        $wpdb->query( "UPDATE `{$table_user}` SET subscription_id = subscription_id_str WHERE (subscription_id IS NULL OR subscription_id = '') AND subscription_id_str IS NOT NULL AND subscription_id_str <> ''" );
    }

    protected static function maybe_drop_subscription_id_str( \wpdb $wpdb, string $table_user ): void {
        $has_str_column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table_user . ' LIKE %s', 'subscription_id_str' ) );
        if ( ! $has_str_column ) {
            return;
        }

        $wpdb->query( "ALTER TABLE `{$table_user}` DROP COLUMN subscription_id_str" );
    }
}
