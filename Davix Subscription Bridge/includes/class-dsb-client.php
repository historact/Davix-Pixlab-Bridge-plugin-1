<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Client {
    const OPTION_SETTINGS      = 'dsb_settings';
    const OPTION_PRODUCT_PLANS = 'dsb_product_plans';
    const OPTION_PLAN_PRODUCTS = 'dsb_plan_products';
    const OPTION_PLAN_SYNC     = 'dsb_plan_sync';

    protected $db;

    public function __construct( DSB_DB $db ) {
        $this->db = $db;
    }

    public function get_settings(): array {
        $defaults = array_merge(
            [
                'node_base_url' => '',
                'bridge_token'  => '',
                'enable_logging'=> 1,
                'debug_enabled' => 0,
                'debug_level'   => 'info',
                'debug_retention_days' => 7,
                'delete_data'   => 0,
                'allow_provision_without_refs' => 0,
                'enable_daily_resync' => 1,
                'resync_batch_size' => 100,
                'resync_lock_minutes' => 30,
                'resync_run_hour' => 3,
                'resync_disable_non_active' => 1,
                'wps_rest_consumer_secret' => '',
                'enable_node_poll_sync' => 0,
                'node_poll_interval_minutes' => 10,
                'node_poll_per_page' => 200,
                'node_poll_delete_stale' => 1,
                'node_poll_lock_minutes' => 10,
                'enable_purge_worker' => 1,
                'purge_lock_minutes'  => 10,
                'purge_lease_minutes' => 15,
                'purge_batch_size'    => 20,
                'alert_emails'        => '',
                'telegram_bot_token'  => '',
                'telegram_chat_ids'   => '',
                'alert_template'      => '',
                'recovery_template'   => '',
                'alert_threshold'     => 3,
                'alert_cooldown_minutes' => 60,
                'enable_alerts_purge_worker'   => 0,
                'enable_recovery_purge_worker' => 0,
                'enable_alerts_node_poll'      => 0,
                'enable_recovery_node_poll'    => 0,
                'enable_alerts_resync'         => 0,
                'enable_recovery_resync'       => 0,
                'enable_cron_debug_purge_worker' => 0,
                'enable_cron_debug_node_poll'    => 0,
                'enable_cron_debug_resync'       => 0,
            ],
            $this->get_style_defaults(),
            $this->get_label_defaults()
        );
        $options  = get_option( self::OPTION_SETTINGS, [] );
        return wp_parse_args( is_array( $options ) ? $options : [], $defaults );
    }

    public function get_style_defaults(): array {
        return [
            'style_dashboard_bg'          => '#0f172a',
            'style_card_bg'               => '#0b1220',
            'style_card_border'           => '#1e293b',
            'style_card_shadow'           => 'rgba(0,0,0,0.4)',
            'style_text_primary'          => '#f8fafc',
            'style_text_secondary'        => '#cbd5e1',
            'style_text_muted'            => '#94a3b8',
            'style_button_bg'             => '#0ea5e9',
            'style_button_text'           => '#0b1220',
            'style_button_border'         => '#0ea5e9',
            'style_button_hover_bg'       => '#0ea5e9',
            'style_button_hover_border'   => '#0ea5e9',
            'style_button_active_bg'      => '#0ea5e9',
            'style_input_bg'              => '#0f172a',
            'style_input_text'            => '#e2e8f0',
            'style_input_border'          => '#1f2a3d',
            'style_input_focus_border'    => '#0ea5e9',
            'style_badge_active_bg'       => '#0ea5e9',
            'style_badge_active_border'   => '#0ea5e9',
            'style_badge_active_text'     => '#0b1220',
            'style_badge_disabled_bg'     => '#1f2937',
            'style_badge_disabled_border' => '#1f2937',
            'style_badge_disabled_text'   => '#e2e8f0',
            'style_progress_track'        => '#111827',
            'style_progress_fill'         => '#22c55e',
            'style_progress_text'         => '#cbd5e1',
            'style_table_bg'              => '#0b1220',
            'style_table_header_bg'       => '#0f172a',
            'style_table_header_text'     => '#cbd5e1',
            'style_table_border'          => '#1e293b',
            'style_table_row_bg'          => '#0e1627',
            'style_table_row_hover_bg'    => '#111827',
            'style_table_error_text'      => '#f87171',
            'style_status_success_text'   => '#22c55e',
            'style_status_error_text'     => '#f87171',
        ];
    }

    public function get_label_defaults(): array {
        return [
            'label_current_plan'          => __( 'Current Plan', 'davix-sub-bridge' ),
            'label_usage_metered'         => __( 'Monthly limit', 'davix-sub-bridge' ),
            'label_api_key'               => __( 'API Key', 'davix-sub-bridge' ),
            'label_key'                   => __( 'Key', 'davix-sub-bridge' ),
            'label_created'               => __( 'Created', 'davix-sub-bridge' ),
            'label_disable_key'           => __( 'Disable Key', 'davix-sub-bridge' ),
            'label_enable_key'            => __( 'Enable Key', 'davix-sub-bridge' ),
            'label_regenerate_key'        => __( 'Regenerate Key', 'davix-sub-bridge' ),
            'label_usage_this_period'     => __( 'Usage this period', 'davix-sub-bridge' ),
            'label_used_calls'            => __( 'Used Calls', 'davix-sub-bridge' ),
            'label_history'               => __( 'History', 'davix-sub-bridge' ),
            'label_h2i'                   => __( 'H2I', 'davix-sub-bridge' ),
            'label_image'                 => __( 'IMAGE', 'davix-sub-bridge' ),
            'label_pdf'                   => __( 'PDF', 'davix-sub-bridge' ),
            'label_tools'                 => __( 'TOOLS', 'davix-sub-bridge' ),
            'label_date_time'             => __( 'Date/Time', 'davix-sub-bridge' ),
            'label_endpoint'              => __( 'Endpoint', 'davix-sub-bridge' ),
            'label_files'                 => __( 'Files', 'davix-sub-bridge' ),
            'label_bytes_in'              => __( 'Bytes In', 'davix-sub-bridge' ),
            'label_bytes_out'             => __( 'Bytes Out', 'davix-sub-bridge' ),
            'label_error'                 => __( 'Error', 'davix-sub-bridge' ),
            'label_status'                => __( 'Status', 'davix-sub-bridge' ),
            'label_create_key'            => __( 'Create Key', 'davix-sub-bridge' ),
            'label_create_api_key_title'  => __( 'Create API Key', 'davix-sub-bridge' ),
            'label_create_api_key_submit' => __( 'Create API Key', 'davix-sub-bridge' ),
            'label_usage_metered_hint'    => __( 'Usage metered', 'davix-sub-bridge' ),
            'label_login_required'        => __( 'Please log in to view your API usage.', 'davix-sub-bridge' ),
            'label_loading'               => __( 'Loading…', 'davix-sub-bridge' ),
            'label_no_requests'           => __( 'No requests yet.', 'davix-sub-bridge' ),
            'label_pagination_previous'   => __( 'Previous', 'davix-sub-bridge' ),
            'label_pagination_next'       => __( 'Next', 'davix-sub-bridge' ),
            'label_modal_title'           => __( 'Your new API key', 'davix-sub-bridge' ),
            'label_modal_hint'            => __( 'Shown once — copy it now.', 'davix-sub-bridge' ),
            'label_modal_close'           => __( 'Close', 'davix-sub-bridge' ),
        ];
    }

    public function get_style_settings(): array {
        $defaults = $this->get_style_defaults();
        $settings = get_option( self::OPTION_SETTINGS, [] );
        $settings = is_array( $settings ) ? $settings : [];
        $resolved = [];

        foreach ( $defaults as $key => $default ) {
            $value = array_key_exists( $key, $settings ) ? (string) $settings[ $key ] : '';
            $resolved[ $key ] = '' === $value ? $default : $value;
        }

        return $resolved;
    }

    public function get_label_settings(): array {
        $defaults = $this->get_label_defaults();
        $settings = $this->get_settings();
        $resolved = [];
        foreach ( $defaults as $key => $default ) {
            $value = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
            $resolved[ $key ] = '' === $value ? $default : $value;
        }

        return $resolved;
    }

    public function get_product_plans(): array {
        $plans = get_option( self::OPTION_PRODUCT_PLANS, [] );
        if ( ! is_array( $plans ) ) {
            return [];
        }

        $clean = [];
        foreach ( $plans as $product_id => $plan_slug ) {
            $pid = absint( $product_id );
            if ( $pid <= 0 ) {
                continue;
            }
            $clean[ $pid ] = dsb_normalize_plan_slug( $plan_slug );
        }

        if ( $clean !== $plans ) {
            update_option( self::OPTION_PRODUCT_PLANS, $clean );
        }

        return $clean;
    }

    public function get_plan_products(): array {
        $products = get_option( self::OPTION_PLAN_PRODUCTS, [] );
        if ( ! is_array( $products ) ) {
            return [];
        }
        return array_values( array_filter( array_map( 'absint', $products ) ) );
    }

    public function get_plan_sync_status(): array {
        $status = get_option( self::OPTION_PLAN_SYNC, [] );
        if ( ! is_array( $status ) ) {
            return [];
        }
        return $status;
    }

    public function save_settings( array $data ): void {
        $existing = $this->get_settings();

        $debug_enabled = 0;
        if ( isset( $data['debug_enabled'] ) ) {
            $debug_value   = is_array( $data['debug_enabled'] ) ? end( $data['debug_enabled'] ) : $data['debug_enabled'];
            $debug_enabled = (int) ( '1' === (string) $debug_value );
        }

        $clean = [
            'node_base_url' => esc_url_raw( $data['node_base_url'] ?? ( $existing['node_base_url'] ?? '' ) ),
            'bridge_token'  => sanitize_text_field( $data['bridge_token'] ?? ( $existing['bridge_token'] ?? '' ) ),
            'enable_logging'=> isset( $data['enable_logging'] ) ? 1 : ( $existing['enable_logging'] ?? 0 ),
            'debug_enabled' => $debug_enabled,
            'debug_level'   => isset( $data['debug_level'] ) ? sanitize_key( $data['debug_level'] ) : ( $existing['debug_level'] ?? 'info' ),
            'debug_retention_days' => isset( $data['debug_retention_days'] ) ? max( 1, (int) $data['debug_retention_days'] ) : ( $existing['debug_retention_days'] ?? 7 ),
            'delete_data'   => isset( $data['delete_data'] ) ? 1 : ( $existing['delete_data'] ?? 0 ),
            'allow_provision_without_refs' => isset( $data['allow_provision_without_refs'] ) ? 1 : ( $existing['allow_provision_without_refs'] ?? 0 ),
            'enable_daily_resync' => isset( $data['enable_daily_resync'] ) ? 1 : ( $existing['enable_daily_resync'] ?? 0 ),
            'resync_batch_size' => isset( $data['resync_batch_size'] ) ? (int) $data['resync_batch_size'] : ( $existing['resync_batch_size'] ?? 100 ),
            'resync_lock_minutes' => isset( $data['resync_lock_minutes'] ) ? (int) $data['resync_lock_minutes'] : ( $existing['resync_lock_minutes'] ?? 30 ),
            'resync_run_hour' => isset( $data['resync_run_hour'] ) ? (int) $data['resync_run_hour'] : ( $existing['resync_run_hour'] ?? 3 ),
            'resync_disable_non_active' => isset( $data['resync_disable_non_active'] ) ? 1 : ( $existing['resync_disable_non_active'] ?? 1 ),
            'wps_rest_consumer_secret' => isset( $data['wps_rest_consumer_secret'] ) ? sanitize_text_field( $data['wps_rest_consumer_secret'] ) : ( $existing['wps_rest_consumer_secret'] ?? '' ),
            'enable_node_poll_sync' => isset( $data['enable_node_poll_sync'] ) ? 1 : ( $existing['enable_node_poll_sync'] ?? 0 ),
            'node_poll_interval_minutes' => isset( $data['node_poll_interval_minutes'] ) ? (int) $data['node_poll_interval_minutes'] : ( $existing['node_poll_interval_minutes'] ?? 10 ),
            'node_poll_per_page' => isset( $data['node_poll_per_page'] ) ? (int) $data['node_poll_per_page'] : ( $existing['node_poll_per_page'] ?? 200 ),
            'node_poll_delete_stale' => isset( $data['node_poll_delete_stale'] ) ? 1 : ( $existing['node_poll_delete_stale'] ?? 1 ),
            'node_poll_lock_minutes' => isset( $data['node_poll_lock_minutes'] ) ? (int) $data['node_poll_lock_minutes'] : ( $existing['node_poll_lock_minutes'] ?? 10 ),
            'enable_purge_worker' => isset( $data['enable_purge_worker'] ) ? 1 : ( $existing['enable_purge_worker'] ?? 1 ),
            'purge_lock_minutes'  => isset( $data['purge_lock_minutes'] ) ? (int) $data['purge_lock_minutes'] : ( $existing['purge_lock_minutes'] ?? 10 ),
            'purge_lease_minutes' => isset( $data['purge_lease_minutes'] ) ? (int) $data['purge_lease_minutes'] : ( $existing['purge_lease_minutes'] ?? 15 ),
            'purge_batch_size'    => isset( $data['purge_batch_size'] ) ? (int) $data['purge_batch_size'] : ( $existing['purge_batch_size'] ?? 20 ),
            'alert_emails'        => isset( $data['alert_emails'] ) ? sanitize_textarea_field( $data['alert_emails'] ) : ( $existing['alert_emails'] ?? '' ),
            'telegram_bot_token'  => isset( $data['telegram_bot_token'] ) ? sanitize_text_field( $data['telegram_bot_token'] ) : ( $existing['telegram_bot_token'] ?? '' ),
            'telegram_chat_ids'   => isset( $data['telegram_chat_ids'] ) ? sanitize_textarea_field( $data['telegram_chat_ids'] ) : ( $existing['telegram_chat_ids'] ?? '' ),
            'alert_template'      => isset( $data['alert_template'] ) ? wp_kses_post( $data['alert_template'] ) : ( $existing['alert_template'] ?? '' ),
            'recovery_template'   => isset( $data['recovery_template'] ) ? wp_kses_post( $data['recovery_template'] ) : ( $existing['recovery_template'] ?? '' ),
            'alert_threshold'     => isset( $data['alert_threshold'] ) ? (int) $data['alert_threshold'] : ( $existing['alert_threshold'] ?? 3 ),
            'alert_cooldown_minutes' => isset( $data['alert_cooldown_minutes'] ) ? (int) $data['alert_cooldown_minutes'] : ( $existing['alert_cooldown_minutes'] ?? 60 ),
            'enable_alerts_purge_worker'   => isset( $data['enable_alerts_purge_worker'] ) ? 1 : ( $existing['enable_alerts_purge_worker'] ?? 0 ),
            'enable_recovery_purge_worker' => isset( $data['enable_recovery_purge_worker'] ) ? 1 : ( $existing['enable_recovery_purge_worker'] ?? 0 ),
            'enable_alerts_node_poll'      => isset( $data['enable_alerts_node_poll'] ) ? 1 : ( $existing['enable_alerts_node_poll'] ?? 0 ),
            'enable_recovery_node_poll'    => isset( $data['enable_recovery_node_poll'] ) ? 1 : ( $existing['enable_recovery_node_poll'] ?? 0 ),
            'enable_alerts_resync'         => isset( $data['enable_alerts_resync'] ) ? 1 : ( $existing['enable_alerts_resync'] ?? 0 ),
            'enable_recovery_resync'       => isset( $data['enable_recovery_resync'] ) ? 1 : ( $existing['enable_recovery_resync'] ?? 0 ),
            'enable_cron_debug_purge_worker' => isset( $data['enable_cron_debug_purge_worker'] ) ? 1 : ( $existing['enable_cron_debug_purge_worker'] ?? 0 ),
            'enable_cron_debug_node_poll'    => isset( $data['enable_cron_debug_node_poll'] ) ? 1 : ( $existing['enable_cron_debug_node_poll'] ?? 0 ),
            'enable_cron_debug_resync'       => isset( $data['enable_cron_debug_resync'] ) ? 1 : ( $existing['enable_cron_debug_resync'] ?? 0 ),
        ];

        $allowed_levels          = [ 'debug', 'info', 'warn', 'error' ];
        $clean['debug_level']    = in_array( $clean['debug_level'], $allowed_levels, true ) ? $clean['debug_level'] : 'info';
        $clean['debug_retention_days'] = max( 1, (int) $clean['debug_retention_days'] );
        $clean['resync_batch_size'] = max( 20, min( 500, (int) $clean['resync_batch_size'] ) );
        $clean['resync_lock_minutes'] = max( 5, (int) $clean['resync_lock_minutes'] );
        $clean['resync_run_hour'] = max( 0, min( 23, (int) $clean['resync_run_hour'] ) );
        $clean['node_poll_interval_minutes'] = max( 5, min( 60, (int) $clean['node_poll_interval_minutes'] ) );
        $clean['node_poll_per_page']         = max( 1, min( 500, (int) $clean['node_poll_per_page'] ) );
        $clean['node_poll_lock_minutes']     = max( 1, (int) $clean['node_poll_lock_minutes'] );
        $clean['purge_lock_minutes']         = max( 1, min( 120, (int) $clean['purge_lock_minutes'] ) );
        $clean['purge_lease_minutes']        = max( 1, min( 240, (int) $clean['purge_lease_minutes'] ) );
        $clean['purge_batch_size']           = max( 1, min( 100, (int) $clean['purge_batch_size'] ) );
        $clean['alert_threshold']            = max( 1, (int) $clean['alert_threshold'] );
        $clean['alert_cooldown_minutes']     = max( 1, (int) $clean['alert_cooldown_minutes'] );

        $plan_slug_meta = isset( $data['dsb_plan_slug_meta'] ) && is_array( $data['dsb_plan_slug_meta'] ) ? $data['dsb_plan_slug_meta'] : [];
        $plan_products = isset( $data['plan_products'] ) && is_array( $data['plan_products'] ) ? array_values( $data['plan_products'] ) : $this->get_plan_products();
        $plan_products = array_filter( array_map( 'absint', $plan_products ) );

        $existing_product_plans = $this->get_product_plans();

        $style_defaults = $this->get_style_defaults();
        foreach ( $style_defaults as $key => $default ) {
            $value = isset( $data[ $key ] ) ? sanitize_text_field( $data[ $key ] ) : ( $existing[ $key ] ?? $default );
            $clean[ $key ] = '' === $value ? $default : $value;
        }

        $label_defaults = $this->get_label_defaults();
        foreach ( $label_defaults as $key => $default ) {
            $value = isset( $data[ $key ] ) ? sanitize_text_field( $data[ $key ] ) : ( $existing[ $key ] ?? $default );
            $clean[ $key ] = '' === $value ? $default : $value;
        }

        $plans = $existing_product_plans;
        if ( isset( $data['product_plans'] ) && is_array( $data['product_plans'] ) ) {
            $plans = [];
            foreach ( $data['product_plans'] as $product_id => $plan_slug ) {
                $pid = absint( $product_id );
                if ( $pid <= 0 ) {
                    continue;
                }
                $plans[ $pid ] = dsb_normalize_plan_slug( sanitize_text_field( $plan_slug ) );
            }
        }

        foreach ( $plan_products as $pid ) {
            $slug = isset( $plan_slug_meta[ $pid ] ) ? dsb_normalize_plan_slug( sanitize_text_field( $plan_slug_meta[ $pid ] ) ) : '';

            if ( ! $slug ) {
                $existing_slug = dsb_normalize_plan_slug( get_post_meta( $pid, '_dsb_plan_slug', true ) );
                $slug          = $existing_slug ? $existing_slug : '';
            }

            if ( ! $slug ) {
                $product = wc_get_product( $pid );
                if ( $product ) {
                    $slug = dsb_normalize_plan_slug( $product->get_slug() );
                }
            }

            if ( $slug ) {
                update_post_meta( $pid, '_dsb_plan_slug', $slug );
                $plans[ $pid ] = $slug;
            }
        }

        update_option( self::OPTION_SETTINGS, $clean );
        update_option( self::OPTION_PRODUCT_PLANS, $plans );
        update_option( self::OPTION_PLAN_PRODUCTS, $plan_products );
        update_option( DSB_DB::OPTION_DELETE_ON_UNINSTALL, $clean['delete_data'] );
    }

    public function masked_token(): string {
        $settings = $this->get_settings();
        $token    = $settings['bridge_token'];
        if ( ! $token ) {
            return __( 'Not set', 'davix-sub-bridge' );
        }
        $len = strlen( $token );
        if ( $len <= 6 ) {
            return str_repeat( '*', $len );
        }
        return substr( $token, 0, 3 ) . str_repeat( '*', $len - 6 ) . substr( $token, -3 );
    }

    public function masked_consumer_secret(): string {
        $settings = $this->get_settings();
        $secret   = $settings['wps_rest_consumer_secret'] ?? '';
        if ( ! $secret ) {
            return __( 'Not set', 'davix-sub-bridge' );
        }

        $len = strlen( $secret );
        if ( $len <= 6 ) {
            return str_repeat( '*', $len );
        }

        return substr( $secret, 0, 3 ) . str_repeat( '*', $len - 6 ) . substr( $secret, -3 );
    }

    protected function request( string $path, string $method = 'GET', ?array $body = [], array $query = [] ) {
        $settings = $this->get_settings();
        $method   = strtoupper( $method );

        if ( empty( $settings['node_base_url'] ) ) {
            return new \WP_Error( 'dsb_missing_base', __( 'Node base URL missing', 'davix-sub-bridge' ) );
        }

        if ( empty( $settings['bridge_token'] ) ) {
            return new \WP_Error( 'dsb_missing_token', __( 'Bridge token missing', 'davix-sub-bridge' ) );
        }

        $url = $this->build_url( $path, $query );

        $args = [
            'timeout' => 15,
            'headers' => [
                'x-davix-bridge-token' => $settings['bridge_token'],
            ],
        ];

        if ( 'POST' === $method ) {
            $args['body']                    = wp_json_encode( $body );
            $args['headers']['Content-Type'] = 'application/json';
        }

        $response = 'POST' === $method ? wp_remote_post( $url, $args ) : wp_remote_get( $url, $args );
        if ( is_array( $response ) ) {
            $response['__dsb_request_url']    = $url;
            $response['__dsb_request_method'] = $method;
        }

        if ( is_wp_error( $response ) ) {
            $response->add_data( [ 'dsb_url' => $url, 'dsb_method' => $method ] );
        }

        return $response;
    }

    protected function build_url( string $path, array $query = [] ): string {
        $settings = $this->get_settings();
        $base     = isset( $settings['node_base_url'] ) ? rtrim( (string) $settings['node_base_url'], '/' ) : '';
        $url      = $base . '/' . ltrim( $path, '/' );

        if ( $query ) {
            $url = add_query_arg( $query, $url );
        }

        return $url;
    }

    protected function post_internal( string $path, array $body = [] ): array {
        $response = $this->request( $path, 'POST', $body );
        $result   = $this->prepare_response( $response );
        $result['url']    = is_array( $response ) && isset( $response['__dsb_request_url'] ) ? $response['__dsb_request_url'] : $this->build_url( $path );
        $result['method'] = 'POST';
        return $result;
    }

    public function send_event( array $payload ): array {
        $payload['plan_slug'] = isset( $payload['plan_slug'] ) ? dsb_normalize_plan_slug( $payload['plan_slug'] ) : '';
        $response = $this->request( '/internal/subscription/event', 'POST', $payload );
        $body     = is_wp_error( $response ) ? null : wp_remote_retrieve_body( $response );
        $decoded  = $body ? json_decode( $body, true ) : null;
        $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        $success  = false;
        if ( $code >= 200 && $code < 300 && is_array( $decoded ) ) {
            $status_value = isset( $decoded['status'] ) && is_string( $decoded['status'] ) ? strtolower( $decoded['status'] ) : ( $decoded['status'] ?? '' );
            $action_value = isset( $decoded['action'] ) && is_string( $decoded['action'] ) ? strtolower( $decoded['action'] ) : ( $decoded['action'] ?? '' );
            $ok_actions   = [ 'created', 'updated', 'reactivated', 'activated', 'renewed' ];
            $ok_statuses  = [ 'ok', 'active', 'disabled' ];
            $has_marker   = ( ! empty( $decoded['api_key_id'] ) || ! empty( $decoded['key'] ) || ! empty( $decoded['subscription_id'] ) );

            if ( 'error' !== $status_value && ( in_array( $status_value, $ok_statuses, true ) || in_array( $action_value, $ok_actions, true ) || $has_marker ) ) {
                $success = true;
            }
        }

        $log = [
            'event'           => $payload['event'] ?? '',
            'customer_email'  => $payload['customer_email'] ?? '',
            'plan_slug'       => $payload['plan_slug'] ?? '',
            'subscription_id' => $payload['subscription_id'] ?? '',
            'order_id'        => $payload['order_id'] ?? '',
            'response_action' => $decoded['action'] ?? null,
            'http_code'       => $code,
            'error_excerpt'   => is_wp_error( $response ) ? $response->get_error_message() : ( is_array( $decoded ) ? ( $decoded['status'] ?? '' ) : ( $body ? substr( $body, 0, 200 ) : '' ) ),
        ];
        $settings = $this->get_settings();
        if ( $settings['enable_logging'] ) {
            $this->db->log_event( $log );
        }

        $subscription_identifier = sanitize_text_field( $payload['subscription_id'] ?? '' );
        if ( ! $subscription_identifier && isset( $payload['order_id'] ) ) {
            $subscription_identifier = sanitize_text_field( (string) $payload['order_id'] );
        }

        if ( $success ) {
            $valid_from  = $this->normalize_mysql_datetime(
                $decoded['key']['valid_from']
                    ?? $decoded['valid_from']
                    ?? ( $payload['valid_from'] ?? null )
            );
            $valid_until = $this->normalize_mysql_datetime(
                $decoded['key']['valid_until']
                    ?? $decoded['key']['valid_to']
                    ?? $decoded['key']['expires_at']
                    ?? $decoded['key']['expires_on']
                    ?? $decoded['valid_until']
                    ?? $decoded['valid_to']
                    ?? $decoded['expires_at']
                    ?? $decoded['expires_on']
                    ?? ( $payload['valid_until'] ?? null )
            );
            $key_status = in_array( $status_value, [ 'active', 'disabled' ], true )
                ? $status_value
                : ( isset( $payload['event'] ) && in_array( $payload['event'], [ 'cancelled', 'disabled' ], true ) ? 'disabled' : 'active' );

            $this->db->upsert_key(
                [
                    'subscription_id' => $subscription_identifier,
                    'customer_email'  => sanitize_email( $payload['customer_email'] ?? '' ),
                    'wp_user_id'      => isset( $payload['wp_user_id'] ) ? absint( $payload['wp_user_id'] ) : null,
                    'customer_name'   => isset( $payload['customer_name'] ) ? sanitize_text_field( $payload['customer_name'] ) : null,
                    'subscription_status' => isset( $payload['subscription_status'] ) ? sanitize_text_field( $payload['subscription_status'] ) : null,
                    'plan_slug'       => sanitize_text_field( $payload['plan_slug'] ?? '' ),
                    'status'          => $key_status,
                    'key_prefix'      => isset( $decoded['key'] ) && is_string( $decoded['key'] ) ? substr( $decoded['key'], 0, 10 ) : ( $decoded['key_prefix'] ?? null ),
                    'key_last4'       => isset( $decoded['key'] ) && is_string( $decoded['key'] ) ? substr( $decoded['key'], -4 ) : ( $decoded['key_last4'] ?? null ),
                    'valid_from'      => $valid_from,
                    'valid_until'     => $valid_until,
                    'node_plan_id'    => $decoded['plan_id'] ?? null,
                    'last_action'     => $decoded['action'] ?? null,
                    'last_http_code'  => $code,
                    'last_error'      => null,
                ]
            );

            if ( isset( $payload['event'] ) && in_array( $payload['event'], [ 'activated', 'renewed', 'reactivated', 'active' ], true ) ) {
                $this->db->upsert_user(
                    [
                        'wp_user_id'      => isset( $payload['wp_user_id'] ) ? absint( $payload['wp_user_id'] ) : 0,
                        'customer_email'  => sanitize_email( $payload['customer_email'] ?? '' ),
                        'subscription_id' => $subscription_identifier,
                        'order_id'        => isset( $payload['order_id'] ) ? absint( $payload['order_id'] ) : null,
                        'product_id'      => isset( $payload['product_id'] ) ? absint( $payload['product_id'] ) : null,
                        'plan_slug'       => sanitize_text_field( $payload['plan_slug'] ?? '' ),
                        'status'          => isset( $payload['event'] ) && in_array( $payload['event'], [ 'cancelled', 'disabled' ], true ) ? 'disabled' : 'active',
                        'valid_from'      => $valid_from,
                        'valid_until'     => $valid_until,
                        'source'          => 'subscription_event',
                        'last_sync_at'    => current_time( 'mysql', true ),
                    ]
                );

                if ( $settings['enable_logging'] ) {
                    $this->db->log_event(
                        [
                            'event'           => 'upgrade_debug',
                            'customer_email'  => $payload['customer_email'] ?? '',
                            'plan_slug'       => $payload['plan_slug'] ?? '',
                            'subscription_id' => $subscription_identifier,
                            'order_id'        => $payload['order_id'] ?? '',
                            'response_action' => $decoded['action'] ?? null,
                            'http_code'       => $code,
                            'error_excerpt'   => 'truth_table_upserted',
                        ]
                    );
                }
            }
        } elseif ( $settings['enable_logging'] ) {
            if ( 200 === $code && ! is_array( $decoded ) ) {
                dsb_log(
                    'error',
                    'Node response invalid JSON; key untouched',
                    [
                        'subscription_id' => $subscription_identifier,
                        'http_code'       => $code,
                    ]
                );
            } else {
                $this->db->upsert_key(
                    [
                        'subscription_id' => $subscription_identifier,
                        'customer_email'  => sanitize_email( $payload['customer_email'] ?? '' ),
                        'wp_user_id'      => isset( $payload['wp_user_id'] ) ? absint( $payload['wp_user_id'] ) : null,
                        'customer_name'   => isset( $payload['customer_name'] ) ? sanitize_text_field( $payload['customer_name'] ) : null,
                        'subscription_status' => isset( $payload['subscription_status'] ) ? sanitize_text_field( $payload['subscription_status'] ) : null,
                        'plan_slug'       => sanitize_text_field( $payload['plan_slug'] ?? '' ),
                        'status'          => 'error',
                        'last_action'     => $payload['event'] ?? '',
                        'last_http_code'  => $code,
                        'last_error'      => is_wp_error( $response ) ? $response->get_error_message() : ( is_array( $decoded ) ? ( $decoded['status'] ?? '' ) : ( $body ? substr( $body, 0, 200 ) : '' ) ),
                    ]
                );
            }
        }

        return [
            'response' => $response,
            'decoded'  => $decoded,
            'code'     => $code,
        ];
    }

    public function test_connection(): array {
        $response = $this->request( '/internal/subscription/debug', 'GET' );
        $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        $decoded  = ! is_wp_error( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;

        return [
            'response' => $response,
            'decoded'  => $decoded,
            'code'     => $code,
        ];
    }

    public function fetch_wps_subscriptions_all() {
        $settings = $this->get_settings();
        $secret   = $settings['wps_rest_consumer_secret'] ?? '';

        if ( ! $secret ) {
            return new \WP_Error( 'dsb_missing_wps_secret', __( 'WPS consumer secret missing', 'davix-sub-bridge' ) );
        }

        $url = add_query_arg( 'consumer_secret', rawurlencode( $secret ), home_url( '/wp-json/wsp-route/v1/wsp-view-subscription' ) );

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body    = wp_remote_retrieve_body( $response );
        $decoded = $body ? json_decode( $body, true ) : null;

        if ( ! is_array( $decoded ) ) {
            return new \WP_Error( 'dsb_wps_invalid_body', __( 'Unexpected WPS response', 'davix-sub-bridge' ) );
        }

        $status = $decoded['status'] ?? ( $decoded['success'] ?? '' );
        $data   = $decoded['data'] ?? [];

        if ( is_array( $data ) && ( 'success' === $status || true === $status ) ) {
            dsb_log( 'info', 'Fetched WPS subscriptions', [ 'count' => count( $data ), 'status' => $status ? 'success' : 'unknown' ] );
            return $data;
        }

        return new \WP_Error( 'dsb_wps_invalid_status', __( 'WPS subscription fetch failed', 'davix-sub-bridge' ) );
    }

    public function fetch_keys( int $page = 1, int $per_page = 20, string $search = '' ) {
        return $this->request(
            '/internal/admin/keys',
            'GET',
            [],
            [
                'page'     => max( 1, $page ),
                'per_page' => max( 1, $per_page ),
                'search'   => $search,
            ]
        );
    }

    public function fetch_node_export( int $page, int $per_page ): array {
        $page     = max( 1, $page );
        $per_page = max( 1, min( 500, $per_page ) );

        $response = $this->request( '/internal/admin/keys/export', 'GET', null, [
            'page'     => $page,
            'per_page' => $per_page,
        ] );

        return $this->prepare_response( $response );
    }

    public function provision_key( array $payload ) {
        return $this->request( '/internal/admin/key/provision', 'POST', $payload );
    }

    public function disable_key( array $payload ) {
        return $this->request( '/internal/admin/key/disable', 'POST', $payload );
    }

    public function rotate_key( array $payload ) {
        return $this->request( '/internal/admin/key/rotate', 'POST', $payload );
    }

    public function user_summary( array $identity ): array {
        return $this->post_internal( '/internal/user/summary', $identity );
    }

    public function user_usage( array $identity, string $range, array $opts = [] ): array {
        $payload  = array_merge( $identity, [ 'range' => $range ], $opts );
        return $this->post_internal( '/internal/user/usage', $payload );
    }

    public function user_rotate( array $identity ): array {
        return $this->post_internal( '/internal/user/key/rotate', $identity );
    }

    public function user_toggle( array $identity, bool $enabled ): array {
        $action  = $enabled ? 'enable' : 'disable';
        $payload = array_merge( $identity, [ 'action' => $action ] );
        return $this->post_internal( '/internal/user/key/toggle', $payload );
    }

    public function user_logs( array $identity, int $page, int $per_page, array $filters = [] ): array {
        $payload = array_merge( $identity, [
            'page'     => $page,
            'per_page' => $per_page,
        ], $filters );

        return $this->post_internal( '/internal/user/logs', $payload );
    }

    public function fetch_user_summary( array $payload ): array {
        return $this->post_internal( '/internal/user/summary', $payload );
    }

    public function rotate_user_key( array $payload ): array {
        return $this->post_internal( '/internal/user/key/rotate', $payload );
    }

    public function purge_user_on_node( array $payload ): array {
        if ( isset( $payload['plan_slug'] ) ) {
            $payload['plan_slug'] = dsb_normalize_plan_slug( $payload['plan_slug'] );
        }

        return $this->post_internal( '/internal/user/purge', $payload );
    }

    public function fetch_plans() {
        return $this->request( '/internal/admin/plans', 'GET' );
    }

    public function fetch_request_log_diagnostics(): array {
        $response = $this->request( '/internal/admin/diagnostics/request-log', 'GET', null );
        return $this->prepare_response( $response );
    }

    public function sync_plan( array $payload ) {
        if ( isset( $payload['plan_slug'] ) ) {
            $payload['plan_slug'] = dsb_normalize_plan_slug( $payload['plan_slug'] );
        }
        return $this->request( '/internal/wp-sync/plan', 'POST', $payload );
    }

    public function save_plan_sync_status( array $status ): void {
        update_option( self::OPTION_PLAN_SYNC, $status );
    }

    protected function prepare_response( $response ): array {
        $body    = is_wp_error( $response ) ? null : wp_remote_retrieve_body( $response );
        $decoded = $body ? json_decode( $body, true ) : null;
        $code    = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        $url     = '';
        $method  = '';

        if ( is_array( $response ) ) {
            $url    = $response['__dsb_request_url'] ?? '';
            $method = $response['__dsb_request_method'] ?? '';
        } elseif ( is_wp_error( $response ) ) {
            $error_data = $response->get_error_data();
            $url        = is_array( $error_data ) && isset( $error_data['dsb_url'] ) ? $error_data['dsb_url'] : '';
            $method     = is_array( $error_data ) && isset( $error_data['dsb_method'] ) ? $error_data['dsb_method'] : '';
        }

        return [
            'response' => $response,
            'decoded'  => $decoded,
            'code'     => $code,
            'url'      => $url,
            'method'   => $method,
        ];
    }

    protected function normalize_mysql_datetime( $value ): ?string {
        if ( null === $value || '' === $value ) {
            return null;
        }

        try {
            if ( is_numeric( $value ) ) {
                $dt = new \DateTimeImmutable( '@' . (int) $value );
                return $dt->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
            }

            if ( is_array( $value ) ) {
                $value = reset( $value );
            }

            $dt = new \DateTimeImmutable( is_string( $value ) ? $value : '' );
            return $dt->format( 'Y-m-d H:i:s' );
        } catch ( \Throwable $e ) {
            return null;
        }
    }
}
