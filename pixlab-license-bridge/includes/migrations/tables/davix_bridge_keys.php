<?php

defined( 'ABSPATH' ) || exit;

return "CREATE TABLE {$this->table_keys} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscription_id varchar(190) NOT NULL,
            customer_email varchar(190) NOT NULL,
            wp_user_id BIGINT UNSIGNED DEFAULT NULL,
            customer_name varchar(255) DEFAULT NULL,
            subscription_status varchar(50) DEFAULT NULL,
            plan_slug varchar(190) NOT NULL,
            status varchar(60) NOT NULL,
            key_prefix varchar(20) DEFAULT NULL,
            key_last4 varchar(10) DEFAULT NULL,
            valid_from datetime DEFAULT NULL,
            valid_until datetime DEFAULT NULL,
            node_plan_id varchar(80) DEFAULT NULL,
            node_api_key_id BIGINT UNSIGNED DEFAULT NULL,
            last_action varchar(60) DEFAULT NULL,
            last_http_code smallint DEFAULT NULL,
            last_error text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_wp_user_subscription (wp_user_id, subscription_id),
            KEY wp_user_id (wp_user_id),
            KEY subscription_id (subscription_id),
            UNIQUE KEY node_api_key_id (node_api_key_id),
            KEY customer_email (customer_email)
        ) $charset_collate;";
