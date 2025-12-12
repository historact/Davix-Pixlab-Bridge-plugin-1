<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\DSB_Admin' ) ) {

class DSB_Admin {
    protected $client;
    protected $db;
    protected $events;
    protected $notices = [];

    public function __construct( DSB_Client $client, DSB_DB $db, DSB_Events $events ) {
        $this->client = $client;
        $this->db     = $db;
        $this->events = $events;
    }

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_dsb_search_users', [ $this, 'ajax_search_users' ] );
        add_action( 'wp_ajax_dsb_search_subscriptions', [ $this, 'ajax_search_subscriptions' ] );
        add_action( 'wp_ajax_dsb_search_orders', [ $this, 'ajax_search_orders' ] );
    }

    public function register_menu(): void {
        add_menu_page(
            __( 'Davix Bridge', 'davix-sub-bridge' ),
            __( 'Davix Bridge', 'davix-sub-bridge' ),
            'manage_options',
            'davix-bridge',
            [ $this, 'render_page' ],
            'dashicons-admin-links'
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( 'toplevel_page_davix-bridge' !== $hook ) {
            return;
        }

        if ( wp_script_is( 'selectWoo', 'registered' ) ) {
            wp_enqueue_script( 'selectWoo' );
            wp_enqueue_style( 'select2' );
        } elseif ( wp_script_is( 'select2', 'registered' ) ) {
            wp_enqueue_script( 'select2' );
            wp_enqueue_style( 'select2' );
        }

        if ( function_exists( 'wc' ) ) {
            wp_enqueue_style( 'woocommerce_admin_styles' );
        }

        wp_register_script( 'dsb-admin', DSB_PLUGIN_URL . 'assets/js/dsb-admin.js', [ 'jquery' ], DSB_VERSION, true );
        wp_localize_script(
            'dsb-admin',
            'dsbAdminData',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'dsb_admin_ajax' ),
            ]
        );
        wp_enqueue_script( 'dsb-admin' );
    }

    protected function add_notice( string $message, string $type = 'success' ): void {
        $this->notices[] = [
            'message' => $message,
            'type'    => $type,
        ];
    }

    public function handle_actions(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['dsb_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_settings_nonce'] ) ), 'dsb_save_settings' ) ) {
            $this->client->save_settings( wp_unslash( $_POST ) );
            if ( isset( $_POST['dsb_plan_slug_meta'] ) && is_array( $_POST['dsb_plan_slug_meta'] ) ) {
                foreach ( $_POST['dsb_plan_slug_meta'] as $product_id => $slug ) {
                    $pid = absint( $product_id );
                    if ( $pid > 0 ) {
                        update_post_meta( $pid, '_dsb_plan_slug', sanitize_text_field( wp_unslash( $slug ) ) );
                    }
                }
            }
            $this->add_notice( __( 'Settings saved.', 'davix-sub-bridge' ) );
        }

        if ( isset( $_POST['dsb_test_connection'] ) && check_admin_referer( 'dsb_test_connection' ) ) {
            $result = $this->client->test_connection();
            if ( is_wp_error( $result['response'] ?? null ) ) {
                $this->add_notice( $result['response']->get_error_message(), 'error' );
            } elseif ( ( $result['code'] ?? 0 ) >= 200 && ( $result['code'] ?? 0 ) < 300 ) {
                $this->add_notice( __( 'Connection successful.', 'davix-sub-bridge' ) );
            } else {
                $this->add_notice( __( 'Connection failed. Check URL/token.', 'davix-sub-bridge' ), 'error' );
            }
        }

        if ( 'plan-mapping' === $tab && isset( $_POST['dsb_plans_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_plans_nonce'] ) ), 'dsb_save_plans' ) ) {
            $plans      = [];
            $ids        = isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ? array_values( $_POST['product_ids'] ) : [];
            $slugs      = isset( $_POST['plan_slugs'] ) && is_array( $_POST['plan_slugs'] ) ? array_values( $_POST['plan_slugs'] ) : [];
            $pair_count = min( count( $ids ), count( $slugs ) );
            for ( $i = 0; $i < $pair_count; $i ++ ) {
                $pid  = sanitize_text_field( $ids[ $i ] );
                $slug = sanitize_text_field( $slugs[ $i ] );
                if ( '' !== $pid && '' !== $slug ) {
                    $plans[ $pid ] = $slug;
                }
            }

            $settings = $this->client->get_settings();
            $this->client->save_settings( [
                'product_plans' => $plans,
                'node_base_url' => $settings['node_base_url'],
                'bridge_token'  => $settings['bridge_token'],
                'enable_logging'=> $settings['enable_logging'],
                'delete_data'   => $settings['delete_data'],
                'plan_products' => $this->client->get_plan_products(),
            ] );
            $this->add_notice( __( 'Plan mappings saved.', 'davix-sub-bridge' ) );
        }

        if ( 'keys' === $tab ) {
            $this->handle_key_actions();
        }

        if ( isset( $_POST['dsb_sync_plans'] ) && check_admin_referer( 'dsb_sync_plans' ) ) {
            $summary = $this->sync_plans_to_node();
            $message = sprintf(
                /* translators: 1: success count, 2: failure count */
                esc_html__( 'Plan sync completed. Success: %1$d, Failed: %2$d', 'davix-sub-bridge' ),
                isset( $summary['count_success'] ) ? (int) $summary['count_success'] : 0,
                isset( $summary['count_failed'] ) ? (int) $summary['count_failed'] : 0
            );
            $this->add_notice( $message, isset( $summary['count_failed'] ) && $summary['count_failed'] > 0 ? 'error' : 'success' );
        }
    }

    protected function handle_key_actions(): void {
        if ( isset( $_POST['dsb_manual_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_manual_nonce'] ) ), 'dsb_manual_key' ) ) {
            $user_id        = isset( $_POST['customer_user_id'] ) ? absint( $_POST['customer_user_id'] ) : 0;
            $email          = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
            if ( ! $email && $user_id ) {
                $user = get_userdata( $user_id );
                if ( $user ) {
                    $email = $user->user_email;
                }
            }
            $plan_slug      = isset( $_POST['plan_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_slug'] ) ) : '';
            $subscriptionId = isset( $_POST['subscription_id'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription_id'] ) ) : '';
            $order_id       = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';

            if ( ! $email || ! $plan_slug || ( '' === $subscriptionId && '' === $order_id ) ) {
                $this->add_notice( __( 'Customer, plan, and subscription or order are required.', 'davix-sub-bridge' ), 'error' );
                return;
            }
            $response       = $this->client->provision_key(
                [
                    'customer_email'  => $email,
                    'plan_slug'       => $plan_slug,
                    'subscription_id' => $subscriptionId,
                    'order_id'        => $order_id,
                ]
            );
            $this->handle_key_response( $response, __( 'Provisioned', 'davix-sub-bridge' ) );
        }

        if ( isset( $_POST['dsb_key_action_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_key_action_nonce'] ) ), 'dsb_key_action' ) ) {
            $action          = isset( $_POST['dsb_action'] ) ? sanitize_key( wp_unslash( $_POST['dsb_action'] ) ) : '';
            $subscription_id = isset( $_POST['subscription_id'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription_id'] ) ) : '';
            $customer_email  = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';

            if ( 'disable' === $action ) {
                $response = $this->client->disable_key(
                    [
                        'subscription_id' => $subscription_id,
                        'customer_email'  => $customer_email,
                    ]
                );
                $this->handle_key_response( $response, __( 'Key disabled.', 'davix-sub-bridge' ) );
            } elseif ( 'rotate' === $action ) {
                $response = $this->client->rotate_key(
                    [
                        'subscription_id' => $subscription_id,
                        'customer_email'  => $customer_email,
                    ]
                );
                $this->handle_key_response( $response, __( 'Key rotated.', 'davix-sub-bridge' ) );
            }
        }
    }

    protected function handle_key_response( $response, string $success_message ): void {
        if ( is_wp_error( $response ) ) {
            $this->add_notice( $response->get_error_message(), 'error' );
            return;
        }
        $code    = wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 200 && $code < 300 && is_array( $decoded ) && ( $decoded['status'] ?? '' ) === 'ok' ) {
            $message = $success_message;
            if ( ! empty( $decoded['key'] ) ) {
                $message .= ' ' . __( 'Copy now:', 'davix-sub-bridge' ) . ' ' . sanitize_text_field( $decoded['key'] );
            }
            $this->add_notice( $message );
        } else {
            $this->add_notice( __( 'Request failed', 'davix-sub-bridge' ) . ' ' . wp_json_encode( $decoded ), 'error' );
        }
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

        echo '<div class="wrap"><h1>Davix Subscription Bridge</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        $tabs = [
            'settings'     => __( 'Settings', 'davix-sub-bridge' ),
            'plan-mapping' => __( 'Plan Mapping', 'davix-sub-bridge' ),
            'keys'         => __( 'Keys', 'davix-sub-bridge' ),
            'logs'         => __( 'Logs', 'davix-sub-bridge' ),
        ];
        foreach ( $tabs as $key => $label ) {
            $class = $tab === $key ? 'nav-tab nav-tab-active' : 'nav-tab';
            printf( '<a href="%s" class="%s">%s</a>', esc_url( add_query_arg( [ 'page' => 'davix-bridge', 'tab' => $key ], admin_url( 'admin.php' ) ) ), esc_attr( $class ), esc_html( $label ) );
        }
        echo '</h2>';

        foreach ( $this->notices as $notice ) {
            printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ), esc_html( $notice['message'] ) );
        }

        if ( 'plan-mapping' === $tab ) {
            $this->render_plan_tab();
        } elseif ( 'keys' === $tab ) {
            $this->render_keys_tab();
        } elseif ( 'logs' === $tab ) {
            $this->render_logs_tab();
        } else {
            $this->render_settings_tab();
        }

        echo '</div>';
    }

    protected function render_settings_tab(): void {
        $settings = $this->client->get_settings();
        $plan_products = $this->client->get_plan_products();
        $plan_candidates = $this->discover_plan_products();
        $plan_sync = $this->client->get_plan_sync_status();
        ?>
        <form method="post">
            <?php wp_nonce_field( 'dsb_save_settings', 'dsb_settings_nonce' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Node Base URL', 'davix-sub-bridge' ); ?></th>
                    <td><input type="url" name="node_base_url" class="regular-text" value="<?php echo esc_attr( $settings['node_base_url'] ); ?>" placeholder="https://pixlab.davix.dev" required /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Bridge Token', 'davix-sub-bridge' ); ?></th>
                    <td>
                        <input type="password" name="bridge_token" class="regular-text" value="<?php echo esc_attr( $settings['bridge_token'] ); ?>" autocomplete="off" />
                        <p class="description"><?php printf( '%s %s', esc_html__( 'Stored securely, masked in UI.', 'davix-sub-bridge' ), esc_html( $this->client->masked_token() ) ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Enable logging', 'davix-sub-bridge' ); ?></th>
                    <td><label><input type="checkbox" name="enable_logging" value="1" <?php checked( $settings['enable_logging'], 1 ); ?> /> <?php esc_html_e( 'Store last 200 events', 'davix-sub-bridge' ); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Delete data on uninstall', 'davix-sub-bridge' ); ?></th>
                    <td><label><input type="checkbox" name="delete_data" value="1" <?php checked( $settings['delete_data'], 1 ); ?> /> <?php esc_html_e( 'Drop plugin tables/options on uninstall', 'davix-sub-bridge' ); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Plan products', 'davix-sub-bridge' ); ?></th>
                    <td>
                        <p class="description"><?php esc_html_e( 'Select which WooCommerce products should sync to Node as plans (auto-detected subscription products plus manual selection).', 'davix-sub-bridge' ); ?></p>
                        <table class="widefat">
                            <thead><tr><th><?php esc_html_e( 'Sync', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Product', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Plan Slug override', 'davix-sub-bridge' ); ?></th></tr></thead>
                            <tbody>
                            <?php if ( empty( $plan_candidates ) ) : ?>
                                <tr><td colspan="3"><?php esc_html_e( 'No subscription-like products found. Use checkboxes after creating products.', 'davix-sub-bridge' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $plan_candidates as $product ) : ?>
                                    <?php $pid = $product->get_id();
                                    $checked = in_array( $pid, $plan_products, true );
                                    $plan_slug_meta = get_post_meta( $pid, '_dsb_plan_slug', true );
                                    ?>
                                    <tr>
                                        <td><input type="checkbox" name="plan_products[]" value="<?php echo esc_attr( $pid ); ?>" <?php checked( $checked ); ?> /></td>
                                        <td><?php echo esc_html( $product->get_name() ); ?> (<?php echo esc_html( $product->get_type() ); ?>) — #<?php echo esc_html( $pid ); ?></td>
                                        <td><input type="text" name="dsb_plan_slug_meta[<?php echo esc_attr( $pid ); ?>]" value="<?php echo esc_attr( $plan_slug_meta ); ?>" placeholder="custom-plan-slug" /></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <form method="post" style="margin-top:20px;">
            <?php wp_nonce_field( 'dsb_test_connection' ); ?>
            <?php submit_button( __( 'Test Connection', 'davix-sub-bridge' ), 'secondary', 'dsb_test_connection', false ); ?>
        </form>

        <form method="post" style="margin-top:20px;">
            <?php wp_nonce_field( 'dsb_sync_plans' ); ?>
            <?php submit_button( __( 'Sync Plans to Node', 'davix-sub-bridge' ), 'primary', 'dsb_sync_plans', false ); ?>
            <?php if ( ! empty( $plan_sync ) ) : ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: 1: timestamp, 2: success count, 3: failure count */
                        esc_html__( 'Last sync: %1$s — Success: %2$d, Failed: %3$d', 'davix-sub-bridge' ),
                        esc_html( $plan_sync['timestamp'] ?? '' ),
                        (int) ( $plan_sync['count_success'] ?? 0 ),
                        (int) ( $plan_sync['count_failed'] ?? 0 )
                    );
                    if ( ! empty( $plan_sync['errors'] ) && is_array( $plan_sync['errors'] ) ) {
                        echo '<br />' . esc_html__( 'Errors:', 'davix-sub-bridge' ) . ' ' . esc_html( implode( '; ', $plan_sync['errors'] ) );
                    }
                    ?>
                </p>
            <?php endif; ?>
        </form>
        <?php
    }

    protected function sync_plans_to_node(): array {
        $products = [];
        $selected_ids = $this->client->get_plan_products();
        if ( ! empty( $selected_ids ) ) {
            foreach ( $selected_ids as $pid ) {
                $product = wc_get_product( $pid );
                if ( $product ) {
                    $products[] = $product;
                }
            }
        }

        if ( empty( $products ) ) {
            $products = $this->discover_plan_products();
        }

        $summary = [
            'count_total'   => count( $products ),
            'count_success' => 0,
            'count_failed'  => 0,
            'errors'        => [],
            'timestamp'     => current_time( 'mysql' ),
        ];

        foreach ( $products as $product ) {
            $payload = $this->get_plan_payload_for_product( $product );
            if ( empty( $payload['plan_slug'] ) ) {
                $summary['count_failed'] ++;
                $summary['errors'][] = sprintf( __( 'Missing plan slug for product %d', 'davix-sub-bridge' ), $product->get_id() );
                continue;
            }

            $response = $this->client->sync_plan( $payload );
            if ( is_wp_error( $response ) ) {
                $summary['count_failed'] ++;
                $summary['errors'][] = $payload['plan_slug'] . ': ' . $response->get_error_message();
                continue;
            }
            $code    = wp_remote_retrieve_response_code( $response );
            $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( $code >= 200 && $code < 300 && is_array( $decoded ) && ( $decoded['status'] ?? '' ) === 'ok' ) {
                $summary['count_success'] ++;
            } else {
                $summary['count_failed'] ++;
                $summary['errors'][] = $payload['plan_slug'] . ': ' . ( is_array( $decoded ) ? wp_json_encode( $decoded ) : __( 'Unknown error', 'davix-sub-bridge' ) );
            }
        }

        $this->client->save_plan_sync_status( $summary );
        return $summary;
    }

    protected function get_plan_payload_for_product( \WC_Product $product ): array {
        $plan_slug = $product->get_meta( '_dsb_plan_slug', true );
        if ( ! $plan_slug ) {
            $plan_slug = str_replace( '-', '_', sanitize_title( $product->get_slug() ) );
        }

        $monthly_quota_files    = (int) $product->get_meta( '_dsb_monthly_quota_files', true );
        $max_files_per_request  = (int) $product->get_meta( '_dsb_max_files_per_request', true );
        $timeout_seconds        = (int) $product->get_meta( '_dsb_timeout_seconds', true );
        $max_total_upload_mb    = (int) $product->get_meta( '_dsb_max_total_upload_mb', true );

        $payload = [
            'plan_slug'             => $plan_slug,
            'name'                  => $product->get_name(),
            'billing_period'        => $this->detect_billing_period( $product ),
            'monthly_quota_files'   => $monthly_quota_files > 0 ? $monthly_quota_files : 1000,
            'max_files_per_request' => $max_files_per_request > 0 ? $max_files_per_request : 10,
            'max_total_upload_mb'   => $max_total_upload_mb > 0 ? $max_total_upload_mb : 10,
            'timeout_seconds'       => $timeout_seconds > 0 ? $timeout_seconds : 30,
            'allow_h2i'             => $this->meta_flag( $product, '_dsb_allow_h2i', 1 ),
            'allow_image'           => $this->meta_flag( $product, '_dsb_allow_image', 1 ),
            'allow_pdf'             => $this->meta_flag( $product, '_dsb_allow_pdf', 1 ),
            'allow_tools'           => $this->meta_flag( $product, '_dsb_allow_tools', 1 ),
            'is_free'               => $this->meta_flag( $product, '_dsb_is_free', (float) $product->get_price() <= 0 ? 1 : 0 ),
            'description'           => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
        ];

        return $payload;
    }

    protected function detect_billing_period( \WC_Product $product ): string {
        $candidates = [
            $product->get_meta( 'wps_sfw_subscription_interval_type', true ),
            $product->get_meta( 'wps_sfw_subscription_period', true ),
            $product->get_meta( '_subscription_period', true ),
            $product->get_meta( 'wps_sfw_billing_period', true ),
        ];

        foreach ( $candidates as $value ) {
            $period = strtolower( (string) $value );
            if ( in_array( $period, [ 'month', 'monthly' ], true ) ) {
                return 'monthly';
            }
            if ( in_array( $period, [ 'year', 'yearly', 'annual', 'annually' ], true ) ) {
                return 'yearly';
            }
        }

        return 'monthly';
    }

    protected function meta_flag( \WC_Product $product, string $meta_key, int $default = 0 ): int {
        $value = $product->get_meta( $meta_key, true );
        if ( '' === $value ) {
            return $default;
        }
        return in_array( strtolower( (string) $value ), [ '1', 'yes', 'true', 'on' ], true ) ? 1 : 0;
    }

    protected function discover_plan_products(): array {
        $products = [];

        $query = new \WC_Product_Query(
            [
                'status' => [ 'publish', 'private' ],
                'limit'  => 200,
                'orderby'=> 'title',
                'order'  => 'ASC',
                'return' => 'objects',
            ]
        );

        foreach ( $query->get_products() as $product ) {
            if ( $product instanceof \WC_Product && $this->product_is_subscription( $product ) ) {
                $products[ $product->get_id() ] = $product;
            }
        }

        foreach ( $this->client->get_plan_products() as $pid ) {
            if ( isset( $products[ $pid ] ) ) {
                continue;
            }
            $product = wc_get_product( $pid );
            if ( $product ) {
                $products[ $pid ] = $product;
            }
        }

        return array_values( $products );
    }

    protected function product_is_subscription( \WC_Product $product ): bool {
        if ( method_exists( $product, 'is_type' ) ) {
            if ( $product->is_type( 'subscription' ) || $product->is_type( 'variable-subscription' ) ) {
                return true;
            }
        }

        $meta_keys = [
            'wps_sfw_subscription',
            '_wps_sfw_subscription',
            'wps_sfw_recurring',
            'wps_sfw_subscription_price',
            'wps_sfw_subscription_frequency',
            '_subscription_period',
            '_subscription_price',
            '_subscription_period_interval',
        ];

        foreach ( $meta_keys as $meta_key ) {
            $value = $product->get_meta( $meta_key, true );
            if ( '' !== $value && null !== $value ) {
                return true;
            }
        }

        return false;
    }

    protected function get_plan_options(): array {
        $options = [];
        $response = $this->client->fetch_plans();
        if ( ! is_wp_error( $response ) ) {
            $code    = wp_remote_retrieve_response_code( $response );
            $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( $code >= 200 && $code < 300 && isset( $decoded['items'] ) && is_array( $decoded['items'] ) ) {
                foreach ( $decoded['items'] as $plan ) {
                    if ( empty( $plan['plan_slug'] ) ) {
                        continue;
                    }
                    $text = $plan['plan_slug'];
                    if ( isset( $plan['monthly_quota_files'] ) ) {
                        $text .= ' (' . intval( $plan['monthly_quota_files'] ) . ')';
                    }
                    $options[ $plan['plan_slug'] ] = $text;
                }
            }
        }

        if ( empty( $options ) ) {
            $mappings = $this->client->get_product_plans();
            foreach ( $mappings as $plan_slug ) {
                $options[ $plan_slug ] = $plan_slug;
            }
        }

        return $options;
    }

    protected function find_subscription_email( int $subscription_id ): string {
        $email_keys = [ 'wps_sfw_customer_email', 'customer_email', 'billing_email', '_billing_email' ];
        foreach ( $email_keys as $email_key ) {
            $email = get_post_meta( $subscription_id, $email_key, true );
            if ( $email ) {
                return sanitize_email( $email );
            }
        }

        $user_id = (int) get_post_meta( $subscription_id, 'user_id', true );
        if ( $user_id ) {
            $user = get_userdata( $user_id );
            if ( $user ) {
                return $user->user_email;
            }
        }

        return '';
    }

    protected function render_plan_tab(): void {
        $plans = $this->client->get_product_plans();
        ?>
        <form method="post" id="dsb-plan-form">
            <?php wp_nonce_field( 'dsb_save_plans', 'dsb_plans_nonce' ); ?>
            <p><?php esc_html_e( 'Map WooCommerce product IDs to Davix plan slugs.', 'davix-sub-bridge' ); ?></p>
            <table class="widefat" id="dsb-plan-table">
                <thead><tr><th><?php esc_html_e( 'Product ID', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Plan Slug', 'davix-sub-bridge' ); ?></th><th></th></tr></thead>
                <tbody>
                <?php if ( ! empty( $plans ) ) : ?>
                    <?php foreach ( $plans as $product_id => $plan_slug ) : ?>
                        <tr>
                            <td><input type="number" name="product_ids[]" value="<?php echo esc_attr( $product_id ); ?>" required /></td>
                            <td><input type="text" name="plan_slugs[]" value="<?php echo esc_attr( $plan_slug ); ?>" required /></td>
                            <td><button type="button" class="button dsb-remove-row">&times;</button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <tr class="dsb-empty" <?php echo empty( $plans ) ? '' : 'style="display:none;"'; ?>><td colspan="3"><?php esc_html_e( 'No mappings yet.', 'davix-sub-bridge' ); ?></td></tr>
                </tbody>
            </table>
            <p><button type="button" class="button" id="dsb-add-row"><?php esc_html_e( 'Add mapping', 'davix-sub-bridge' ); ?></button></p>
            <?php submit_button( __( 'Save Mappings', 'davix-sub-bridge' ) ); ?>
        </form>
        <script>
            (function($){
                $('#dsb-add-row').on('click', function(){
                    var row = '<tr><td><input type="number" name="product_ids[]" value="" required /></td><td><input type="text" name="plan_slugs[]" value="" placeholder="plan_slug" required /></td><td><button type="button" class="button dsb-remove-row">&times;</button></td></tr>';
                    $('#dsb-plan-table tbody .dsb-empty').hide();
                    $('#dsb-plan-table tbody').append(row);
                });
                $(document).on('click', '.dsb-remove-row', function(){
                    $(this).closest('tr').remove();
                    var rows = $('#dsb-plan-table tbody tr').not('.dsb-empty');
                    if (rows.length === 0){
                        $('#dsb-plan-table tbody .dsb-empty').show();
                    }
                });
            })(jQuery);
        </script>
        <?php
    }

    protected function render_keys_tab(): void {
        $page   = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $response = $this->client->fetch_keys( $page, 20, $search );
        $items    = [];
        $total    = 0;
        $per_page = 20;
        $plan_options = $this->get_plan_options();
        if ( ! is_wp_error( $response ) ) {
            $code    = wp_remote_retrieve_response_code( $response );
            $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( $code >= 200 && $code < 300 && isset( $decoded['items'] ) ) {
                $items = $decoded['items'];
                $total = (int) ( $decoded['total'] ?? 0 );
                $per_page = (int) ( $decoded['per_page'] ?? 20 );
            } else {
                $this->add_notice( __( 'Could not load keys.', 'davix-sub-bridge' ), 'error' );
            }
        } else {
            $this->add_notice( $response->get_error_message(), 'error' );
        }
        ?>
        <form method="get">
            <input type="hidden" name="page" value="davix-bridge" />
            <input type="hidden" name="tab" value="keys" />
            <p class="search-box">
                <label class="screen-reader-text" for="dsb-search">Search Keys</label>
                <input type="search" id="dsb-search" name="s" value="<?php echo esc_attr( $search ); ?>" />
                <?php submit_button( __( 'Search', 'davix-sub-bridge' ), '', '', false ); ?>
            </p>
        </form>
        <table class="widefat">
            <thead><tr><th><?php esc_html_e( 'Subscription ID', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Email', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Plan', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Status', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Key Prefix', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Key Last4', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Updated', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Actions', 'davix-sub-bridge' ); ?></th></tr></thead>
            <tbody>
            <?php if ( empty( $items ) ) : ?>
                <tr><td colspan="8"><?php esc_html_e( 'No keys found.', 'davix-sub-bridge' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $items as $item ) : ?>
                    <tr>
                        <td><?php echo esc_html( $item['subscription_id'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $item['customer_email'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $item['plan_slug'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $item['status'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $item['key_prefix'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $item['key_last4'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $item['updated_at'] ?? '' ); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'dsb_key_action', 'dsb_key_action_nonce' ); ?>
                                <input type="hidden" name="dsb_action" value="rotate" />
                                <input type="hidden" name="subscription_id" value="<?php echo esc_attr( $item['subscription_id'] ?? '' ); ?>" />
                                <input type="hidden" name="customer_email" value="<?php echo esc_attr( $item['customer_email'] ?? '' ); ?>" />
                                <?php submit_button( __( 'Rotate', 'davix-sub-bridge' ), 'link', '', false ); ?>
                            </form>
                            |
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'dsb_key_action', 'dsb_key_action_nonce' ); ?>
                                <input type="hidden" name="dsb_action" value="disable" />
                                <input type="hidden" name="subscription_id" value="<?php echo esc_attr( $item['subscription_id'] ?? '' ); ?>" />
                                <input type="hidden" name="customer_email" value="<?php echo esc_attr( $item['customer_email'] ?? '' ); ?>" />
                                <?php submit_button( __( 'Disable', 'davix-sub-bridge' ), 'link', '', false ); ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
        $total_pages = $per_page > 0 ? ceil( $total / $per_page ) : 1;
        if ( $total_pages > 1 ) {
            $page_links = paginate_links(
                [
                    'base'      => add_query_arg( [ 'paged' => '%#%', 'page' => 'davix-bridge', 'tab' => 'keys', 's' => $search ] ),
                    'format'    => '',
                    'prev_text' => __( '&laquo;', 'davix-sub-bridge' ),
                    'next_text' => __( '&raquo;', 'davix-sub-bridge' ),
                    'total'     => $total_pages,
                    'current'   => $page,
                ]
            );
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $page_links ) . '</div></div>';
        }
        ?>
        <h3><?php esc_html_e( 'Manual Provisioning', 'davix-sub-bridge' ); ?></h3>
        <form method="post">
            <?php wp_nonce_field( 'dsb_manual_key', 'dsb_manual_nonce' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th><?php esc_html_e( 'Customer', 'davix-sub-bridge' ); ?></th>
                    <td>
                        <select id="dsb-customer" name="customer_user_id" class="dsb-select-ajax" data-action="dsb_search_users" data-placeholder="<?php esc_attr_e( 'Search by email', 'davix-sub-bridge' ); ?>" style="width:300px"></select>
                        <input type="hidden" name="customer_email" id="dsb-customer-email" />
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Subscription', 'davix-sub-bridge' ); ?></th>
                    <td><select id="dsb-subscription" name="subscription_id" class="dsb-select-ajax" data-action="dsb_search_subscriptions" data-placeholder="<?php esc_attr_e( 'Search subscriptions', 'davix-sub-bridge' ); ?>" style="width:300px" required></select></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Order', 'davix-sub-bridge' ); ?></th>
                    <td>
                        <select id="dsb-order" name="order_id" class="dsb-select-ajax" data-action="dsb_search_orders" data-placeholder="<?php esc_attr_e( 'Search orders by ID/email', 'davix-sub-bridge' ); ?>" style="width:300px"></select>
                        <p class="description"><?php esc_html_e( 'Optional, helps Node associate orders.', 'davix-sub-bridge' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Plan', 'davix-sub-bridge' ); ?></th>
                    <td>
                        <select id="dsb-plan" name="plan_slug" style="width:300px" required>
                            <option value=""><?php esc_html_e( 'Select plan', 'davix-sub-bridge' ); ?></option>
                            <?php foreach ( $plan_options as $slug => $label ) : ?>
                                <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Provision Key', 'davix-sub-bridge' ) ); ?>
        </form>
        <?php
    }

    protected function render_logs_tab(): void {
        $logs = $this->db->get_logs();
        ?>
        <h2><?php esc_html_e( 'Bridge Logs', 'davix-sub-bridge' ); ?></h2>
        <table class="widefat">
            <thead><tr><th><?php esc_html_e( 'Time', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Event', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Subscription', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Order', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Email', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Response', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'HTTP', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Error', 'davix-sub-bridge' ); ?></th></tr></thead>
            <tbody>
            <?php foreach ( $logs as $log ) : ?>
                <tr>
                    <td><?php echo esc_html( $log['created_at'] ); ?></td>
                    <td><?php echo esc_html( $log['event'] ); ?></td>
                    <td><?php echo esc_html( $log['subscription_id'] ); ?></td>
                    <td><?php echo esc_html( $log['order_id'] ); ?></td>
                    <td><?php echo esc_html( $log['customer_email'] ); ?></td>
                    <td><?php echo esc_html( $log['response_action'] ); ?></td>
                    <td><?php echo esc_html( $log['http_code'] ); ?></td>
                    <td><?php echo esc_html( $log['error_excerpt'] ); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $logs ) ) : ?>
                <tr><td colspan="8"><?php esc_html_e( 'No logs yet.', 'davix-sub-bridge' ); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    public function ajax_search_users(): void {
        check_ajax_referer( 'dsb_admin_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        $query = new \WP_User_Query(
            [
                'search'         => '*' . $term . '*',
                'number'         => 20,
                'search_columns' => [ 'user_email', 'user_login', 'display_name' ],
            ]
        );
        $results = [];
        foreach ( $query->get_results() as $user ) {
            $results[] = [
                'id'   => $user->ID,
                'text' => $user->user_email . ' (' . $user->display_name . ')',
                'email'=> $user->user_email,
            ];
        }
        wp_send_json( [ 'results' => $results ] );
    }

    public function ajax_search_subscriptions(): void {
        check_ajax_referer( 'dsb_admin_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

        $post_types = [ 'shop_subscription', 'wps_subscriptions', 'wps_sfw_subscription', 'wps-subscription' ];
        foreach ( get_post_types( [], 'names' ) as $type ) {
            if ( false !== strpos( $type, 'subscription' ) && ! in_array( $type, $post_types, true ) ) {
                $post_types[] = $type;
            }
        }

        $query = new \WP_Query(
            [
                'post_type'      => $post_types,
                'post_status'    => 'any',
                's'              => $term,
                'posts_per_page' => 20,
            ]
        );
        $results = [];
        foreach ( $query->posts as $post ) {
            $email  = $this->find_subscription_email( $post->ID );
            $status = get_post_status( $post );
            $results[] = [
                'id'   => (string) $post->ID,
                'text' => $post->ID . ' — ' . $status . ' — ' . $email,
            ];
        }
        wp_send_json( [ 'results' => $results ] );
    }

    public function ajax_search_orders(): void {
        check_ajax_referer( 'dsb_admin_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

        $args = [
            'limit'   => 20,
            'orderby' => 'date',
            'order'   => 'DESC',
        ];
        if ( $term ) {
            $args['search'] = '*' . $term . '*';
        }
        if ( is_email( $term ) ) {
            $args['billing_email'] = $term;
        }

        $query  = new \WC_Order_Query( $args );
        $orders = $query->get_orders();
        $results = [];
        foreach ( $orders as $order ) {
            if ( ! $order instanceof \WC_Order ) {
                continue;
            }
            $results[] = [
                'id'   => (string) $order->get_id(),
                'text' => sprintf( 'Order #%1$s — %2$s — %3$s', $order->get_id(), $order->get_billing_email(), $order->get_status() ),
            ];
        }
        wp_send_json( [ 'results' => $results ] );
    }
}

}
