<?php

defined( 'ABSPATH' ) || exit;

return "CREATE TABLE {$this->table_provision_queue} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id varchar(190) NOT NULL,
            payload longtext NOT NULL,
            status varchar(16) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            next_run_at datetime DEFAULT NULL,
            locked_until datetime DEFAULT NULL,
            claim_token varchar(64) DEFAULT NULL,
            last_error text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            KEY idx_status_next_run (status, next_run_at),
            KEY idx_locked_until (locked_until),
            KEY idx_claim_token (claim_token)
        ) $charset_collate;";
