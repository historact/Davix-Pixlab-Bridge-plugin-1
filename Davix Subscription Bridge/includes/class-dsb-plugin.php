<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Plugin {
    protected static $instance;
    protected $db;
    protected $client;
    protected $admin;
    protected $events;
    protected $resync;
    protected $node_poll;
    protected $purge_worker;
    protected $dashboard;
    protected $dashboard_ajax;

    public static function instance(): self {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate(): void {
        if ( ! self::dependencies_met() ) {
            deactivate_plugins( plugin_basename( DSB_PLUGIN_FILE ) );
            wp_die( esc_html__( 'Davix Subscription Bridge requires WooCommerce and Subscriptions for WooCommerce.', 'davix-sub-bridge' ) );
        }
        $db = new DSB_DB( $GLOBALS['wpdb'] );
        $db->migrate();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( \Davix\SubscriptionBridge\DSB_Purge_Worker::CRON_HOOK );
        wp_clear_scheduled_hook( \Davix\SubscriptionBridge\DSB_Node_Poll::CRON_HOOK );
        wp_clear_scheduled_hook( \Davix\SubscriptionBridge\DSB_Resync::CRON_HOOK );
    }

    public static function uninstall(): void {
        if ( get_option( DSB_DB::OPTION_DELETE_ON_UNINSTALL ) ) {
            $db = new DSB_DB( $GLOBALS['wpdb'] );
            $db->drop_tables();
            wp_clear_scheduled_hook( \Davix\SubscriptionBridge\DSB_Purge_Worker::CRON_HOOK );
            wp_clear_scheduled_hook( \Davix\SubscriptionBridge\DSB_Node_Poll::CRON_HOOK );
            delete_option( DSB_DB::OPTION_DELETE_ON_UNINSTALL );
            delete_option( DSB_DB::OPTION_DB_VERSION );
            delete_option( DSB_Client::OPTION_SETTINGS );
            delete_option( DSB_Client::OPTION_PRODUCT_PLANS );
            delete_option( DSB_Client::OPTION_PLAN_PRODUCTS );
            delete_option( DSB_Client::OPTION_PLAN_SYNC );
            delete_option( DSB_Resync::OPTION_LOCK_UNTIL );
            delete_option( DSB_Resync::OPTION_LAST_RUN_AT );
            delete_option( DSB_Resync::OPTION_LAST_RESULT );
            delete_option( DSB_Resync::OPTION_LAST_ERROR );
            delete_option( DSB_Node_Poll::OPTION_LOCK_UNTIL );
            delete_option( DSB_Node_Poll::OPTION_LAST_RUN_AT );
            delete_option( DSB_Node_Poll::OPTION_LAST_RESULT );
            delete_option( DSB_Node_Poll::OPTION_LAST_ERROR );
            delete_option( DSB_Node_Poll::OPTION_LAST_DURATION_MS );
            delete_option( DSB_Purge_Worker::OPTION_LOCK_UNTIL );
            delete_option( DSB_Purge_Worker::OPTION_LAST_RUN_AT );
            delete_option( DSB_Purge_Worker::OPTION_LAST_RESULT );
            delete_option( DSB_Purge_Worker::OPTION_LAST_ERROR );
            delete_option( DSB_Purge_Worker::OPTION_LAST_DURATION_MS );
            delete_option( DSB_Purge_Worker::OPTION_LAST_PROCESSED );
            delete_option( DSB_Resync::OPTION_LAST_DURATION );
            delete_option( DSB_Cron_Alerts::OPTION_STATE );
        }
    }

    protected static function dependencies_met(): bool {
        return class_exists( 'WooCommerce' ) && ( class_exists( '\\WPS_Subscriptions_For_Woocommerce' ) || defined( 'SUBSCRIPTIONS_FOR_WOOCOMMERCE_VERSION' ) );
    }

    public function __construct() {
        if ( ! self::dependencies_met() ) {
            add_action( 'admin_notices', [ $this, 'dependency_notice' ] );
            return;
        }

        $this->db        = new DSB_DB( $GLOBALS['wpdb'] );
        $this->client    = new DSB_Client( $this->db );
        $this->events    = new DSB_Events( $this->client, $this->db );
        $this->resync    = new DSB_Resync( $this->client, $this->db );
        $this->node_poll = new DSB_Node_Poll( $this->client, $this->db );
        $this->purge_worker = new DSB_Purge_Worker( $this->client, $this->db );
        $this->admin           = new DSB_Admin( $this->client, $this->db, $this->events, $this->resync, $this->purge_worker, $this->node_poll );
        $this->dashboard       = new DSB_Dashboard( $this->client );
        $this->dashboard_ajax  = new DSB_Dashboard_Ajax( $this->client );

        DSB_User_Purger::register( $this->db, $this->purge_worker );

        add_action( 'user_register', [ $this, 'handle_user_register_free' ], 20, 1 );

        add_action( 'init', [ $this, 'init' ] );
    }

    public function init(): void {
        load_plugin_textdomain( 'davix-sub-bridge', false, dirname( plugin_basename( DSB_PLUGIN_FILE ) ) . '/languages' );
        $this->db->migrate();
        $this->admin->init();
        $this->events->init();
        $this->resync->init();
        $this->node_poll->init();
        $this->purge_worker->init();
        $this->dashboard->init();
        $this->dashboard_ajax->init();
    }

    public function dependency_notice(): void {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Davix Subscription Bridge requires WooCommerce and WPSwings Subscriptions for WooCommerce to be active.', 'davix-sub-bridge' ) . '</p></div>';
    }

    public function handle_user_register_free( int $user_id ): void {
        $user = get_userdata( $user_id );

        if ( ! $user instanceof \WP_User ) {
            return;
        }

        $email = $user->user_email ? sanitize_email( $user->user_email ) : '';

        if ( ! $email ) {
            dsb_log( 'warning', 'Skipping free provisioning for user without email', [ 'user_id' => $user_id ] );
            return;
        }

        $plan_slug       = dsb_normalize_plan_slug( 'free' );
        $now_mysql       = current_time( 'mysql', true );
        $subscription_id = 'free-' . $user_id;

        $name = trim( (string) ( $user->first_name ?? '' ) . ' ' . ( $user->last_name ?? '' ) );
        if ( ! $name ) {
            $name = $user->display_name ?? '';
        }
        $customer_name = $name ? sanitize_text_field( $name ) : '';

        $payload = [
            'customer_email' => $email,
            'plan_slug'      => $plan_slug,
            'wp_user_id'     => $user_id,
        ];

        if ( $customer_name ) {
            $payload['customer_name'] = $customer_name;
        }

        dsb_log(
            'debug',
            'Free provision payload sent',
            [
                'user_id' => $user_id,
                'email'   => $email,
            ]
        );

        $response    = $this->client->provision_key( $payload );
        $code        = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        $decoded     = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
        $status_ok   = ! is_wp_error( $response ) && $code >= 200 && $code < 300 && is_array( $decoded ) && ( $decoded['status'] ?? '' ) === 'ok';
        $valid_from  = $this->normalize_mysql_datetime( $decoded['key']['valid_from'] ?? $decoded['valid_from'] ?? $now_mysql ) ?? $now_mysql;
        $valid_until = $this->normalize_mysql_datetime(
            $decoded['key']['valid_until']
                ?? $decoded['key']['valid_to']
                ?? $decoded['key']['expires_at']
                ?? $decoded['key']['expires_on']
                ?? $decoded['valid_until']
                ?? $decoded['valid_to']
                ?? $decoded['expires_at']
                ?? $decoded['expires_on']
                ?? null
        );

        $this->db->upsert_key(
            [
                'subscription_id'     => $subscription_id,
                'customer_email'      => $email,
                'wp_user_id'          => $user_id,
                'plan_slug'           => $plan_slug,
                'status'              => $status_ok ? 'active' : 'error',
                'subscription_status' => $status_ok ? 'active' : 'pending',
                'key_prefix'          => isset( $decoded['key'] ) && is_string( $decoded['key'] ) ? substr( $decoded['key'], 0, 10 ) : ( $decoded['key_prefix'] ?? null ),
                'key_last4'           => isset( $decoded['key'] ) && is_string( $decoded['key'] ) ? substr( $decoded['key'], -4 ) : ( $decoded['key_last4'] ?? null ),
                'valid_from'          => $valid_from,
                'valid_until'         => $valid_until,
                'node_plan_id'        => $decoded['plan_id'] ?? null,
                'last_action'         => 'auto_free_register',
                'last_http_code'      => $code ?: null,
                'last_error'          => $status_ok ? null : ( is_wp_error( $response ) ? $response->get_error_message() : ( $decoded['status'] ?? wp_remote_retrieve_body( $response ) ) ),
            ]
        );

        $this->db->upsert_user(
            [
                'wp_user_id'      => $user_id,
                'customer_email'  => $email,
                'subscription_id' => null,
                'order_id'        => null,
                'product_id'      => null,
                'plan_slug'       => $plan_slug,
                'status'          => 'active',
                'valid_from'      => $valid_from,
                'valid_until'     => null,
                'source'          => 'auto_free_register',
                'last_sync_at'    => $now_mysql,
            ]
        );

        $settings = $this->client->get_settings();
        if ( ! empty( $settings['enable_logging'] ) ) {
            $this->db->log_event(
                [
                    'event'           => 'free_user_register',
                    'customer_email'  => $email,
                    'plan_slug'       => $plan_slug,
                    'subscription_id' => $subscription_id,
                    'response_action' => $decoded['action'] ?? null,
                    'http_code'       => $code,
                    'error_excerpt'   => is_wp_error( $response ) ? $response->get_error_message() : ( $decoded['status'] ?? '' ),
                ]
            );
        }

        if ( $status_ok ) {
            dsb_log( 'info', 'Provisioned free plan on user registration', [ 'user_id' => $user_id, 'email' => $email ] );
        } else {
            dsb_log(
                'error',
                'Provisioning free plan on user registration failed',
                [
                    'user_id' => $user_id,
                    'email'   => $email,
                    'code'    => $code,
                    'body'    => is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response ),
                ]
            );
        }
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
