<?php

defined( 'ABSPATH' ) || exit;

return "CREATE TABLE {$this->table_purge_queue} (
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
