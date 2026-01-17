<?php

defined( 'ABSPATH' ) || exit;

return "CREATE TABLE {$this->table_user} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id BIGINT UNSIGNED NOT NULL,
            customer_email VARCHAR(190) DEFAULT NULL,
            subscription_id VARCHAR(191) DEFAULT NULL,
            order_id BIGINT UNSIGNED DEFAULT NULL,
            product_id BIGINT UNSIGNED DEFAULT NULL,
            plan_slug VARCHAR(190) DEFAULT NULL,
            status VARCHAR(50) DEFAULT NULL,
            valid_from DATETIME NULL,
            valid_until DATETIME NULL,
            node_api_key_id BIGINT UNSIGNED DEFAULT NULL,
            source VARCHAR(50) DEFAULT 'wps_rest',
            last_sync_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_wp_user_subscription (wp_user_id, subscription_id),
            KEY idx_email (customer_email),
            KEY idx_sub (subscription_id),
            KEY idx_status (status),
            KEY idx_plan (plan_slug),
            KEY idx_node_api_key_id (node_api_key_id)
        ) $charset_collate;";
