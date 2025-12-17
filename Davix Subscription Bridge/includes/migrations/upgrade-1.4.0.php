<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Migration_140 {
    public static function run( \wpdb $wpdb, string $table_purge_queue ): void {
        $table = esc_sql( $table_purge_queue );

        self::maybe_add_column( $wpdb, $table, 'claim_token', "ALTER TABLE `{$table}` ADD COLUMN claim_token varchar(64) DEFAULT NULL AFTER attempts" );
        self::maybe_add_column( $wpdb, $table, 'locked_until', "ALTER TABLE `{$table}` ADD COLUMN locked_until datetime DEFAULT NULL AFTER claim_token" );
        self::maybe_add_column( $wpdb, $table, 'started_at', "ALTER TABLE `{$table}` ADD COLUMN started_at datetime DEFAULT NULL AFTER locked_until" );
        self::maybe_add_column( $wpdb, $table, 'finished_at', "ALTER TABLE `{$table}` ADD COLUMN finished_at datetime DEFAULT NULL AFTER started_at" );
        self::maybe_add_column( $wpdb, $table, 'next_run_at', "ALTER TABLE `{$table}` ADD COLUMN next_run_at datetime DEFAULT NULL AFTER finished_at" );

        self::maybe_add_index( $wpdb, $table, 'idx_status_locked_until', "ALTER TABLE `{$table}` ADD INDEX idx_status_locked_until (status, locked_until)" );
        self::maybe_add_index( $wpdb, $table, 'idx_claim_token', "ALTER TABLE `{$table}` ADD INDEX idx_claim_token (claim_token)" );
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
