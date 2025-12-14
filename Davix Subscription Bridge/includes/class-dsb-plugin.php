<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Plugin {
    protected static $instance;
    protected $db;
    protected $client;
    protected $admin;
    protected $events;
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
        $db->create_tables();
    }

    public static function uninstall(): void {
        if ( get_option( DSB_DB::OPTION_DELETE_ON_UNINSTALL ) ) {
            $db = new DSB_DB( $GLOBALS['wpdb'] );
            $db->drop_tables();
            delete_option( DSB_DB::OPTION_DELETE_ON_UNINSTALL );
            delete_option( DSB_Client::OPTION_SETTINGS );
            delete_option( DSB_Client::OPTION_PRODUCT_PLANS );
            delete_option( DSB_Client::OPTION_PLAN_PRODUCTS );
            delete_option( DSB_Client::OPTION_PLAN_SYNC );
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
        $this->admin           = new DSB_Admin( $this->client, $this->db, $this->events );
        $this->dashboard       = new DSB_Dashboard( $this->client );
        $this->dashboard_ajax  = new DSB_Dashboard_Ajax( $this->client );

        add_action( 'init', [ $this, 'init' ] );
    }

    public function init(): void {
        load_plugin_textdomain( 'davix-sub-bridge', false, dirname( plugin_basename( DSB_PLUGIN_FILE ) ) . '/languages' );
        $this->db->migrate();
        $this->admin->init();
        $this->events->init();
        $this->dashboard->init();
        $this->dashboard_ajax->init();
    }

    public function dependency_notice(): void {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Davix Subscription Bridge requires WooCommerce and WPSwings Subscriptions for WooCommerce to be active.', 'davix-sub-bridge' ) . '</p></div>';
    }
}
