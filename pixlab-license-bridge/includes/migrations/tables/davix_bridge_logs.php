<?php

defined( 'ABSPATH' ) || exit;

return "CREATE TABLE {$this->table_logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event varchar(80) NOT NULL,
            customer_email varchar(190) DEFAULT NULL,
            plan_slug varchar(190) DEFAULT NULL,
            subscription_id varchar(190) DEFAULT NULL,
            order_id varchar(190) DEFAULT NULL,
            response_action varchar(80) DEFAULT NULL,
            http_code smallint DEFAULT NULL,
            error_excerpt text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY subscription_id (subscription_id)
        ) $charset_collate;";
