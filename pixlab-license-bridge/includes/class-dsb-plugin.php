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
    protected $provision_worker;
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
            wp_die( esc_html__( 'PixLab License Bridge requires Paid Memberships Pro to be active.', 'pixlab-license-bridge' ) );
        }
        $db = new DSB_DB( $GLOBALS['wpdb'] );
        $db->migrate();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( \Davix\SubscriptionBridge\DSB_Purge_Worker::CRON_HOOK );
        wp_clear_scheduled_hook( \Davix\SubscriptionBridge\DSB_Provision_Worker::CRON_HOOK );
        wp_clear_scheduled_hook( \Davix\SubscriptionBridge\DSB_Node_Poll::CRON_HOOK );
        wp_clear_scheduled_hook( \Davix\SubscriptionBridge\DSB_Resync::CRON_HOOK );
    }

    public static function uninstall(): void {
        if ( get_option( DSB_DB::OPTION_DELETE_ON_UNINSTALL ) ) {
            $db = new DSB_DB( $GLOBALS['wpdb'] );
            $db->drop_tables();
            wp_clear_scheduled_hook( \Davix\SubscriptionBridge\DSB_Purge_Worker::CRON_HOOK );
            wp_clear_scheduled_hook( \Davix\SubscriptionBridge\DSB_Provision_Worker::CRON_HOOK );
            wp_clear_scheduled_hook( \Davix\SubscriptionBridge\DSB_Node_Poll::CRON_HOOK );
            delete_option( DSB_DB::OPTION_DELETE_ON_UNINSTALL );
            delete_option( DSB_DB::OPTION_DB_VERSION );
            delete_option( 'dsb_db_version' );
            delete_option( DSB_Client::OPTION_SETTINGS );
            delete_option( DSB_Client::OPTION_PRODUCT_PLANS );
            delete_option( DSB_Client::OPTION_PLAN_PRODUCTS );
            delete_option( DSB_Client::OPTION_LEVEL_PLANS );
            delete_option( DSB_Client::OPTION_PLAN_SYNC );
            delete_option( DSB_Resync::OPTION_LOCK_UNTIL );
            delete_option( DSB_Resync::OPTION_LAST_RUN_AT );
            delete_option( DSB_Resync::OPTION_LAST_RUN_TS );
            delete_option( DSB_Resync::OPTION_LAST_RESULT );
            delete_option( DSB_Resync::OPTION_LAST_ERROR );
            delete_option( DSB_Node_Poll::OPTION_LOCK_UNTIL );
            delete_option( DSB_Node_Poll::OPTION_LAST_RUN_AT );
            delete_option( DSB_Node_Poll::OPTION_LAST_RUN_TS );
            delete_option( DSB_Node_Poll::OPTION_LAST_RESULT );
            delete_option( DSB_Node_Poll::OPTION_LAST_ERROR );
            delete_option( DSB_Node_Poll::OPTION_LAST_DURATION_MS );
            delete_option( DSB_Node_Poll::OPTION_LAST_UNSTABLE );
            delete_option( DSB_Node_Poll::OPTION_STABLE_STREAK );
            delete_option( DSB_Purge_Worker::OPTION_LOCK_UNTIL );
            delete_option( DSB_Purge_Worker::OPTION_LAST_RUN_AT );
            delete_option( DSB_Purge_Worker::OPTION_LAST_RUN_TS );
            delete_option( DSB_Purge_Worker::OPTION_LAST_RESULT );
            delete_option( DSB_Purge_Worker::OPTION_LAST_ERROR );
            delete_option( DSB_Purge_Worker::OPTION_LAST_DURATION_MS );
            delete_option( DSB_Purge_Worker::OPTION_LAST_PROCESSED );
            delete_option( DSB_Provision_Worker::OPTION_LOCK_UNTIL );
            delete_option( DSB_Provision_Worker::OPTION_LAST_RUN_AT );
            delete_option( DSB_Provision_Worker::OPTION_LAST_RUN_TS );
            delete_option( DSB_Provision_Worker::OPTION_LAST_RESULT );
            delete_option( DSB_Provision_Worker::OPTION_LAST_ERROR );
            delete_option( DSB_Provision_Worker::OPTION_LAST_DURATION_MS );
            delete_option( DSB_Provision_Worker::OPTION_LAST_PROCESSED );
            delete_option( DSB_Resync::OPTION_LAST_DURATION );
            delete_option( DSB_Cron_Alerts::OPTION_STATE );
            delete_option( DSB_Cron_Alerts::OPTION_GENERIC_STATE );
            delete_option( DSB_DB::OPTION_TRIGGERS_STATUS );
            delete_option( 'dsb_log_dir_path' );
            delete_option( 'dsb_log_upload_token' );
        }
    }

    protected static function dependencies_met(): bool {
        return function_exists( 'pmpro_getMembershipLevelForUser' ) || class_exists( '\\MemberOrder' );
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
        $this->provision_worker = new DSB_Provision_Worker( $this->client, $this->db );
        $this->admin           = new DSB_Admin( $this->client, $this->db, $this->events, $this->resync, $this->purge_worker, $this->provision_worker, $this->node_poll );
        $this->dashboard       = new DSB_Dashboard( $this->client );
        $this->dashboard_ajax  = new DSB_Dashboard_Ajax( $this->client, $this->db );

        DSB_User_Purger::register( $this->db, $this->purge_worker );

        add_action( 'user_register', [ $this, 'handle_user_register_free' ], 20, 1 );

        add_action( 'init', [ $this, 'init' ] );
    }

    public function init(): void {
        load_plugin_textdomain( 'pixlab-license-bridge', false, dirname( plugin_basename( DSB_PLUGIN_FILE ) ) . '/languages' );
        $this->db->migrate();
        $this->admin->init();
        if ( function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
            require_once DSB_PLUGIN_DIR . 'includes/class-dsb-pmpro-events.php';
            DSB_PMPro_Events::init( $this->client, $this->db );
        }
        $this->resync->init();
        $this->node_poll->init();
        $this->purge_worker->init();
        $this->provision_worker->init();
        $this->dashboard->init();
        $this->dashboard_ajax->init();
    }

    public function dependency_notice(): void {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'PixLab License Bridge requires Paid Memberships Pro to be active.', 'pixlab-license-bridge' ) . '</p></div>';
    }

    public function handle_user_register_free( int $user_id ): void {
        $user = get_userdata( $user_id );

        if ( ! $user instanceof \WP_User ) {
            return;
        }

        if ( ! function_exists( 'pmpro_changeMembershipLevel' ) ) {
            dsb_log( 'warning', 'PMPro not active; cannot assign free membership on signup', [ 'user_id' => $user_id ] );
            return;
        }

        $email = $user->user_email ? sanitize_email( $user->user_email ) : '';

        if ( ! $email ) {
            dsb_log( 'warning', 'Skipping free provisioning for user without email', [ 'user_id' => $user_id ] );
            return;
        }

        $free_level_id = $this->find_free_level_id();
        if ( ! $free_level_id ) {
            dsb_log( 'warning', 'No free PMPro level configured; skipping free assignment', [ 'user_id' => $user_id ] );
            return;
        }

        $current_level = function_exists( 'pmpro_getMembershipLevelForUser' ) ? pmpro_getMembershipLevelForUser( $user_id ) : null;
        if ( ! $current_level || ( (int) $current_level->id !== (int) $free_level_id ) ) {
            pmpro_changeMembershipLevel( (int) $free_level_id, $user_id );
        }

        $plan_slug       = $this->client->plan_slug_for_level( (int) $free_level_id ) ?: dsb_normalize_plan_slug( 'free' );
        $subscription_id = 'pmpro-' . $user_id . '-' . (int) $free_level_id;

        $name = trim( (string) ( $user->first_name ?? '' ) . ' ' . ( $user->last_name ?? '' ) );
        if ( ! $name ) {
            $name = $user->display_name ?? '';
        }
        $customer_name = $name ? sanitize_text_field( $name ) : '';

        $payload = [
            'event'               => 'activated',
            'customer_email'      => strtolower( $email ),
            'plan_slug'           => $plan_slug,
            'wp_user_id'          => $user_id,
            'customer_name'       => $customer_name,
            'subscription_id'     => $subscription_id,
            'product_id'          => (int) $free_level_id,
            'subscription_status' => 'active',
            'valid_from'          => gmdate( 'c' ),
        ];

        $valid_until = $this->pmpro_level_valid_until( $user_id );
        if ( $valid_until ) {
            $payload['valid_until'] = $valid_until;
        }

        $payload['event_id'] = DSB_Util::event_id_from_payload( $payload );
        $this->db->enqueue_provision_job( $payload );

        dsb_log( 'info', 'Provisioned free PMPro level on user registration', [ 'user_id' => $user_id, 'email' => $email, 'level_id' => $free_level_id ] );
    }

    protected function find_free_level_id(): int {
        $settings = $this->client->get_settings();
        $configured = isset( $settings['free_level_id'] ) ? absint( $settings['free_level_id'] ) : 0;
        if ( $configured > 0 ) {
            return $configured;
        }

        if ( function_exists( 'pmpro_getAllLevels' ) ) {
            $levels = pmpro_getAllLevels( true, true );
            if ( is_array( $levels ) ) {
                foreach ( $levels as $level ) {
                    $level_id = isset( $level->id ) ? (int) $level->id : 0;
                    if ( ! $level_id ) {
                        continue;
                    }
                    $meta = get_option( 'dsb_level_meta_' . $level_id, [] );
                    if ( is_array( $meta ) && ! empty( $meta['is_free'] ) ) {
                        return $level_id;
                    }
                }
            }
        }

        return 0;
    }

    protected function pmpro_level_valid_until( int $user_id ): ?string {
        if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
            return null;
        }

        $level = pmpro_getMembershipLevelForUser( $user_id );
        if ( empty( $level ) || empty( $level->enddate ) ) {
            return null;
        }

        if ( is_numeric( $level->enddate ) ) {
            return gmdate( 'c', (int) $level->enddate );
        }

        try {
            $dt = new \DateTimeImmutable( is_string( $level->enddate ) ? $level->enddate : '' );
            return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'c' );
        } catch ( \Throwable $e ) {
            return null;
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
