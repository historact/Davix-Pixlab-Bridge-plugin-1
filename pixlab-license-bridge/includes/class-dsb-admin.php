<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\DSB_Admin' ) ) {

class DSB_Admin {
    protected $client;
    protected $db;
    protected $events;
    protected $resync;
    protected $purge_worker;
    protected $provision_worker;
    protected $node_poll;
    protected $notices = [];
    protected $synced_product_ids = [];
    protected $diagnostics_result = null;
    protected $settings_access_safe_mode = null;
    protected $settings_access_allowed_roles = null;
    protected $settings_access_notice_added = false;
    protected static function woocommerce_active(): bool {
        return function_exists( 'wc_get_product' ) || function_exists( 'WC' ) || class_exists( '\\WooCommerce' );
    }

    public function __construct( DSB_Client $client, DSB_DB $db, DSB_Events $events, DSB_Resync $resync, DSB_Purge_Worker $purge_worker, DSB_Provision_Worker $provision_worker, DSB_Node_Poll $node_poll ) {
        $this->client       = $client;
        $this->db           = $db;
        $this->events       = $events;
        $this->resync       = $resync;
        $this->purge_worker = $purge_worker;
        $this->provision_worker = $provision_worker;
        $this->node_poll    = $node_poll;
    }

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_filter( 'pre_update_option_' . DSB_Client::OPTION_SETTINGS, [ $this, 'filter_pre_update_settings' ], 10, 2 );
        add_action( 'admin_post_dsb_download_log', [ $this, 'handle_download_log' ] );
        add_action( 'admin_post_dsb_clear_log', [ $this, 'handle_clear_log' ] );
        add_action( 'admin_post_dsb_clear_db_logs', [ $this, 'handle_clear_db_logs' ] );
        add_action( 'admin_post_dsb_test_alert_routing', [ $this, 'handle_test_alert_routing' ] );
        add_action( 'admin_post_dsb_download_alerts', [ $this, 'handle_download_alerts' ] );
        add_action( 'admin_post_dsb_run_resync_now', [ $this, 'handle_run_resync_now' ] );
        add_action( 'admin_post_dsb_run_node_poll_now', [ $this, 'handle_run_node_poll_now' ] );
        add_action( 'wp_ajax_dsb_search_users', [ $this, 'ajax_search_users' ] );
        add_action( 'wp_ajax_dsb_js_log', __NAMESPACE__ . '\\dsb_handle_js_log' );
        add_action( 'wp_ajax_dsb_render_tab', [ $this, 'ajax_render_tab' ] );
        add_action( 'wp_ajax_dsb_get_recent_alerts', [ $this, 'ajax_get_recent_alerts' ] );
        add_action( 'wp_ajax_dsb_clear_alerts', [ $this, 'ajax_clear_alerts' ] );
        add_action( 'wp_ajax_dsb_get_debug_log_tail', [ $this, 'ajax_get_debug_log_tail' ] );
        add_action( 'wp_ajax_dsb_get_logs_table', [ $this, 'ajax_get_logs_table' ] );
        if ( function_exists( 'pmpro_getLevel' ) ) {
            add_action( 'pmpro_membership_level_after_other_settings', [ $this, 'render_level_plan_fields' ], 10, 1 );
            add_action( 'pmpro_save_membership_level', [ $this, 'save_level_plan_meta' ], 10, 1 );
        }
    }

    public function register_menu(): void {
        if ( ! $this->current_user_can_manage_settings() ) {
            return;
        }

        $capability = 'manage_options';
        if ( $this->settings_access_enabled() && ! $this->settings_access_safe_mode() ) {
            $capability = 'read';
        }

        add_options_page(
            __( 'PixLab License Bridge', 'pixlab-license-bridge' ),
            __( 'PixLab License', 'pixlab-license-bridge' ),
            $capability,
            'davix-bridge',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        $settings = $this->client->get_settings();

        dsb_log( 'debug', 'Admin enqueue called', [
            'hook' => $hook,
            'page' => $page,
            'tab'  => $tab,
        ] );

        if ( ! isset( $_GET['page'] ) || 'davix-bridge' !== $page ) {
            dsb_log( 'debug', 'Admin enqueue skipped: not PixLab License page', [ 'hook' => $hook, 'page' => $page ] );
            return;
        }

        if ( wp_script_is( 'selectWoo', 'registered' ) || wp_script_is( 'selectWoo', 'enqueued' ) ) {
            wp_enqueue_script( 'selectWoo' );
            if ( wp_style_is( 'selectWoo', 'registered' ) ) {
                wp_enqueue_style( 'selectWoo' );
            } elseif ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
                wp_enqueue_style( 'woocommerce_admin_styles' );
            } elseif ( wp_style_is( 'select2', 'registered' ) ) {
                wp_enqueue_style( 'select2' );
            }
        } elseif ( wp_script_is( 'select2', 'registered' ) ) {
            wp_enqueue_script( 'select2' );
            if ( wp_style_is( 'select2', 'registered' ) ) {
                wp_enqueue_style( 'select2' );
            }
        }

        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        $css_path = DSB_PLUGIN_DIR . 'assets/css/dsb-admin.css';
        $css_ver  = file_exists( $css_path ) ? DSB_VERSION . '.' . filemtime( $css_path ) : DSB_VERSION;

        wp_register_style(
            'dsb-admin-styles',
            DSB_PLUGIN_URL . 'assets/css/dsb-admin.css',
            [ 'wp-color-picker' ],
            $css_ver
        );
        wp_enqueue_style( 'dsb-admin-styles' );

        $js_path = DSB_PLUGIN_DIR . 'assets/js/dsb-admin.js';
        $js_ver  = file_exists( $js_path ) ? DSB_VERSION . '.' . filemtime( $js_path ) : DSB_VERSION;

        wp_register_script(
            'dsb-admin',
            DSB_PLUGIN_URL . 'assets/js/dsb-admin.js',
            [ 'jquery', 'wp-color-picker' ],
            $js_ver,
            true
        );
        wp_localize_script(
            'dsb-admin',
            'DSB_ADMIN',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'dsb_js_log' ),
                'ajax_nonce' => wp_create_nonce( 'dsb_admin_ajax' ),
                'page'    => $page,
                'tab'     => $tab,
                'debug'   => ! empty( $settings['debug_enabled'] ),
            ]
        );
        wp_enqueue_script( 'dsb-admin' );

        if ( ! empty( $settings['debug_enabled'] ) && in_array( $tab, [ 'keys', 'style' ], true ) ) {
            wp_add_inline_script(
                'dsb-admin',
                "(function(){\n  try {\n    if (window.DSB_INLINE_PROOF_SENT) return;\n    if (!window.DSB_ADMIN) return;\n    if (!DSB_ADMIN.ajaxUrl || !DSB_ADMIN.nonce) return;\n    if (!DSB_ADMIN.debug) return;\n    if (['keys','style'].indexOf(DSB_ADMIN.tab) === -1) return;\n    window.DSB_INLINE_PROOF_SENT = true;\n    var data = new FormData();\n    data.append('action','dsb_js_log');\n    data.append('nonce',DSB_ADMIN.nonce);\n    data.append('level','debug');\n    data.append('message','INLINE_PROOF: dsb-admin handle printed');\n    data.append('context', JSON.stringify({href: location.href, tab: DSB_ADMIN.tab}));\n    fetch(DSB_ADMIN.ajaxUrl, {method:'POST', credentials:'same-origin', body:data});\n  } catch(e) {}\n})();",
                'after'
            );
        }

        dsb_log( 'debug', 'Enqueuing dsb-admin.js', [
            'handle'    => 'dsb-admin',
            'src'       => DSB_PLUGIN_URL . 'assets/js/dsb-admin.js',
            'deps'      => [ 'jquery', 'wp-color-picker' ],
            'in_footer' => true,
        ] );
    }

    public function add_plan_limits_tab( array $tabs ): array {
        $tabs['dsb_plan_limits'] = [
            'label'    => __( 'Davix Plan Limits', 'pixlab-license-bridge' ),
            'target'   => 'dsb_plan_limits_panel',
            'class'    => [ 'show_if_simple', 'show_if_variable', 'show_if_subscription', 'show_if_variable-subscription' ],
            'priority' => 80,
        ];

        return $tabs;
    }

    public function render_plan_limits_panel(): void {
        if ( ! self::woocommerce_active() ) {
            dsb_log( 'debug', 'WooCommerce inactive; skipping plan limits panel render' );
            return;
        }
        global $post;
        $product = wc_get_product( $post );
        if ( ! $product instanceof \WC_Product ) {
            return;
        }

        $defaults = $this->get_plan_limits_defaults( $product );
        ?>
        <div id="dsb_plan_limits_panel" class="panel woocommerce_options_panel hidden">
            <div class="options_group">
                <?php
                woocommerce_wp_text_input(
                    [
                        'id'          => '_dsb_plan_slug',
                        'label'       => __( 'Plan Slug', 'pixlab-license-bridge' ),
                        'desc_tip'    => true,
                        'description' => __( 'Optional override. Defaults to a sanitized product slug.', 'pixlab-license-bridge' ),
                        'value'       => $defaults['plan_slug'],
                    ]
                );
                woocommerce_wp_text_input(
                    [
                        'id'                => '_dsb_monthly_quota_files',
                        'label'             => __( 'Monthly quota (files)', 'pixlab-license-bridge' ),
                        'type'              => 'number',
                        'custom_attributes' => [ 'min' => 1 ],
                        'value'             => $defaults['monthly_quota_files'],
                    ]
                );
                woocommerce_wp_text_input(
                    [
                        'id'                => '_dsb_max_files_per_request',
                        'label'             => __( 'Max files per request', 'pixlab-license-bridge' ),
                        'type'              => 'number',
                        'custom_attributes' => [ 'min' => 1 ],
                        'value'             => $defaults['max_files_per_request'],
                    ]
                );
                woocommerce_wp_text_input(
                    [
                        'id'                => '_dsb_max_total_upload_mb',
                        'label'             => __( 'Max total upload (MB)', 'pixlab-license-bridge' ),
                        'type'              => 'number',
                        'custom_attributes' => [ 'min' => 1 ],
                        'value'             => $defaults['max_total_upload_mb'],
                    ]
                );
                woocommerce_wp_text_input(
                    [
                        'id'                => '_dsb_max_dimension_px',
                        'label'             => __( 'Max dimension (px)', 'pixlab-license-bridge' ),
                        'type'              => 'number',
                        'custom_attributes' => [ 'min' => 100 ],
                        'value'             => $defaults['max_dimension_px'],
                    ]
                );
                woocommerce_wp_text_input(
                    [
                        'id'                => '_dsb_timeout_seconds',
                        'label'             => __( 'Timeout (seconds)', 'pixlab-license-bridge' ),
                        'type'              => 'number',
                        'custom_attributes' => [ 'min' => 5 ],
                        'value'             => $defaults['timeout_seconds'],
                    ]
                );
                ?>
            </div>
            <div class="options_group">
                <?php
                woocommerce_wp_checkbox(
                    [
                        'id'          => '_dsb_allow_h2i',
                        'label'       => __( 'Allow H2I', 'pixlab-license-bridge' ),
                        'value'       => $defaults['allow_h2i'],
                        'desc_tip'    => true,
                        'description' => __( 'Permit H2I actions for this plan.', 'pixlab-license-bridge' ),
                    ]
                );
                woocommerce_wp_checkbox(
                    [
                        'id'          => '_dsb_allow_image',
                        'label'       => __( 'Allow image', 'pixlab-license-bridge' ),
                        'value'       => $defaults['allow_image'],
                    ]
                );
                woocommerce_wp_checkbox(
                    [
                        'id'          => '_dsb_allow_pdf',
                        'label'       => __( 'Allow PDF', 'pixlab-license-bridge' ),
                        'value'       => $defaults['allow_pdf'],
                    ]
                );
                woocommerce_wp_checkbox(
                    [
                        'id'          => '_dsb_allow_tools',
                        'label'       => __( 'Allow tools', 'pixlab-license-bridge' ),
                        'value'       => $defaults['allow_tools'],
                    ]
                );
                woocommerce_wp_checkbox(
                    [
                        'id'          => '_dsb_is_free',
                        'label'       => __( 'Free plan', 'pixlab-license-bridge' ),
                        'value'       => $defaults['is_free'],
                        'desc_tip'    => true,
                        'description' => __( 'Mark plan as free when the price is zero.', 'pixlab-license-bridge' ),
                    ]
                );
                ?>
            </div>
        </div>
        <?php
    }

    public function render_level_plan_fields( $level ): void {
        $level_id = isset( $level->id ) ? (int) $level->id : 0;
        $meta     = $this->get_level_meta_defaults( $level_id );
        ?>
        <h3><?php esc_html_e( 'Davix Plan Limits', 'pixlab-license-bridge' ); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="dsb_level_plan_slug"><?php esc_html_e( 'Plan Slug', 'pixlab-license-bridge' ); ?></label></th>
                <td><input type="text" name="dsb_level_meta[plan_slug]" id="dsb_level_plan_slug" value="<?php echo esc_attr( $meta['plan_slug'] ); ?>" class="regular-text" required />
                <p class="description"><?php esc_html_e( 'Slug sent to Node for this membership level.', 'pixlab-license-bridge' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><label for="dsb_level_billing_period"><?php esc_html_e( 'Billing period', 'pixlab-license-bridge' ); ?></label></th>
                <td>
                    <select name="dsb_level_meta[billing_period]" id="dsb_level_billing_period">
                        <option value="monthly" <?php selected( $meta['billing_period'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'pixlab-license-bridge' ); ?></option>
                        <option value="yearly" <?php selected( $meta['billing_period'], 'yearly' ); ?>><?php esc_html_e( 'Yearly', 'pixlab-license-bridge' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Monthly quota (files)', 'pixlab-license-bridge' ); ?></th>
                <td><input type="number" min="1" name="dsb_level_meta[monthly_quota_files]" value="<?php echo esc_attr( $meta['monthly_quota_files'] ); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Max files per request', 'pixlab-license-bridge' ); ?></th>
                <td><input type="number" min="1" name="dsb_level_meta[max_files_per_request]" value="<?php echo esc_attr( $meta['max_files_per_request'] ); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Max total upload (MB)', 'pixlab-license-bridge' ); ?></th>
                <td><input type="number" min="1" name="dsb_level_meta[max_total_upload_mb]" value="<?php echo esc_attr( $meta['max_total_upload_mb'] ); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Max dimension (px)', 'pixlab-license-bridge' ); ?></th>
                <td><input type="number" min="100" name="dsb_level_meta[max_dimension_px]" value="<?php echo esc_attr( $meta['max_dimension_px'] ); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Timeout (seconds)', 'pixlab-license-bridge' ); ?></th>
                <td><input type="number" min="5" name="dsb_level_meta[timeout_seconds]" value="<?php echo esc_attr( $meta['timeout_seconds'] ); ?>" /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Capabilities', 'pixlab-license-bridge' ); ?></th>
                <td>
                    <label><input type="checkbox" name="dsb_level_meta[allow_h2i]" value="1" <?php checked( $meta['allow_h2i'], 1 ); ?> /> <?php esc_html_e( 'Allow H2I', 'pixlab-license-bridge' ); ?></label><br/>
                    <label><input type="checkbox" name="dsb_level_meta[allow_image]" value="1" <?php checked( $meta['allow_image'], 1 ); ?> /> <?php esc_html_e( 'Allow image', 'pixlab-license-bridge' ); ?></label><br/>
                    <label><input type="checkbox" name="dsb_level_meta[allow_pdf]" value="1" <?php checked( $meta['allow_pdf'], 1 ); ?> /> <?php esc_html_e( 'Allow PDF', 'pixlab-license-bridge' ); ?></label><br/>
                    <label><input type="checkbox" name="dsb_level_meta[allow_tools]" value="1" <?php checked( $meta['allow_tools'], 1 ); ?> /> <?php esc_html_e( 'Allow tools', 'pixlab-license-bridge' ); ?></label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Free plan', 'pixlab-license-bridge' ); ?></th>
                <td><label><input type="checkbox" name="dsb_level_meta[is_free]" value="1" <?php checked( $meta['is_free'], 1 ); ?> /> <?php esc_html_e( 'Mark this membership as free', 'pixlab-license-bridge' ); ?></label></td>
            </tr>
            <tr>
                <th scope="row"><label for="dsb_level_description"><?php esc_html_e( 'Description', 'pixlab-license-bridge' ); ?></label></th>
                <td><textarea name="dsb_level_meta[description]" id="dsb_level_description" rows="3" class="large-text"><?php echo esc_textarea( $meta['description'] ); ?></textarea></td>
            </tr>
        </table>
        <?php
    }

    public function save_level_plan_meta( int $level_id ): void {
        $level_id = absint( $level_id );
        if ( $level_id <= 0 ) {
            return;
        }

        $posted_meta = isset( $_REQUEST['dsb_level_meta'] ) && is_array( $_REQUEST['dsb_level_meta'] ) ? wp_unslash( $_REQUEST['dsb_level_meta'] ) : [];
        $meta        = $this->get_level_meta_defaults( $level_id );

        foreach ( [ 'plan_slug', 'billing_period', 'description' ] as $text_field ) {
            if ( isset( $posted_meta[ $text_field ] ) ) {
                $value = sanitize_text_field( $posted_meta[ $text_field ] );
                if ( 'plan_slug' === $text_field ) {
                    $value = dsb_normalize_plan_slug( $value );
                }
                $meta[ $text_field ] = $value;
            }
        }

        foreach ( [ 'monthly_quota_files', 'max_files_per_request', 'max_total_upload_mb', 'max_dimension_px', 'timeout_seconds' ] as $int_field ) {
            if ( isset( $posted_meta[ $int_field ] ) ) {
                $meta[ $int_field ] = max( 0, (int) $posted_meta[ $int_field ] );
            }
        }

        foreach ( [ 'allow_h2i', 'allow_image', 'allow_pdf', 'allow_tools', 'is_free' ] as $flag_field ) {
            $meta[ $flag_field ] = isset( $posted_meta[ $flag_field ] ) ? 1 : 0;
        }

        update_option( 'dsb_level_meta_' . $level_id, $meta );

        $level_plans          = $this->client->get_level_plans();
        $level_plans[ $level_id ] = $meta['plan_slug'];
        $this->client->save_level_plans( $level_plans );

        $this->sync_level_to_node( $level_id, $meta );
    }

    protected function get_level_meta_defaults( int $level_id ): array {
        $level = function_exists( 'pmpro_getLevel' ) ? pmpro_getLevel( $level_id ) : null;
        $stored = get_option( 'dsb_level_meta_' . $level_id, [] );
        $defaults = [
            'plan_slug'             => $this->client->plan_slug_for_level( $level_id ) ?: ( $level && isset( $level->name ) ? dsb_normalize_plan_slug( $level->name ) : '' ),
            'billing_period'        => $level && isset( $level->cycle_period ) && in_array( strtolower( (string) $level->cycle_period ), [ 'year', 'yearly', 'annual', 'annually' ], true ) ? 'yearly' : 'monthly',
            'monthly_quota_files'   => 1000,
            'max_files_per_request' => 10,
            'max_total_upload_mb'   => 10,
            'max_dimension_px'      => 2000,
            'timeout_seconds'       => 30,
            'allow_h2i'             => 1,
            'allow_image'           => 1,
            'allow_pdf'             => 1,
            'allow_tools'           => 1,
            'is_free'               => 0,
            'description'           => $level && isset( $level->description ) ? wp_strip_all_tags( $level->description ) : '',
        ];

        if ( is_array( $stored ) ) {
            $meta = wp_parse_args( $stored, $defaults );
        } else {
            $meta = $defaults;
        }

        $meta['plan_slug'] = dsb_normalize_plan_slug( $meta['plan_slug'] );

        return $meta;
    }

    protected function sync_level_to_node( int $level_id, array $meta = [] ): void {
        $payload = $this->get_plan_payload_for_level( $level_id, $meta );
        if ( empty( $payload['plan_slug'] ) ) {
            $this->db->log_event(
                [
                    'event'         => 'plan_sync',
                    'plan_slug'     => '',
                    'error_excerpt' => __( 'Missing plan slug; sync skipped.', 'pixlab-license-bridge' ),
                ]
            );
            return;
        }

        $response = $this->client->sync_plan( $payload );
        $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        $decoded  = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
        $this->db->log_event(
            [
                'event'           => 'plan_sync',
                'plan_slug'       => $payload['plan_slug'],
                'subscription_id' => '',
                'order_id'        => '',
                'response_action' => $decoded['action'] ?? '',
                'http_code'       => $code,
                'error_excerpt'   => is_wp_error( $response ) ? $response->get_error_message() : ( $decoded['status'] ?? '' ),
            ]
        );
    }

    protected function get_plan_payload_for_level( int $level_id, array $meta = [] ): array {
        $meta  = $meta ?: $this->get_level_meta_defaults( $level_id );
        $level = function_exists( 'pmpro_getLevel' ) ? pmpro_getLevel( $level_id ) : null;

        $plan_slug   = $meta['plan_slug'];
        $name        = $level && isset( $level->name ) ? $level->name : sprintf( __( 'Level %d', 'pixlab-license-bridge' ), $level_id );
        $description = $meta['description'] ?: ( $level && isset( $level->description ) ? wp_strip_all_tags( $level->description ) : '' );
        $billing_period = in_array( $meta['billing_period'], [ 'monthly', 'yearly' ], true ) ? $meta['billing_period'] : 'monthly';

        return [
            'plan_slug'             => $plan_slug,
            'name'                  => $name,
            'billing_period'        => $billing_period,
            'monthly_quota_files'   => (int) $meta['monthly_quota_files'],
            'max_files_per_request' => (int) $meta['max_files_per_request'],
            'max_total_upload_mb'   => (int) $meta['max_total_upload_mb'],
            'max_dimension_px'      => (int) $meta['max_dimension_px'],
            'timeout_seconds'       => (int) $meta['timeout_seconds'],
            'allow_h2i'             => (int) $meta['allow_h2i'],
            'allow_image'           => (int) $meta['allow_image'],
            'allow_pdf'             => (int) $meta['allow_pdf'],
            'allow_tools'           => (int) $meta['allow_tools'],
            'is_free'               => (int) $meta['is_free'],
            'description'           => $description,
            'wp_level_id'           => $level_id,
        ];
    }

    public function save_plan_limits_meta( \WC_Product $product ): void {
        $normalize_plan_slug = __NAMESPACE__ . '\\dsb_normalize_plan_slug';
        $plan_slug_callable  = is_callable( $normalize_plan_slug );
        $logged_missing_cb   = false;

        $fields = [
            '_dsb_plan_slug'             => $normalize_plan_slug,
            '_dsb_monthly_quota_files'   => 'absint',
            '_dsb_max_files_per_request' => 'absint',
            '_dsb_max_total_upload_mb'   => 'absint',
            '_dsb_max_dimension_px'      => 'absint',
            '_dsb_timeout_seconds'       => 'absint',
        ];

        foreach ( $fields as $key => $callback ) {
            $raw_value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';

            if ( '' !== $raw_value && is_callable( $callback ) ) {
                $value = call_user_func( $callback, $raw_value );
            } else {
                if ( '' !== $raw_value && ! $logged_missing_cb && ! is_callable( $callback ) ) {
                    dsb_log( 'error', 'Plan limits field callback not callable', [ 'field' => $key, 'callback' => $callback ] );
                    $logged_missing_cb = true;
                }
                $value = '_dsb_plan_slug' === $key ? sanitize_key( $raw_value ) : sanitize_text_field( $raw_value );
            }

            if ( '_dsb_plan_slug' === $key ) {
                if ( $plan_slug_callable ) {
                    $value = $value ? call_user_func( $normalize_plan_slug, $value ) : call_user_func( $normalize_plan_slug, $product->get_slug() );
                } else {
                    if ( ! $logged_missing_cb ) {
                        dsb_log( 'error', 'Plan slug normalizer missing; using sanitized slug', [ 'callback' => $normalize_plan_slug ] );
                        $logged_missing_cb = true;
                    }
                    $value = $value ? sanitize_key( $value ) : sanitize_key( $product->get_slug() );
                }
            }

            $product->update_meta_data( $key, $value );
        }

        $checkboxes = [ '_dsb_allow_h2i', '_dsb_allow_image', '_dsb_allow_pdf', '_dsb_allow_tools', '_dsb_is_free' ];
        foreach ( $checkboxes as $checkbox ) {
            $product->update_meta_data( $checkbox, isset( $_POST[ $checkbox ] ) ? 1 : 0 );
        }
    }

    protected function get_plan_limits_defaults( \WC_Product $product ): array {
        $defaults = [
            'plan_slug'             => dsb_normalize_plan_slug( $product->get_meta( '_dsb_plan_slug', true ) ?: $product->get_slug() ),
            'monthly_quota_files'   => (int) $product->get_meta( '_dsb_monthly_quota_files', true ) ?: 1000,
            'max_files_per_request' => (int) $product->get_meta( '_dsb_max_files_per_request', true ) ?: 10,
            'max_total_upload_mb'   => (int) $product->get_meta( '_dsb_max_total_upload_mb', true ) ?: 10,
            'max_dimension_px'      => (int) $product->get_meta( '_dsb_max_dimension_px', true ) ?: 2000,
            'timeout_seconds'       => (int) $product->get_meta( '_dsb_timeout_seconds', true ) ?: 30,
            'allow_h2i'             => $this->meta_flag( $product, '_dsb_allow_h2i', 1 ),
            'allow_image'           => $this->meta_flag( $product, '_dsb_allow_image', 1 ),
            'allow_pdf'             => $this->meta_flag( $product, '_dsb_allow_pdf', 1 ),
            'allow_tools'           => $this->meta_flag( $product, '_dsb_allow_tools', 1 ),
            'is_free'               => $this->meta_flag( $product, '_dsb_is_free', (float) $product->get_price() <= 0 ? 1 : 0 ),
        ];

        return $defaults;
    }

    protected function add_notice( string $message, string $type = 'success' ): void {
        $this->notices[] = [
            'message' => $message,
            'type'    => $type,
        ];
    }

    protected function get_settings_access(): array {
        $settings = $this->client->get_settings();
        $access   = isset( $settings['settings_access'] ) && is_array( $settings['settings_access'] ) ? $settings['settings_access'] : [];
        return wp_parse_args(
            $access,
            [
                'enabled'       => 0,
                'allowed_roles' => [],
            ]
        );
    }

    protected function settings_access_enabled(): bool {
        $access = $this->get_settings_access();
        return ! empty( $access['enabled'] );
    }

    protected function normalize_role_keys( $roles ): array {
        $roles = is_array( $roles ) ? $roles : [];
        $role_source = function_exists( 'get_editable_roles' ) ? get_editable_roles() : [];
        $wp_roles = function_exists( 'wp_roles' ) ? wp_roles() : null;
        if ( $wp_roles && is_array( $wp_roles->roles ) ) {
            $role_source = array_merge( $role_source, $wp_roles->roles );
        }

        $role_source = is_array( $role_source ) ? $role_source : [];
        $valid_keys  = array_keys( $role_source );
        $name_map    = [];

        foreach ( $role_source as $role_key => $role_data ) {
            if ( ! is_array( $role_data ) || empty( $role_data['name'] ) ) {
                continue;
            }
            $name_map[ strtolower( (string) $role_data['name'] ) ] = $role_key;
        }

        $clean = [];
        foreach ( $roles as $role ) {
            if ( ! is_string( $role ) ) {
                continue;
            }
            $role = trim( $role );
            if ( '' === $role ) {
                continue;
            }
            $key = sanitize_key( $role );
            if ( in_array( $key, $valid_keys, true ) ) {
                $clean[ $key ] = true;
                continue;
            }
            $lower = strtolower( $role );
            if ( isset( $name_map[ $lower ] ) ) {
                $clean[ $name_map[ $lower ] ] = true;
            }
        }

        return array_values( array_keys( $clean ) );
    }

    protected function get_settings_access_roles(): array {
        if ( null !== $this->settings_access_allowed_roles ) {
            return $this->settings_access_allowed_roles;
        }

        $access = $this->get_settings_access();
        $roles = $this->normalize_role_keys( $access['allowed_roles'] ?? [] );
        $this->settings_access_allowed_roles = $roles;
        return $roles;
    }

    protected function settings_access_safe_mode(): bool {
        if ( null !== $this->settings_access_safe_mode ) {
            return $this->settings_access_safe_mode;
        }

        if ( ! $this->settings_access_enabled() ) {
            $this->settings_access_safe_mode = false;
            return false;
        }

        $roles = $this->get_settings_access_roles();
        if ( empty( $roles ) ) {
            $this->settings_access_safe_mode = true;
            return true;
        }

        $has_users = false;
        foreach ( $roles as $role ) {
            $query = new \WP_User_Query(
                [
                    'role'         => $role,
                    'number'       => 1,
                    'fields'       => 'ID',
                    'count_total'  => false,
                ]
            );
            if ( ! empty( $query->get_results() ) ) {
                $has_users = true;
                break;
            }
        }

        $this->settings_access_safe_mode = ! $has_users;
        return $this->settings_access_safe_mode;
    }

    protected function current_user_can_manage_settings(): bool {
        if ( ! $this->settings_access_enabled() ) {
            return current_user_can( 'manage_options' );
        }

        if ( $this->settings_access_safe_mode() ) {
            return current_user_can( 'manage_options' );
        }

        if ( ! is_user_logged_in() ) {
            return false;
        }

        $user = wp_get_current_user();
        $user_roles = array_map( 'sanitize_key', is_array( $user->roles ) ? $user->roles : [] );
        $allowed_roles = $this->get_settings_access_roles();

        return ! empty( array_intersect( $user_roles, $allowed_roles ) );
    }

    protected function maybe_add_settings_access_safe_mode_notice(): void {
        if ( $this->settings_access_notice_added || ! $this->settings_access_enabled() ) {
            return;
        }

        if ( ! $this->settings_access_safe_mode() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->settings_access_notice_added = true;
        $this->add_notice(
            __( 'Settings page access restriction is enabled but misconfigured. Safe Mode restored administrator access because no allowed roles are available.', 'pixlab-license-bridge' ),
            'error'
        );
    }

    public function filter_pre_update_settings( $new_value, $old_value ) {
        if ( $this->current_user_can_manage_settings() ) {
            return $new_value;
        }

        $old_access = isset( $old_value['settings_access'] ) && is_array( $old_value['settings_access'] ) ? $old_value['settings_access'] : [];
        $new_access = isset( $new_value['settings_access'] ) && is_array( $new_value['settings_access'] ) ? $new_value['settings_access'] : [];
        $old_enabled = ! empty( $old_access['enabled'] );
        $new_enabled = ! empty( $new_access['enabled'] );

        if ( $old_enabled && ! $new_enabled && current_user_can( 'manage_options' ) ) {
            return $new_value;
        }

        if ( is_admin() ) {
            $this->add_notice(
                __( 'You are not allowed to update PixLab License settings.', 'pixlab-license-bridge' ),
                'error'
            );
        }

        return $old_value;
    }

    public function handle_actions(): void {
        $settings_nonce_valid = 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['dsb_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_settings_nonce'] ) ), 'dsb_save_settings' );
        $plan_mapping_nonce_valid = 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['dsb_plans_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_plans_nonce'] ) ), 'dsb_save_plans' );
        $settings_action = $settings_nonce_valid || $plan_mapping_nonce_valid;
        if ( $settings_action ) {
            if ( ! $this->current_user_can_manage_settings() ) {
                return;
            }
        } elseif ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
        $previous_settings = $this->client->get_settings();

        if ( isset( $_GET['dsb_log_action'] ) ) {
            $action = sanitize_key( wp_unslash( $_GET['dsb_log_action'] ) );
            if ( 'cleared' === $action ) {
                $this->add_notice( __( 'Debug log cleared.', 'pixlab-license-bridge' ) );
            } elseif ( 'error' === $action ) {
                $this->add_notice( __( 'Debug log action failed.', 'pixlab-license-bridge' ), 'error' );
            }
        }

        if ( isset( $_GET['dsb_logs_action'] ) ) {
            $action = sanitize_key( wp_unslash( $_GET['dsb_logs_action'] ) );
            if ( 'cleared' === $action ) {
                $this->add_notice( __( 'Bridge logs cleared.', 'pixlab-license-bridge' ) );
            } elseif ( 'error' === $action ) {
                $this->add_notice( __( 'Could not clear bridge logs.', 'pixlab-license-bridge' ), 'error' );
            }
        }

        if ( isset( $_GET['dsb_resync_status'] ) ) {
            $status  = sanitize_key( wp_unslash( $_GET['dsb_resync_status'] ) );
            $message = isset( $_GET['dsb_resync_message'] ) ? sanitize_text_field( wp_unslash( $_GET['dsb_resync_message'] ) ) : '';

            if ( 'ok' === $status ) {
                $this->add_notice( __( 'Resync run completed.', 'pixlab-license-bridge' ) );
            } elseif ( 'locked' === $status ) {
                $this->add_notice( __( 'Resync skipped because a run is already in progress.', 'pixlab-license-bridge' ), 'error' );
            } else {
                $this->add_notice( $message ? $message : __( 'Resync encountered an error.', 'pixlab-license-bridge' ), 'error' );
            }
        }

        if ( isset( $_GET['dsb_node_poll_status'] ) ) {
            $status  = sanitize_key( wp_unslash( $_GET['dsb_node_poll_status'] ) );
            $message = isset( $_GET['dsb_node_poll_message'] ) ? sanitize_text_field( wp_unslash( $_GET['dsb_node_poll_message'] ) ) : '';

            if ( 'ok' === $status ) {
                $this->add_notice( __( 'Node poll sync completed.', 'pixlab-license-bridge' ) );
            } elseif ( 'locked' === $status ) {
                $this->add_notice( __( 'Node poll skipped because a run is already in progress.', 'pixlab-license-bridge' ), 'error' );
            } elseif ( 'disabled' === $status ) {
                $this->add_notice( __( 'Node poll is disabled in settings.', 'pixlab-license-bridge' ), 'error' );
            } else {
                $this->add_notice( $message ? $message : __( 'Node poll encountered an error.', 'pixlab-license-bridge' ), 'error' );
            }
        }

        if ( isset( $_GET['dsb_alert_test'] ) ) {
            $status = sanitize_key( wp_unslash( $_GET['dsb_alert_test'] ) );
            if ( 'success' === $status ) {
                $this->add_notice( __( 'Test alert sent.', 'pixlab-license-bridge' ) );
            } else {
                $this->add_notice( __( 'Test alert failed to send.', 'pixlab-license-bridge' ), 'error' );
            }
        }

        if ( $settings_nonce_valid ) {
            if ( 'style' === $tab ) {
                $style_keys = array_keys( $this->client->get_style_defaults() );
                $received   = [];
                foreach ( $style_keys as $style_key ) {
                    if ( isset( $_POST[ $style_key ] ) ) {
                        $received[] = $style_key;
                    }
                }
                dsb_log( 'info', 'Saving style settings', [ 'keys' => $received, 'count' => count( $received ) ] );
            }

                $debug_requested = isset( $_POST['debug_enabled'] ) && '1' === (string) ( is_array( $_POST['debug_enabled'] ) ? end( $_POST['debug_enabled'] ) : $_POST['debug_enabled'] );
                if ( $debug_requested && function_exists( __NAMESPACE__ . '\\dsb_is_production_env' ) && dsb_is_production_env() && function_exists( __NAMESPACE__ . '\\dsb_is_log_path_public' ) && dsb_is_log_path_public( dsb_get_log_dir() ) ) {
                    $_POST['debug_enabled'] = '0';
                    $this->add_notice( __( 'Debug logging disabled because the log directory is publicly accessible. Configure a non-public path.', 'pixlab-license-bridge' ), 'error' );
                }

                $this->client->save_settings( wp_unslash( $_POST ) );
                if ( isset( $_POST['dsb_plan_slug_meta'] ) && is_array( $_POST['dsb_plan_slug_meta'] ) ) {
                    foreach ( $_POST['dsb_plan_slug_meta'] as $product_id => $slug ) {
                        $pid = absint( $product_id );
                        if ( $pid > 0 ) {
                            $normalized = dsb_normalize_plan_slug( wp_unslash( $slug ) );
                            update_post_meta( $pid, '_dsb_plan_slug', $normalized );
                        }
                    }
                }
                $updated_settings = $this->client->get_settings();

                if ( ! empty( $previous_settings['debug_enabled'] ) && empty( $updated_settings['debug_enabled'] ) ) {
                    $this->add_notice( __( 'Debug disabled. Logging stopped.', 'pixlab-license-bridge' ) );
                } elseif ( empty( $previous_settings['debug_enabled'] ) && ! empty( $updated_settings['debug_enabled'] ) ) {
                    dsb_ensure_log_dir();
                    dsb_log( 'info', 'Debug enabled' );
                }

                if ( 'cron' === $tab ) {
                    $cron_keys = [
                        'enable_purge_worker',
                        'enable_cron_debug_purge_worker',
                        'enable_node_poll_sync',
                        'node_poll_delete_stale',
                        'enable_cron_debug_node_poll',
                        'enable_daily_resync',
                        'resync_disable_non_active',
                        'enable_cron_debug_resync',
                    ];

                    dsb_log( 'debug', 'Cron settings saved', [
                        'updated_keys' => array_values( array_intersect( $cron_keys, array_keys( $_POST ) ) ),
                    ] );
                }

                dsb_log( 'info', 'Settings saved', [ 'tab' => $tab, 'posted_keys' => array_keys( $_POST ) ] );
                $this->add_notice( __( 'Settings saved.', 'pixlab-license-bridge' ) );
            }

        if ( isset( $_POST['dsb_test_connection'] ) && check_admin_referer( 'dsb_test_connection' ) ) {
            $result = $this->client->test_connection();
            $code   = $result['code'] ?? 0;
            $ping_code = $result['ping_code'] ?? 0;
            $is_ping_404 = 404 === $ping_code || ( 404 === $code && ( $result['endpoint'] ?? '' ) === '/internal/ping' );
            if ( is_wp_error( $result['response'] ?? null ) ) {
                $this->add_notice( $result['response']->get_error_message(), 'error' );
            } elseif ( $code >= 200 && $code < 300 ) {
                $this->add_notice( __( 'Connection successful.', 'pixlab-license-bridge' ) );
            } elseif ( 401 === $code || 403 === $code ) {
                $this->add_notice( __( 'Token/IP allowlist mismatch or unauthorized.', 'pixlab-license-bridge' ), 'error' );
            } elseif ( 404 === $code && $is_ping_404 ) {
                $this->add_notice( __( 'Your PixLab server is outdated; update PixLab app.', 'pixlab-license-bridge' ), 'error' );
            } else {
                $this->add_notice( __( 'Connection failed. Check URL/token.', 'pixlab-license-bridge' ), 'error' );
            }
        }

        if ( 'settings' === $tab && isset( $_POST['dsb_request_log_diagnostics_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_request_log_diagnostics_nonce'] ) ), 'dsb_request_log_diagnostics' ) ) {
            $this->diagnostics_result = $this->run_request_log_diagnostics();
        }

        if ( 'plan-mapping' === $tab && ( $plan_mapping_nonce_valid || ( isset( $_POST['dsb_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_settings_nonce'] ) ), 'dsb_save_settings' ) ) ) ) {
            $plans = [];
            if ( isset( $_POST['level_plans'] ) && is_array( $_POST['level_plans'] ) ) {
                foreach ( $_POST['level_plans'] as $level_id => $plan_slug ) {
                    $lid = absint( $level_id );
                    $slug = dsb_normalize_plan_slug( sanitize_text_field( wp_unslash( $plan_slug ) ) );
                    if ( $lid > 0 && '' !== $slug ) {
                        $plans[ $lid ] = $slug;
                    }
                }
            }

            $settings = $this->client->get_settings();
            $this->client->save_settings(
                [
                    'level_plans' => $plans,
                    'node_base_url' => $settings['node_base_url'],
                    'bridge_token'  => $settings['bridge_token'],
                    'enable_logging'=> $settings['enable_logging'],
                    'delete_data'   => $settings['delete_data'],
                    'allow_provision_without_refs' => $settings['allow_provision_without_refs'],
                ]
            );
            $this->add_notice( __( 'Plan mappings saved.', 'pixlab-license-bridge' ) );
        }

        if ( 'cron' === $tab && isset( $_POST['dsb_run_purge_worker'] ) && check_admin_referer( 'dsb_run_purge_worker' ) ) {
            $result = $this->purge_worker->run( true );
            $status = $result['status'] ?? '';

            if ( 'ok' === $status ) {
                $processed = isset( $result['processed'] ) ? (int) $result['processed'] : 0;
                $this->add_notice( sprintf( __( 'Purge worker ran successfully. Processed %d jobs.', 'pixlab-license-bridge' ), $processed ) );
            } elseif ( 'skipped_locked' === $status ) {
                $this->add_notice( __( 'Purge worker skipped because a lock is active.', 'pixlab-license-bridge' ), 'error' );
            } elseif ( 'skipped_disabled' === $status ) {
                $this->add_notice( __( 'Purge worker is disabled.', 'pixlab-license-bridge' ), 'error' );
            } else {
                $error_message = isset( $result['error'] ) ? $result['error'] : __( 'Unexpected error', 'pixlab-license-bridge' );
                $this->add_notice( sprintf( __( 'Purge worker failed: %s', 'pixlab-license-bridge' ), sanitize_text_field( $error_message ) ), 'error' );
            }
        }

        if ( 'cron' === $tab && isset( $_POST['dsb_run_provision_worker'] ) && check_admin_referer( 'dsb_run_provision_worker' ) ) {
            $result = $this->provision_worker->run( true );
            $status = $result['status'] ?? '';

            if ( 'ok' === $status ) {
                $processed = isset( $result['processed'] ) ? (int) $result['processed'] : 0;
                $this->add_notice( sprintf( __( 'Provision worker ran successfully. Processed %d jobs.', 'pixlab-license-bridge' ), $processed ) );
            } elseif ( 'skipped_locked' === $status ) {
                $this->add_notice( __( 'Provision worker skipped because a lock is active.', 'pixlab-license-bridge' ), 'error' );
            } else {
                $error_message = isset( $result['error'] ) ? $result['error'] : __( 'Unexpected error', 'pixlab-license-bridge' );
                $this->add_notice( sprintf( __( 'Provision worker failed: %s', 'pixlab-license-bridge' ), sanitize_text_field( $error_message ) ), 'error' );
            }
        }

        if ( 'cron' === $tab && isset( $_POST['dsb_clear_purge_lock'] ) && check_admin_referer( 'dsb_clear_purge_lock' ) ) {
            $lock_until = (int) get_option( DSB_Purge_Worker::OPTION_LOCK_UNTIL, 0 );
            if ( $lock_until > time() ) {
                $this->add_notice( __( 'Purge worker lock is still active; not cleared.', 'pixlab-license-bridge' ), 'error' );
            } elseif ( $lock_until <= 0 ) {
                $this->add_notice( __( 'Purge worker is not locked.', 'pixlab-license-bridge' ) );
            } else {
                $this->purge_worker->clear_lock();
                $this->add_notice( __( 'Purge lock cleared.', 'pixlab-license-bridge' ) );
            }
        }

        if ( 'cron' === $tab && isset( $_POST['dsb_clear_provision_lock'] ) && check_admin_referer( 'dsb_clear_provision_lock' ) ) {
            $lock_until = (int) get_option( DSB_Provision_Worker::OPTION_LOCK_UNTIL, 0 );
            if ( $lock_until > time() ) {
                $this->add_notice( __( 'Provision worker lock is still active; not cleared.', 'pixlab-license-bridge' ), 'error' );
            } elseif ( $lock_until <= 0 ) {
                $this->add_notice( __( 'Provision worker is not locked.', 'pixlab-license-bridge' ) );
            } else {
                $this->provision_worker->clear_lock();
                $this->add_notice( __( 'Provision lock cleared.', 'pixlab-license-bridge' ) );
            }
        }

        if ( 'cron' === $tab && isset( $_POST['dsb_clear_node_poll_lock'] ) && check_admin_referer( 'dsb_clear_node_poll_lock' ) ) {
            $lock_until = (int) get_option( DSB_Node_Poll::OPTION_LOCK_UNTIL, 0 );
            if ( $lock_until > time() ) {
                $this->add_notice( __( 'Node poll lock is still active; not cleared.', 'pixlab-license-bridge' ), 'error' );
            } elseif ( $lock_until <= 0 ) {
                $this->add_notice( __( 'Node poll is not locked.', 'pixlab-license-bridge' ) );
            } else {
                $this->node_poll->clear_lock();
                $this->add_notice( __( 'Node poll lock cleared.', 'pixlab-license-bridge' ) );
            }
        }

        if ( 'cron' === $tab && isset( $_POST['dsb_clear_resync_lock'] ) && check_admin_referer( 'dsb_clear_resync_lock' ) ) {
            $lock_until = (int) get_option( DSB_Resync::OPTION_LOCK_UNTIL, 0 );
            if ( $lock_until > time() ) {
                $this->add_notice( __( 'Resync lock is still active; not cleared.', 'pixlab-license-bridge' ), 'error' );
            } elseif ( $lock_until <= 0 ) {
                $this->add_notice( __( 'Resync job is not locked.', 'pixlab-license-bridge' ) );
            } else {
                $this->resync->clear_lock();
                $this->add_notice( __( 'Resync lock cleared.', 'pixlab-license-bridge' ) );
            }
        }

        if ( 'cron' === $tab && isset( $_POST['dsb_clear_cron_log'] ) && check_admin_referer( 'dsb_clear_cron_log' ) ) {
            $job = sanitize_key( wp_unslash( $_POST['dsb_clear_cron_log'] ) );
            DSB_Cron_Logger::clear( $job );
            $this->add_notice( __( 'Cron debug log cleared.', 'pixlab-license-bridge' ) );
        }

        if ( 'keys' === $tab ) {
            $this->handle_key_actions();
        }

        if ( isset( $_POST['dsb_sync_plans'] ) && check_admin_referer( 'dsb_sync_plans' ) ) {
            $summary = $this->sync_plans_to_node();
            $message = sprintf(
                /* translators: 1: success count, 2: failure count */
                esc_html__( 'Plan sync completed. Success: %1$d, Failed: %2$d', 'pixlab-license-bridge' ),
                isset( $summary['count_success'] ) ? (int) $summary['count_success'] : 0,
                isset( $summary['count_failed'] ) ? (int) $summary['count_failed'] : 0
            );
            $this->add_notice( $message, isset( $summary['count_failed'] ) && $summary['count_failed'] > 0 ? 'error' : 'success' );
        }
    }

    public function handle_run_resync_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to run this action.', 'pixlab-license-bridge' ) );
        }

        $nonce = isset( $_POST['dsb_run_resync_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['dsb_run_resync_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'dsb_run_resync_now' ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'pixlab-license-bridge' ) );
        }

        $result = $this->resync->run( true );

        $target_tab = isset( $_POST['dsb_target_tab'] ) ? sanitize_key( wp_unslash( $_POST['dsb_target_tab'] ) ) : 'settings';

        $args = [
            'page'              => 'davix-bridge',
            'tab'               => $target_tab ?: 'settings',
            'dsb_resync_status' => $result['status'] ?? 'ok',
        ];

        if ( ! empty( $result['error'] ) ) {
            $args['dsb_resync_message'] = substr( (string) $result['error'], 0, 250 );
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_run_node_poll_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to run this action.', 'pixlab-license-bridge' ) );
        }

        $nonce = isset( $_POST['dsb_run_node_poll_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['dsb_run_node_poll_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'dsb_run_node_poll_now' ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'pixlab-license-bridge' ) );
        }

        $result = $this->node_poll->run_once();

        $target_tab = isset( $_POST['dsb_target_tab'] ) ? sanitize_key( wp_unslash( $_POST['dsb_target_tab'] ) ) : 'settings';

        $args = [
            'page'                 => 'davix-bridge',
            'tab'                  => $target_tab ?: 'settings',
            'dsb_node_poll_status' => $result['status'] ?? 'ok',
        ];

        if ( ! empty( $result['error'] ) ) {
            $args['dsb_node_poll_message'] = substr( (string) $result['error'], 0, 250 );
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    protected function handle_key_actions(): void {
        if ( isset( $_POST['dsb_manual_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_manual_nonce'] ) ), 'dsb_manual_key' ) ) {
            $wp_user_id   = isset( $_POST['wp_user_id'] ) ? absint( $_POST['wp_user_id'] ) : 0;
            $level_id     = isset( $_POST['pmpro_level_id'] ) ? absint( $_POST['pmpro_level_id'] ) : 0;
            $valid_from_raw  = isset( $_POST['valid_from'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_from'] ) ) : '';
            $valid_until_raw = isset( $_POST['valid_until'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_until'] ) ) : '';

            $valid_from  = DSB_Util::to_iso_utc( $valid_from_raw );
            $valid_until = DSB_Util::to_iso_utc( $valid_until_raw );

            if ( $valid_from_raw && ! $valid_from ) {
                $this->add_notice( __( 'Invalid Valid From date. Please use a valid date/time.', 'pixlab-license-bridge' ), 'error' );
                return;
            }

            if ( $valid_until_raw && ! $valid_until ) {
                $this->add_notice( __( 'Invalid Valid Until date. Please use a valid date/time.', 'pixlab-license-bridge' ), 'error' );
                return;
            }

            if ( $valid_from && $valid_until && strtotime( $valid_until ) < strtotime( $valid_from ) ) {
                $this->add_notice( __( 'Valid Until must be after Valid From.', 'pixlab-license-bridge' ), 'error' );
                return;
            }

            if ( $wp_user_id <= 0 || $level_id <= 0 ) {
                $this->add_notice( __( 'User and membership level are required.', 'pixlab-license-bridge' ), 'error' );
                return;
            }

            $user = get_user_by( 'id', $wp_user_id );
            if ( ! $user instanceof \WP_User || ! $user->user_email ) {
                $this->add_notice( __( 'Selected user is invalid or missing an email.', 'pixlab-license-bridge' ), 'error' );
                return;
            }

            $customer_email = sanitize_email( (string) $user->user_email );
            if ( ! $customer_email ) {
                $this->add_notice( __( 'Unable to determine customer email for the selected user.', 'pixlab-license-bridge' ), 'error' );
                return;
            }

            $plan_slug = $this->client->plan_slug_for_level( $level_id );
            if ( '' === $plan_slug ) {
                $this->add_notice( __( 'No plan slug is mapped to the selected PMPro level. Please configure Plan Mapping.', 'pixlab-license-bridge' ), 'error' );
                return;
            }

            if ( ! function_exists( 'pmpro_changeMembershipLevel' ) ) {
                $this->add_notice( __( 'Paid Memberships Pro is required to change membership levels.', 'pixlab-license-bridge' ), 'error' );
                return;
            }

            $membership_changed = pmpro_changeMembershipLevel( $level_id, $wp_user_id );
            if ( is_wp_error( $membership_changed ) || ! $membership_changed ) {
                $message = is_wp_error( $membership_changed ) ? $membership_changed->get_error_message() : __( 'Unable to change membership level.', 'pixlab-license-bridge' );
                $this->add_notice( $message, 'error' );
                return;
            }

            $subscription_id = sprintf( 'pmpro-%d-%d', $wp_user_id, $level_id );
            $payload         = [
                'customer_email'  => strtolower( $customer_email ),
                'plan_slug'       => $plan_slug,
                'wp_user_id'      => $wp_user_id,
                'subscription_id' => $subscription_id,
            ];

            if ( $valid_from ) {
                $payload['valid_from'] = $valid_from;
            }

            if ( $valid_until ) {
                $payload['valid_until'] = $valid_until;
            }

            $response = $this->client->provision_key( $payload );
            $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
            $decoded  = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
            $status_value = is_array( $decoded ) && isset( $decoded['status'] ) ? strtolower( (string) $decoded['status'] ) : '';
            $success_statuses = [ 'ok', 'active', 'disabled' ];

            if ( ! is_wp_error( $response ) && $code >= 200 && $code < 300 && in_array( $status_value, $success_statuses, true ) ) {
                $message = __( 'PMPro level updated and API key provisioned.', 'pixlab-license-bridge' );
                if ( ! empty( $decoded['key'] ) ) {
                    $message .= ' ' . __( 'Copy now:', 'pixlab-license-bridge' ) . ' ' . sanitize_text_field( $decoded['key'] );
                }
                $this->add_notice( $message );
            } else {
                $error_message = __( 'PMPro level updated but API key provisioning failed.', 'pixlab-license-bridge' );
                if ( is_wp_error( $response ) ) {
                    $error_message .= ' ' . $response->get_error_message();
                } elseif ( is_array( $decoded ) ) {
                    $error_message .= ' ' . wp_json_encode( $decoded );
                }
                $this->add_notice( $error_message, 'error' );
                dsb_log(
                    'error',
                    'Manual provisioning failed after membership change',
                    [
                        'user_id'   => $wp_user_id,
                        'level_id'  => $level_id,
                        'http_code' => $code,
                        'decoded'   => $decoded,
                    ]
                );
            }
        }

        if ( isset( $_POST['dsb_reprovision_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_reprovision_nonce'] ) ), 'dsb_reprovision_key' ) ) {
            $requested_user_id = isset( $_POST['reprovision_user_id'] ) ? absint( $_POST['reprovision_user_id'] ) : 0;
            $user_id = $requested_user_id ?: get_current_user_id();

            if ( $user_id <= 0 ) {
                $this->add_notice( __( 'Unable to resolve a user for re-provisioning.', 'pixlab-license-bridge' ), 'error' );
            } elseif ( ! class_exists( __NAMESPACE__ . '\\DSB_PMPro_Events' ) ) {
                $this->add_notice( __( 'PMPro integration is not available for re-provisioning.', 'pixlab-license-bridge' ), 'error' );
            } else {
                $user = get_userdata( $user_id );
                $emails = [];
                if ( $user instanceof \WP_User && $user->user_email ) {
                    $emails[] = sanitize_email( $user->user_email );
                }

                $this->db->delete_user_rows_local( $user_id, $emails, [] );
                dsb_log( 'info', 'Admin re-provision cleared local records', [ 'user_id' => $user_id ] );

                $payload = DSB_PMPro_Events::build_active_payload_for_user( $user_id );
                if ( ! $payload ) {
                    $this->add_notice( __( 'Unable to build a PMPro payload for the selected user.', 'pixlab-license-bridge' ), 'error' );
                } else {
                    $dispatch = DSB_PMPro_Events::dispatch_provision_payload( $payload, 'admin_reprovision' );
                    if ( ! empty( $dispatch['success'] ) ) {
                        $this->add_notice( __( 'Re-provision request sent to PixLab.', 'pixlab-license-bridge' ) );
                    } else {
                        $error_message = __( 'Re-provision request failed and was queued for retry.', 'pixlab-license-bridge' );
                        if ( isset( $dispatch['decoded']['status'] ) ) {
                            $error_message .= ' ' . sanitize_text_field( (string) $dispatch['decoded']['status'] );
                        }
                        $this->add_notice( $error_message, 'error' );
                    }
                }
            }
        }

        if ( isset( $_POST['dsb_key_action_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_key_action_nonce'] ) ), 'dsb_key_action' ) ) {
            $action          = isset( $_POST['dsb_action'] ) ? sanitize_key( wp_unslash( $_POST['dsb_action'] ) ) : '';
            $subscription_id = isset( $_POST['subscription_id'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription_id'] ) ) : '';
            $customer_email  = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
            $wp_user_id      = isset( $_POST['wp_user_id'] ) ? absint( $_POST['wp_user_id'] ) : 0;
            $api_key_id      = isset( $_POST['api_key_id'] ) ? absint( $_POST['api_key_id'] ) : 0;

            if ( 'disable' === $action ) {
                $response = $this->client->disable_key(
                    [
                        'subscription_id' => $subscription_id,
                        'customer_email'  => $customer_email,
                    ]
                );
                $this->handle_key_response( $response, __( 'Key disabled.', 'pixlab-license-bridge' ) );
            } elseif ( 'rotate' === $action ) {
                $response = $this->client->rotate_key(
                    [
                        'subscription_id' => $subscription_id,
                        'customer_email'  => $customer_email,
                    ]
                );
                $this->handle_key_response( $response, __( 'Key rotated.', 'pixlab-license-bridge' ) );
            } elseif ( 'purge' === $action ) {
                if ( ! $api_key_id ) {
                    $this->add_notice( __( 'Cannot purge: missing api_key_id for this key.', 'pixlab-license-bridge' ), 'error' );
                    dsb_log(
                        'error',
                        'Admin purge aborted due to missing api_key_id',
                        [
                            'subscription_id' => $subscription_id,
                            'customer_email'  => $customer_email,
                            'wp_user_id'      => $wp_user_id ?: null,
                        ]
                    );
                    return;
                }

                dsb_log(
                    'info',
                    'Admin purge requested',
                    [
                        'api_key_id'      => $api_key_id,
                        'subscription_id' => $subscription_id,
                        'customer_email'  => $customer_email,
                        'wp_user_id'      => $wp_user_id ?: null,
                    ]
                );
                $job_id = $this->db->enqueue_purge_job(
                    [
                        'wp_user_id'      => $wp_user_id ?: null,
                        'customer_email'  => $customer_email,
                        'subscription_id' => $subscription_id,
                        'api_key_id'      => $api_key_id,
                        'reason'          => 'admin_purge',
                    ]
                );
                $this->purge_worker->run_once();
                $this->add_notice( sprintf( __( 'Purge enqueued (job #%d).', 'pixlab-license-bridge' ), $job_id ?: 0 ) );
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

        $status_value = is_array( $decoded ) && isset( $decoded['status'] ) ? strtolower( (string) $decoded['status'] ) : '';

        if ( $code >= 200 && $code < 300 && is_array( $decoded ) && in_array( $status_value, [ 'ok', 'active', 'disabled' ], true ) ) {
            $message = $success_message;
            if ( ! empty( $decoded['key'] ) ) {
                $message .= ' ' . __( 'Copy now:', 'pixlab-license-bridge' ) . ' ' . sanitize_text_field( $decoded['key'] );
            }
            $this->add_notice( $message );
        } else {
            $this->add_notice( __( 'Request failed', 'pixlab-license-bridge' ) . ' ' . wp_json_encode( $decoded ), 'error' );
        }
    }

    public function render_page(): void {
        if ( ! $this->current_user_can_manage_settings() ) {
            wp_die(
                esc_html__( 'You are not allowed to access this page.', 'pixlab-license-bridge' ),
                esc_html__( 'Access denied', 'pixlab-license-bridge' ),
                [ 'response' => 403 ]
            );
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

        echo '<div class="wrap dsb-admin-page">';
        $tabs = [
            'settings'     => __( 'General', 'pixlab-license-bridge' ),
            'style'        => __( 'Style', 'pixlab-license-bridge' ),
            'plan-mapping' => __( 'Plan', 'pixlab-license-bridge' ),
            'keys'         => __( 'Keys', 'pixlab-license-bridge' ),
            'cron'         => __( 'Cron Jobs', 'pixlab-license-bridge' ),
            'alerts'       => __( 'Alert', 'pixlab-license-bridge' ),
            'logs'         => __( 'Logs', 'pixlab-license-bridge' ),
            'debug'        => __( 'Debug', 'pixlab-license-bridge' ),
        ];
        $logo_path = DSB_PLUGIN_DIR . 'assets/logo/logo-64.png';
        $logo_url  = DSB_PLUGIN_URL . 'assets/logo/logo-64.png';
        echo '<div class="dsb-shell">';
        echo '<div class="dsb-hero-header">';
        echo '<div class="dsb-hero-top">';
        echo '<div class="dsb-hero-left">';
        if ( file_exists( $logo_path ) ) {
            printf( '<img src="%s" alt="%s" class="dsb-hero-logo" />', esc_url( $logo_url ), esc_attr__( 'PixLab', 'pixlab-license-bridge' ) );
        }
        echo '<span class="dsb-hero-badge">' . esc_html__( 'PixLab License Bridge', 'pixlab-license-bridge' ) . '</span>';
        echo '</div>';
        echo '</div>';
        echo '<nav class="dsb-hero-tabs" aria-label="' . esc_attr__( 'PixLab License Bridge tabs', 'pixlab-license-bridge' ) . '">';
        foreach ( $tabs as $key => $label ) {
            $class = $tab === $key ? 'dsb-hero-tab is-active' : 'dsb-hero-tab';
            printf( '<a href="%s" class="%s">%s</a>', esc_url( add_query_arg( [ 'page' => 'davix-bridge', 'tab' => $key ], admin_url( 'admin.php' ) ) ), esc_attr( $class ), esc_html( $label ) );
        }
        echo '</nav>';
        echo '</div>';
        echo '</div>';

        $settings = $this->client->get_settings();
        $server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
        if ( ! empty( $settings['debug_enabled'] ) && $server_software && false !== stripos( $server_software, 'nginx' ) ) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html__( 'You must deny web access to PixLab License log directories in Nginx (see docs).', 'pixlab-license-bridge' )
            );
        }

        $this->maybe_add_settings_access_safe_mode_notice();

        foreach ( $this->notices as $notice ) {
            printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ), esc_html( $notice['message'] ) );
        }

        echo '<div id="dsb-tab-content">';
        $this->render_tab_content( $tab );
        echo '</div>';

        echo '</div>';
    }

    protected function render_tab_content( string $tab ): void {
        if ( 'plan-mapping' === $tab ) {
            $this->render_plan_tab();
        } elseif ( 'keys' === $tab ) {
            $this->render_keys_tab();
        } elseif ( 'logs' === $tab ) {
            $this->render_logs_tab();
        } elseif ( 'style' === $tab ) {
            $this->render_style_tab();
        } elseif ( 'debug' === $tab ) {
            $this->render_debug_tab();
        } elseif ( 'cron' === $tab ) {
            $this->render_cron_tab();
        } elseif ( 'alerts' === $tab ) {
            $this->render_alerts_tab();
        } else {
            $this->render_settings_tab();
        }
    }

    public function ajax_render_tab(): void {
        check_ajax_referer( 'dsb_admin_ajax', 'nonce' );
        if ( ! $this->current_user_can_manage_settings() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'pixlab-license-bridge' ) ], 403 );
        }

        $tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'settings';

        ob_start();
        $this->render_tab_content( $tab );
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    protected function render_settings_tab(): void {
        $settings = $this->client->get_settings();
        $has_external_token = $this->client->has_external_bridge_token();
        $settings_locked = $this->client->is_settings_locked();
        $settings_access = isset( $settings['settings_access'] ) && is_array( $settings['settings_access'] ) ? $settings['settings_access'] : [];
        $settings_access_enabled = ! empty( $settings_access['enabled'] );
        $allowed_roles = $this->normalize_role_keys( $settings_access['allowed_roles'] ?? [] );
        $editable_roles = function_exists( 'get_editable_roles' ) ? get_editable_roles() : [];
        $role_labels = [];
        foreach ( $editable_roles as $role_key => $role_data ) {
            $role_labels[ $role_key ] = isset( $role_data['name'] ) ? (string) $role_data['name'] : $role_key;
        }
        ?>
        <form method="post">
            <?php wp_nonce_field( 'dsb_save_settings', 'dsb_settings_nonce' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Node Base URL', 'pixlab-license-bridge' ); ?></th>
                    <td><input type="url" name="node_base_url" class="regular-text" value="<?php echo esc_attr( $settings['node_base_url'] ); ?>" placeholder="https://pixlab.davix.dev" required /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Bridge Token', 'pixlab-license-bridge' ); ?></th>
                    <td>
                        <?php if ( $has_external_token ) : ?>
                            <p class="description"><?php esc_html_e( 'Bridge token managed via wp-config.php or environment variable (DSB_BRIDGE_TOKEN).', 'pixlab-license-bridge' ); ?></p>
                        <?php elseif ( $settings_locked ) : ?>
                            <input type="password" class="regular-text" value="" autocomplete="off" disabled />
                            <p class="description"><?php esc_html_e( 'Bridge token locked in production. Set DSB_BRIDGE_TOKEN in wp-config.php or environment variables.', 'pixlab-license-bridge' ); ?></p>
                        <?php else : ?>
                            <input type="password" name="bridge_token" class="regular-text" value="" autocomplete="off" />
                            <p class="description"><?php printf( '%s %s', esc_html__( 'Stored securely, masked in UI.', 'pixlab-license-bridge' ), esc_html( $this->client->masked_token() ) ); ?></p>
                            <p class="description"><?php esc_html_e( 'Leave blank to keep existing token.', 'pixlab-license-bridge' ); ?></p>
                            <label><input type="checkbox" name="bridge_token_clear" value="1" /> <?php esc_html_e( 'Clear token', 'pixlab-license-bridge' ); ?></label>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Free Level ID', 'pixlab-license-bridge' ); ?></th>
                    <td>
                        <input type="number" name="free_level_id" class="regular-text" value="<?php echo esc_attr( $settings['free_level_id'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. 1', 'pixlab-license-bridge' ); ?>" />
                        <p class="description"><?php esc_html_e( 'PMPro level ID to assign automatically on user signup. Leave blank to use the first level marked as Free.', 'pixlab-license-bridge' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Settings page access', 'pixlab-license-bridge' ); ?></th>
                    <td>
                        <div class="dsb-settings-access">
                            <input type="hidden" name="settings_access[enabled]" value="0" />
                            <label for="dsb-settings-access-enabled">
                                <input type="checkbox" id="dsb-settings-access-enabled" name="settings_access[enabled]" value="1" <?php checked( $settings_access_enabled ); ?> />
                                <?php esc_html_e( 'Enable Settings Page Access Restriction', 'pixlab-license-bridge' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Controls who can access this settings page in the WordPress admin. It does not affect subscription sync behavior.', 'pixlab-license-bridge' ); ?></p>

                            <div class="dsb-settings-access__roles<?php echo $settings_access_enabled ? '' : ' is-hidden'; ?>">
                                <label class="dsb-settings-access__label" for="dsb-settings-access-role-select"><?php esc_html_e( 'Allowed roles', 'pixlab-license-bridge' ); ?></label>
                                <div class="dsb-settings-access__picker">
                                    <div class="dsb-settings-access-chips" aria-live="polite">
                                        <?php foreach ( $allowed_roles as $role_key ) : ?>
                                            <?php $role_label = $role_labels[ $role_key ] ?? $role_key; ?>
                                            <span class="dsb-settings-access-chip" data-role="<?php echo esc_attr( $role_key ); ?>">
                                                <span class="dsb-settings-access-chip__label"><?php echo esc_html( $role_label ); ?></span>
                                                <button type="button" class="dsb-settings-access-chip__remove" data-role-remove="<?php echo esc_attr( $role_key ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s', 'pixlab-license-bridge' ), $role_label ) ); ?>">×</button>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="dsb-settings-access-select-wrap">
                                        <select id="dsb-settings-access-role-select" class="dsb-settings-access-select">
                                            <option value=""><?php esc_html_e( 'Select a role', 'pixlab-license-bridge' ); ?></option>
                                            <?php foreach ( $role_labels as $role_key => $role_label ) : ?>
                                                <option value="<?php echo esc_attr( $role_key ); ?>"><?php echo esc_html( $role_label ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <p class="description"><?php esc_html_e( 'Optional role restrictions for the settings page. Leave empty to fall back to manage_options when restrictions are enabled.', 'pixlab-license-bridge' ); ?></p>
                                <div class="dsb-settings-access-inputs">
                                    <?php foreach ( $allowed_roles as $role_key ) : ?>
                                        <input type="hidden" name="settings_access[allowed_roles][]" value="<?php echo esc_attr( $role_key ); ?>" />
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Delete data on uninstall', 'pixlab-license-bridge' ); ?></th>
                    <td>
                        <input type="hidden" name="delete_data" value="0" />
                        <label><input type="checkbox" name="delete_data" value="1" <?php checked( $settings['delete_data'], 1 ); ?> /> <?php esc_html_e( 'Drop plugin tables/options on uninstall', 'pixlab-license-bridge' ); ?></label>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <form method="post" style="margin-top:20px;">
            <?php wp_nonce_field( 'dsb_test_connection' ); ?>
            <?php submit_button( __( 'Test Connection', 'pixlab-license-bridge' ), 'secondary', 'dsb_test_connection', false ); ?>
        </form>

        <h2><?php esc_html_e( 'Request Log Diagnostics', 'pixlab-license-bridge' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Fetches the latest Node request log payload for debugging. Output is masked for sensitive fields and not stored.', 'pixlab-license-bridge' ); ?></p>
        <form method="post" style="margin-top:10px;">
            <?php wp_nonce_field( 'dsb_request_log_diagnostics', 'dsb_request_log_diagnostics_nonce' ); ?>
            <?php submit_button( __( 'Run Diagnostics', 'pixlab-license-bridge' ), 'secondary', 'dsb_request_log_diagnostics', false ); ?>
        </form>
        <?php if ( $this->diagnostics_result ) : ?>
            <div class="dsb-diagnostics-output" style="margin-top:15px;">
                <p><strong><?php esc_html_e( 'HTTP code:', 'pixlab-license-bridge' ); ?></strong> <?php echo esc_html( $this->diagnostics_result['code'] ?? '' ); ?></p>
                <?php if ( ! empty( $this->diagnostics_result['body'] ) ) : ?>
                    <textarea class="large-text code" rows="12" readonly><?php echo esc_textarea( $this->diagnostics_result['body'] ); ?></textarea>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    protected function render_style_tab(): void {
        $styles = $this->client->get_style_settings();
        $labels = $this->client->get_label_settings();
        ?>
        <form method="post" class="dsb-style-form">
            <?php wp_nonce_field( 'dsb_save_settings', 'dsb_settings_nonce' ); ?>

            <div class="dsb-style-save-bar">
                <?php submit_button( __( 'Save Changes', 'pixlab-license-bridge' ), 'primary', 'submit', false ); ?>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'Dashboard Background', 'pixlab-license-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Control the global background behind the dashboard.', 'pixlab-license-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_color_input_field( 'style_dashboard_bg', __( 'Dashboard Background Color', 'pixlab-license-bridge' ), $styles['style_dashboard_bg'], __( 'Background behind all dashboard sections in the [PIXLAB_DASHBOARD] shortcode.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_dashboard_shadow_color', __( 'Dashboard Shadow Color', 'pixlab-license-bridge' ), $styles['style_dashboard_shadow_color'] ?? '', __( 'Shadow color applied to the outer dashboard container.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_dashboard_border_color', __( 'Dashboard Border Color', 'pixlab-license-bridge' ), $styles['style_dashboard_border_color'] ?? '', __( 'Border color around the outer dashboard container.', 'pixlab-license-bridge' ) );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'Header Styles', 'pixlab-license-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Single set of controls for the top header area (eyebrow, plan title, meta, billing).', 'pixlab-license-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_color_input_field( 'style_header_text', __( 'Header Text Color (all header text)', 'pixlab-license-bridge' ), $styles['style_header_text'], __( 'Applies to eyebrow, plan title, meta, and billing lines.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_header_plan_title_color', __( 'Plan Name Color', 'pixlab-license-bridge' ), $styles['style_header_plan_title_color'] ?? '', __( 'Optional: explicit color for the plan name. Falls back to header text if empty.', 'pixlab-license-bridge' ) );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'Cards Styles', 'pixlab-license-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Unified controls for all cards (API Key, Usage, Endpoints, History).', 'pixlab-license-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_color_input_field( 'style_cards_bg', __( 'Cards Background', 'pixlab-license-bridge' ), $styles['style_cards_bg'], __( 'Background for all dashboard cards.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_cards_border', __( 'Cards Border', 'pixlab-license-bridge' ), $styles['style_cards_border'], __( 'Border color for all cards.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_cards_shadow_color', __( 'Cards Shadow Color', 'pixlab-license-bridge' ), $styles['style_cards_shadow_color'], __( 'Shadow color for card containers.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_cards_text', __( 'Cards Text Color (all card text)', 'pixlab-license-bridge' ), $styles['style_cards_text'], __( 'Applies to card headers, labels, hints, endpoint eyebrows, and usage labels.', 'pixlab-license-bridge' ) );
                    // Expose card hint color so modal messages and card hints can be customized.
                    $this->render_color_input_field(
                        'style_card_hint_color',
                        __( 'Card Hint Color (optional)', 'pixlab-license-bridge' ),
                        $styles['style_card_hint_color'] ?? '',
                        __( 'Optional: color for small hint text inside cards and modal helper messages. Falls back to card text if empty.', 'pixlab-license-bridge' )
                    );

                    // Expose endpoint eyebrow color (optional) so endpoint eyebrow can be overridden.
                    $this->render_color_input_field(
                        'style_endpoint_eyebrow_color',
                        __( 'Endpoint Eyebrow Color (optional)', 'pixlab-license-bridge' ),
                        $styles['style_endpoint_eyebrow_color'] ?? '',
                        __( 'Optional: explicit color for endpoint eyebrow labels (e.g., H2I, PDF). Falls back to card text if empty.', 'pixlab-license-bridge' )
                    );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'API Key Field', 'pixlab-license-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Full visual control of the API key input, allowing light or dark themes.', 'pixlab-license-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_color_input_field( 'style_input_bg', __( 'API Key Input Background Color', 'pixlab-license-bridge' ), $styles['style_input_bg'], __( 'Background of the API key display field in the dashboard.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_input_text', __( 'API Key Input Text Color', 'pixlab-license-bridge' ), $styles['style_input_text'], __( 'Text color of the API key display field.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_input_border', __( 'API Key Input Border Color', 'pixlab-license-bridge' ), $styles['style_input_border'], __( 'Border color surrounding the API key display field.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_input_focus_border', __( 'API Key Input Focus Border Color', 'pixlab-license-bridge' ), $styles['style_input_focus_border'], __( 'Border color shown when the API key field is focused for copying.', 'pixlab-license-bridge' ) );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'Buttons', 'pixlab-license-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Primary (Regenerate), outline (Disable/Enable), and ghost (pagination/modal) with normal, hover, and active states.', 'pixlab-license-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    echo '<tr><th colspan="2"><strong>' . esc_html__( 'Primary Buttons', 'pixlab-license-bridge' ) . '</strong></th></tr>';
                    $this->render_color_input_field( 'style_btn_primary_normal_bg', __( 'Normal Background', 'pixlab-license-bridge' ), $styles['style_btn_primary_normal_bg'], __( 'Primary normal background.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_primary_normal_border', __( 'Normal Border', 'pixlab-license-bridge' ), $styles['style_btn_primary_normal_border'], __( 'Primary normal border.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_primary_normal_text', __( 'Normal Text', 'pixlab-license-bridge' ), $styles['style_btn_primary_normal_text'], __( 'Primary normal text.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_primary_hover_bg', __( 'Hover Background', 'pixlab-license-bridge' ), $styles['style_btn_primary_hover_bg'], __( 'Primary hover background.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_primary_hover_border', __( 'Hover Border', 'pixlab-license-bridge' ), $styles['style_btn_primary_hover_border'], __( 'Primary hover border.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_primary_hover_text', __( 'Hover Text', 'pixlab-license-bridge' ), $styles['style_btn_primary_hover_text'], __( 'Primary hover text.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_primary_active_bg', __( 'Active Background', 'pixlab-license-bridge' ), $styles['style_btn_primary_active_bg'], __( 'Primary active background.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_primary_active_border', __( 'Active Border', 'pixlab-license-bridge' ), $styles['style_btn_primary_active_border'], __( 'Primary active border.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_primary_active_text', __( 'Active Text', 'pixlab-license-bridge' ), $styles['style_btn_primary_active_text'], __( 'Primary active text.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_primary_shadow_color', __( 'Primary Shadow Color', 'pixlab-license-bridge' ), $styles['style_btn_primary_shadow_color'], __( 'Shadow color applied to primary buttons.', 'pixlab-license-bridge' ) );
                    $this->render_number_input_field( 'style_btn_primary_shadow_strength', __( 'Primary Shadow Strength', 'pixlab-license-bridge' ), $styles['style_btn_primary_shadow_strength'], __( 'Multiplier for primary button shadow blur (0 disables).', 'pixlab-license-bridge' ), 0.1, 0, 3, 'x' );

                    echo '<tr><th colspan="2"><strong>' . esc_html__( 'Outline Buttons', 'pixlab-license-bridge' ) . '</strong></th></tr>';
                    $this->render_color_input_field( 'style_btn_outline_normal_bg', __( 'Normal Background', 'pixlab-license-bridge' ), $styles['style_btn_outline_normal_bg'], __( 'Outline normal background.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_outline_normal_border', __( 'Normal Border', 'pixlab-license-bridge' ), $styles['style_btn_outline_normal_border'], __( 'Outline normal border.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_outline_normal_text', __( 'Normal Text', 'pixlab-license-bridge' ), $styles['style_btn_outline_normal_text'], __( 'Outline normal text.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_outline_hover_bg', __( 'Hover Background', 'pixlab-license-bridge' ), $styles['style_btn_outline_hover_bg'], __( 'Outline hover background.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_outline_hover_border', __( 'Hover Border', 'pixlab-license-bridge' ), $styles['style_btn_outline_hover_border'], __( 'Outline hover border.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_outline_hover_text', __( 'Hover Text', 'pixlab-license-bridge' ), $styles['style_btn_outline_hover_text'], __( 'Outline hover text.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_outline_active_bg', __( 'Active Background', 'pixlab-license-bridge' ), $styles['style_btn_outline_active_bg'], __( 'Outline active background.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_outline_active_border', __( 'Active Border', 'pixlab-license-bridge' ), $styles['style_btn_outline_active_border'], __( 'Outline active border.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_outline_active_text', __( 'Active Text', 'pixlab-license-bridge' ), $styles['style_btn_outline_active_text'], __( 'Outline active text.', 'pixlab-license-bridge' ) );

                    echo '<tr><th colspan="2"><strong>' . esc_html__( 'Ghost Buttons', 'pixlab-license-bridge' ) . '</strong></th></tr>';
                    $this->render_color_input_field( 'style_btn_ghost_normal_bg', __( 'Normal Background', 'pixlab-license-bridge' ), $styles['style_btn_ghost_normal_bg'], __( 'Ghost normal background.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_ghost_normal_border', __( 'Normal Border', 'pixlab-license-bridge' ), $styles['style_btn_ghost_normal_border'], __( 'Ghost normal border.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_ghost_normal_text', __( 'Normal Text', 'pixlab-license-bridge' ), $styles['style_btn_ghost_normal_text'], __( 'Ghost normal text.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_ghost_hover_bg', __( 'Hover Background', 'pixlab-license-bridge' ), $styles['style_btn_ghost_hover_bg'], __( 'Ghost hover background.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_ghost_hover_border', __( 'Hover Border', 'pixlab-license-bridge' ), $styles['style_btn_ghost_hover_border'], __( 'Ghost hover border.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_ghost_hover_text', __( 'Hover Text', 'pixlab-license-bridge' ), $styles['style_btn_ghost_hover_text'], __( 'Ghost hover text.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_ghost_active_bg', __( 'Active Background', 'pixlab-license-bridge' ), $styles['style_btn_ghost_active_bg'], __( 'Ghost active background.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_ghost_active_border', __( 'Active Border', 'pixlab-license-bridge' ), $styles['style_btn_ghost_active_border'], __( 'Ghost active border.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_btn_ghost_active_text', __( 'Active Text', 'pixlab-license-bridge' ), $styles['style_btn_ghost_active_text'], __( 'Ghost active text.', 'pixlab-license-bridge' ) );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'Status Bubble', 'pixlab-license-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Colors for Active and Disabled status bubbles.', 'pixlab-license-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_color_input_field( 'style_status_active_bg', __( 'Active Background', 'pixlab-license-bridge' ), $styles['style_status_active_bg'], __( 'Background for active status.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_status_active_border', __( 'Active Border', 'pixlab-license-bridge' ), $styles['style_status_active_border'], __( 'Border for active status.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_status_active_text', __( 'Active Text', 'pixlab-license-bridge' ), $styles['style_status_active_text'], __( 'Text for active status.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_status_disabled_bg', __( 'Disabled Background', 'pixlab-license-bridge' ), $styles['style_status_disabled_bg'], __( 'Background for disabled status.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_status_disabled_border', __( 'Disabled Border', 'pixlab-license-bridge' ), $styles['style_status_disabled_border'], __( 'Border for disabled status.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_status_disabled_text', __( 'Disabled Text', 'pixlab-license-bridge' ), $styles['style_status_disabled_text'], __( 'Text for disabled status.', 'pixlab-license-bridge' ) );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'History Table', 'pixlab-license-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Color controls for the request history table including headers, rows, and statuses.', 'pixlab-license-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_color_input_field( 'style_table_bg', __( 'Table Background Color', 'pixlab-license-bridge' ), $styles['style_table_bg'], __( 'Base background behind the history table rows.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_table_border', __( 'Table Border/Grid Lines', 'pixlab-license-bridge' ), $styles['style_table_border'], __( 'Border color separating table cells and rows.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_table_header_bg', __( 'Table Header Background Color', 'pixlab-license-bridge' ), $styles['style_table_header_bg'], __( 'Background for the history table header row.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_table_header_text', __( 'Table Header Text Color', 'pixlab-license-bridge' ), $styles['style_table_header_text'], __( 'Text color for history table headers.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_table_body_text', __( 'Table Body Text Color', 'pixlab-license-bridge' ), $styles['style_table_body_text'], __( 'Text color for table rows.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_table_row_bg', __( 'Table Row Background Color', 'pixlab-license-bridge' ), $styles['style_table_row_bg'], __( 'Stripe background color for alternating history rows.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_table_row_hover_bg', __( 'Table Row Hover Background Color', 'pixlab-license-bridge' ), $styles['style_table_row_hover_bg'], __( 'Background color when hovering over a history row.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_table_row_border', __( 'Table Row Border Color', 'pixlab-license-bridge' ), $styles['style_table_row_border'], __( 'Border color for row dividers.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_table_empty_text', __( 'Table Empty/Loading Text Color', 'pixlab-license-bridge' ), $styles['style_table_empty_text'], __( 'Text color for loading or empty states shown in the history table.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_table_error_text', __( 'Error Text Color', 'pixlab-license-bridge' ), $styles['style_table_error_text'], __( 'Text color for error messages inside the Error column.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_status_success_text', __( 'Status “Success” Text Color', 'pixlab-license-bridge' ), $styles['style_status_success_text'], __( 'Text color used for Success statuses in the history table.', 'pixlab-license-bridge' ) );
                    $this->render_color_input_field( 'style_status_error_text', __( 'Status “Error” Text Color', 'pixlab-license-bridge' ), $styles['style_status_error_text'], __( 'Text color used for Error statuses in the history table.', 'pixlab-license-bridge' ) );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'Modal & Overlay', 'pixlab-license-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Control overlay color for modal dialogs in the dashboard.', 'pixlab-license-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_color_input_field( 'style_overlay_color', __( 'Modal Overlay Color', 'pixlab-license-bridge' ), $styles['style_overlay_color'], __( 'Backdrop color behind modals.', 'pixlab-license-bridge' ) );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'Layout Sizes & Spacing', 'pixlab-license-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Tune radii, padding, and shadow depth for dashboard cards.', 'pixlab-license-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_number_input_field( 'style_card_radius', __( 'Card & Container Radius', 'pixlab-license-bridge' ), $styles['style_card_radius'], __( 'Corner radius (px) for cards and the dashboard container.', 'pixlab-license-bridge' ), 1, 0, 48 );
                    $this->render_number_input_field( 'style_card_shadow_blur', __( 'Card Shadow Blur', 'pixlab-license-bridge' ), $styles['style_card_shadow_blur'], __( 'Blur radius (px) for card shadows.', 'pixlab-license-bridge' ), 1, 0, 64 );
                    $this->render_number_input_field( 'style_card_shadow_spread', __( 'Card Shadow Spread', 'pixlab-license-bridge' ), $styles['style_card_shadow_spread'], __( 'Shadow spread (px) for cards.', 'pixlab-license-bridge' ), 1, -10, 30 );
                    $this->render_number_input_field( 'style_container_padding', __( 'Dashboard Padding', 'pixlab-license-bridge' ), $styles['style_container_padding'], __( 'Padding (px) inside the dashboard wrapper.', 'pixlab-license-bridge' ), 1, 0, 80 );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'Labels (Text Customization)', 'pixlab-license-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Override any static label shown in the dashboard or Keys tab without changing dynamic data values.', 'pixlab-license-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_text_input_field( 'label_current_plan', __( '“Current Plan” Label', 'pixlab-license-bridge' ), $labels['label_current_plan'], __( 'Appears above the plan name in the [PIXLAB_DASHBOARD] shortcode.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_usage_metered', __( '“Usage metered” Label', 'pixlab-license-bridge' ), $labels['label_usage_metered'], __( 'Prefix text for the plan limit line in the dashboard header.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_api_key', __( '“API Key” Heading', 'pixlab-license-bridge' ), $labels['label_api_key'], __( 'Heading for the API Key card in the shortcode output.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_key', __( '“Key” Label', 'pixlab-license-bridge' ), $labels['label_key'], __( 'Label next to the read-only API key field.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_created', __( '“Created” Label', 'pixlab-license-bridge' ), $labels['label_created'], __( 'Prefix before the API key creation date.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_disable_key', __( '“Disable Key” Label', 'pixlab-license-bridge' ), $labels['label_disable_key'], __( 'Text used for the toggle key button when disabling access.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_enable_key', __( '“Enable Key” Label', 'pixlab-license-bridge' ), $labels['label_enable_key'], __( 'Text used for the toggle key button when enabling access.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'dsb_label_enabled', __( 'Status Badge: Enabled', 'pixlab-license-bridge' ), $labels['dsb_label_enabled'], __( 'Status label shown when the key is active.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'dsb_label_disabled', __( 'Status Badge: Disabled', 'pixlab-license-bridge' ), $labels['dsb_label_disabled'], __( 'Status label shown when the key is disabled or expired.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'dsb_label_provisioning', __( 'Status Badge: Provisioning', 'pixlab-license-bridge' ), $labels['dsb_label_provisioning'], __( 'Status label shown while a key is provisioning.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'dsb_label_expired_message', __( 'Expired Key Message', 'pixlab-license-bridge' ), $labels['dsb_label_expired_message'], __( 'Message shown when enabling is blocked due to an expired subscription.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_regenerate_key', __( '“Regenerate Key” Label', 'pixlab-license-bridge' ), $labels['label_regenerate_key'], __( 'Label for the regenerate key action button.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_usage_this_period', __( '“Usage this period” Heading', 'pixlab-license-bridge' ), $labels['label_usage_this_period'], __( 'Heading above the usage progress bar.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_used_calls', __( '“Used Calls” Label', 'pixlab-license-bridge' ), $labels['label_used_calls'], __( 'Prefix text for the total calls count near the progress bar.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_history', __( '“History” Heading', 'pixlab-license-bridge' ), $labels['label_history'], __( 'Title of the request history card.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_h2i', __( '“H2I” Label', 'pixlab-license-bridge' ), $labels['label_h2i'], __( 'Label above the H2I endpoint usage summary.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_image', __( '“IMAGE” Label', 'pixlab-license-bridge' ), $labels['label_image'], __( 'Label above the Image endpoint usage summary.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_pdf', __( '“PDF” Label', 'pixlab-license-bridge' ), $labels['label_pdf'], __( 'Label above the PDF endpoint usage summary.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_tools', __( '“TOOLS” Label', 'pixlab-license-bridge' ), $labels['label_tools'], __( 'Label above the Tools endpoint usage summary.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_date_time', __( 'Table Header: “Date / Time”', 'pixlab-license-bridge' ), $labels['label_date_time'], __( 'Header label for the Date/Time column in history.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_endpoint', __( 'Table Header: “Endpoint”', 'pixlab-license-bridge' ), $labels['label_endpoint'], __( 'Header label for the Endpoint column in history.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_action', __( 'Table Header: “Action”', 'pixlab-license-bridge' ), $labels['label_action'], __( 'Header label for the Action column in history.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_files', __( 'Table Header: “Files”', 'pixlab-license-bridge' ), $labels['label_files'], __( 'Header label for the Files column in history.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_bytes_in', __( 'Table Header: “Bytes In”', 'pixlab-license-bridge' ), $labels['label_bytes_in'], __( 'Header label for the Bytes In column in history.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_bytes_out', __( 'Table Header: “Bytes Out”', 'pixlab-license-bridge' ), $labels['label_bytes_out'], __( 'Header label for the Bytes Out column in history.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_error', __( 'Table Header: “Error”', 'pixlab-license-bridge' ), $labels['label_error'], __( 'Header label for the Error column in history.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_status', __( 'Table Header: “Status”', 'pixlab-license-bridge' ), $labels['label_status'], __( 'Header label for the Status column in history.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_create_key', __( '“Create Key” Button Label', 'pixlab-license-bridge' ), $labels['label_create_key'], __( 'Button text that opens the manual provisioning modal in the Keys tab.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_create_api_key_title', __( 'Modal Title: “Create API Key”', 'pixlab-license-bridge' ), $labels['label_create_api_key_title'], __( 'Heading displayed at the top of the Create API Key modal.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_create_api_key_submit', __( 'Modal Submit Button Label', 'pixlab-license-bridge' ), $labels['label_create_api_key_submit'], __( 'Text shown on the Create API Key modal submit button.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_login_required', __( 'Login Prompt', 'pixlab-license-bridge' ), $labels['label_login_required'], __( 'Message displayed when logged-out visitors view the dashboard shortcode.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_loading', __( 'Loading Text', 'pixlab-license-bridge' ), $labels['label_loading'], __( 'Placeholder text shown while dashboard data is loading.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_no_requests', __( 'Empty History Text', 'pixlab-license-bridge' ), $labels['label_no_requests'], __( 'Message displayed when the request history has no rows.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_pagination_previous', __( 'Pagination “Previous” Label', 'pixlab-license-bridge' ), $labels['label_pagination_previous'], __( 'Text for the Previous button beneath the history table.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_pagination_next', __( 'Pagination “Next” Label', 'pixlab-license-bridge' ), $labels['label_pagination_next'], __( 'Text for the Next button beneath the history table.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_modal_title', __( 'Key Modal Title', 'pixlab-license-bridge' ), $labels['label_modal_title'], __( 'Heading at the top of the key reveal modal after regeneration.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_modal_hint', __( 'Key Modal Hint', 'pixlab-license-bridge' ), $labels['label_modal_hint'], __( 'Helper text explaining that the regenerated key is shown once.', 'pixlab-license-bridge' ) );
                    $this->render_text_input_field( 'label_modal_close', __( 'Modal Close Button Label', 'pixlab-license-bridge' ), $labels['label_modal_close'], __( 'Text displayed on the close button inside the key modal.', 'pixlab-license-bridge' ) );
                    ?>
                </table>
            </div>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    protected function render_color_input_field( string $id, string $label, string $value, string $description ): void {
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <input type="text" class="dsb-color-field" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>" data-default-color="<?php echo esc_attr( $value ); ?>" />
                <p class="description"><?php echo esc_html( $description ); ?></p>
            </td>
        </tr>
        <?php
    }

    protected function render_text_input_field( string $id, string $label, string $value, string $description ): void {
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <input type="text" class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>" />
                <p class="description"><?php echo esc_html( $description ); ?></p>
            </td>
        </tr>
        <?php
    }

    protected function render_number_input_field( string $id, string $label, string $value, string $description, $step = 1, $min = 0, $max = null, string $suffix = 'px' ): void {
        $numeric_value = is_numeric( $value ) ? $value : preg_replace( '/[^\d.\-]/', '', (string) $value );
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <div class="dsb-number-field">
                    <input type="number" class="small-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $numeric_value ); ?>" step="<?php echo esc_attr( $step ); ?>" min="<?php echo esc_attr( $min ); ?>" <?php echo null !== $max ? 'max="' . esc_attr( $max ) . '"' : ''; ?> />
                    <span class="dsb-number-field__suffix"><?php echo esc_html( $suffix ); ?></span>
                </div>
                <p class="description"><?php echo esc_html( $description ); ?></p>
            </td>
        </tr>
        <?php
    }

    protected function render_select_input_field( string $id, string $label, string $value, array $options, string $description ): void {
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $id ); ?>">
                    <?php foreach ( $options as $option_value => $option_label ) : ?>
                        <option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( (string) $value, (string) $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php echo esc_html( $description ); ?></p>
            </td>
        </tr>
        <?php
    }

    protected function sync_plans_to_node(): array {
        $levels = function_exists( 'pmpro_getAllLevels' ) ? pmpro_getAllLevels( true, true ) : [];

        $summary = [
            'count_total'   => is_array( $levels ) ? count( $levels ) : 0,
            'count_success' => 0,
            'count_failed'  => 0,
            'errors'        => [],
            'timestamp'     => current_time( 'mysql' ),
        ];

        if ( ! is_array( $levels ) ) {
            $summary['errors'][] = __( 'Paid Memberships Pro levels not available.', 'pixlab-license-bridge' );
            return $summary;
        }

        foreach ( $levels as $level ) {
            $payload = $this->get_plan_payload_for_level( (int) $level->id );
            if ( empty( $payload['plan_slug'] ) ) {
                $summary['count_failed'] ++;
                $summary['errors'][] = sprintf( __( 'Missing plan slug for level %d', 'pixlab-license-bridge' ), (int) $level->id );
                continue;
            }

            $response = $this->client->sync_plan( $payload );
            $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
            $decoded  = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
            $this->db->log_event(
                [
                    'event'           => 'plan_sync',
                    'plan_slug'       => $payload['plan_slug'],
                    'subscription_id' => '',
                    'order_id'        => '',
                    'response_action' => $decoded['action'] ?? '',
                    'http_code'       => $code,
                    'error_excerpt'   => is_wp_error( $response ) ? $response->get_error_message() : ( $decoded['status'] ?? '' ),
                ]
            );
            if ( is_wp_error( $response ) ) {
                $summary['count_failed'] ++;
                $summary['errors'][] = $payload['plan_slug'] . ': ' . $response->get_error_message();
                continue;
            }
            if ( $code >= 200 && $code < 300 && is_array( $decoded ) && ( $decoded['status'] ?? '' ) === 'ok' ) {
                $summary['count_success'] ++;
            } else {
                $summary['count_failed'] ++;
                $summary['errors'][] = $payload['plan_slug'] . ': ' . ( is_array( $decoded ) ? wp_json_encode( $decoded ) : __( 'Unknown error', 'pixlab-license-bridge' ) );
            }
        }

        $this->client->save_plan_sync_status( $summary );
        return $summary;
    }

    protected function get_plan_payload_for_product( \WC_Product $product ): array {
        $defaults = $this->get_plan_limits_defaults( $product );
        $plan_slug = $defaults['plan_slug'];
        $payload = [
            'plan_slug'             => $plan_slug,
            'name'                  => $product->get_name(),
            'billing_period'        => $this->detect_billing_period( $product ),
            'monthly_quota_files'   => $defaults['monthly_quota_files'],
            'max_files_per_request' => $defaults['max_files_per_request'],
            'max_total_upload_mb'   => $defaults['max_total_upload_mb'],
            'max_dimension_px'      => $defaults['max_dimension_px'],
            'timeout_seconds'       => $defaults['timeout_seconds'],
            'allow_h2i'             => $defaults['allow_h2i'],
            'allow_image'           => $defaults['allow_image'],
            'allow_pdf'             => $defaults['allow_pdf'],
            'allow_tools'           => $defaults['allow_tools'],
            'is_free'               => $defaults['is_free'],
            'description'           => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
            'wp_product_id'         => $product->get_id(),
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

    protected function product_is_plan( \WC_Product $product ): bool {
        $plan_products = $this->client->get_plan_products();
        if ( in_array( $product->get_id(), $plan_products, true ) ) {
            return true;
        }

        if ( $product->get_meta( '_dsb_plan_slug', true ) ) {
            return true;
        }

        return false;
    }

    public function maybe_sync_product_by_post( int $post_id, $post = null, bool $update = false ): void {
        if ( ! self::woocommerce_active() ) {
            dsb_log( 'debug', 'WooCommerce inactive; skipping product sync by post', [ 'post_id' => $post_id ] );
            return;
        }
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        $product = wc_get_product( $post_id );
        $this->maybe_sync_product( $product );
    }

    public function maybe_sync_product( $product ): void {
        if ( ! self::woocommerce_active() ) {
            dsb_log( 'debug', 'WooCommerce inactive; skipping product sync', [ 'product' => $product ] );
            return;
        }
        if ( is_numeric( $product ) ) {
            $product = wc_get_product( $product );
        }

        if ( ! $product instanceof \WC_Product ) {
            return;
        }

        $product_id = $product->get_id();
        if ( in_array( $product_id, $this->synced_product_ids, true ) ) {
            return;
        }
        $this->synced_product_ids[] = $product_id;

        if ( ! $this->product_is_plan( $product ) ) {
            return;
        }

        $payload = $this->get_plan_payload_for_product( $product );
        if ( empty( $payload['plan_slug'] ) ) {
            $this->db->log_event(
                [
                    'event'         => 'plan_sync',
                    'plan_slug'     => '',
                    'error_excerpt' => __( 'Missing plan slug; sync skipped.', 'pixlab-license-bridge' ),
                ]
            );
            return;
        }

        $response = $this->client->sync_plan( $payload );
        $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        $decoded  = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
        $this->db->log_event(
            [
                'event'           => 'plan_sync',
                'plan_slug'       => $payload['plan_slug'],
                'subscription_id' => '',
                'order_id'        => '',
                'response_action' => $decoded['action'] ?? '',
                'http_code'       => $code,
                'error_excerpt'   => is_wp_error( $response ) ? $response->get_error_message() : ( $decoded['status'] ?? '' ),
            ]
        );
    }

    protected function discover_plan_products(): array {
        if ( ! self::woocommerce_active() ) {
            dsb_log( 'debug', 'WooCommerce inactive; skipping product discovery' );
            return [];
        }
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
            if ( $product instanceof \WC_Product && ( $this->product_is_plan( $product ) || $this->product_is_subscription( $product ) ) ) {
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
            $mappings = $this->client->get_level_plans();
            foreach ( $mappings as $plan_slug ) {
                $options[ $plan_slug ] = $plan_slug;
            }
        }

        return $options;
    }

    protected function format_admin_datetime( $value ): string {
        if ( empty( $value ) ) {
            return '—';
        }

        try {
            $dt = new \DateTimeImmutable( is_string( $value ) ? $value : '' );
            $timestamp = $dt->getTimestamp();
            return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
        } catch ( \Throwable $e ) {
            return '—';
        }
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
        $plan_sync   = $this->client->get_plan_sync_status();
        $level_plans = $this->client->get_level_plans();
        $levels      = function_exists( 'pmpro_getAllLevels' ) ? pmpro_getAllLevels( true, true ) : [];
        ?>
        <form method="post" id="dsb-plan-form">
            <?php wp_nonce_field( 'dsb_save_plans', 'dsb_plans_nonce' ); ?>
            <?php wp_nonce_field( 'dsb_save_settings', 'dsb_settings_nonce' ); ?>
            <p><?php esc_html_e( 'Map Paid Memberships Pro levels to Davix plan slugs.', 'pixlab-license-bridge' ); ?></p>
            <div class="dsb-table-wrap">
            <table class="widefat" id="dsb-plan-table">
                <thead><tr><th><?php esc_html_e( 'Level', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Plan Slug', 'pixlab-license-bridge' ); ?></th></tr></thead>
                <tbody>
                <?php if ( ! empty( $levels ) ) : ?>
                    <?php foreach ( $levels as $level ) : ?>
                        <?php $level_id = isset( $level->id ) ? (int) $level->id : 0; ?>
                        <tr>
                            <td><?php echo esc_html( $level->name ?? __( 'Untitled', 'pixlab-license-bridge' ) ); ?> — #<?php echo esc_html( $level_id ); ?></td>
                            <td><input type="text" name="level_plans[<?php echo esc_attr( $level_id ); ?>]" value="<?php echo esc_attr( $level_plans[ $level_id ] ?? dsb_normalize_plan_slug( $level->name ?? '' ) ); ?>" placeholder="plan-slug" /></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="2"><?php esc_html_e( 'No PMPro levels found. Create a membership level first.', 'pixlab-license-bridge' ); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
            <?php submit_button( __( 'Save Changes', 'pixlab-license-bridge' ) ); ?>
        </form>

        <form method="post" style="margin-top:20px;">
            <?php wp_nonce_field( 'dsb_sync_plans' ); ?>
            <?php submit_button( __( 'Sync Levels to Node', 'pixlab-license-bridge' ), 'primary', 'dsb_sync_plans', false ); ?>
            <?php if ( ! empty( $plan_sync ) ) : ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: 1: timestamp, 2: success count, 3: failure count */
                        esc_html__( 'Last sync: %1$s — Success: %2$d, Failed: %3$d', 'pixlab-license-bridge' ),
                        esc_html( $plan_sync['timestamp'] ?? '' ),
                        (int) ( $plan_sync['count_success'] ?? 0 ),
                        (int) ( $plan_sync['count_failed'] ?? 0 )
                    );
                    if ( ! empty( $plan_sync['errors'] ) && is_array( $plan_sync['errors'] ) ) {
                        echo '<br />' . esc_html__( 'Errors:', 'pixlab-license-bridge' ) . ' ' . esc_html( implode( '; ', $plan_sync['errors'] ) );
                    }
                    ?>
                </p>
            <?php endif; ?>
        </form>
        <?php
    }

    protected function render_keys_tab(): void {
        $settings = $this->client->get_settings();
        $page   = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $labels = $this->client->get_label_settings();
        $response = $this->client->fetch_keys( $page, 20, $search );
        $items    = [];
        $total    = 0;
        $per_page = 20;
        $valid_from_value  = isset( $_POST['valid_from'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_from'] ) ) : '';
        $valid_until_value = isset( $_POST['valid_until'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_until'] ) ) : '';
        $levels = function_exists( 'pmpro_getAllLevels' ) ? pmpro_getAllLevels( true, true ) : [];
        if ( ! is_wp_error( $response ) ) {
            $code    = wp_remote_retrieve_response_code( $response );
            $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( $code >= 200 && $code < 300 && isset( $decoded['items'] ) ) {
                $items = $decoded['items'];
                if ( is_array( $items ) ) {
                    foreach ( $items as &$item ) {
                        if ( is_array( $item ) && empty( $item['api_key_id'] ) && isset( $item['id'] ) ) {
                            $item['api_key_id'] = $item['id'];
                        }
                    }
                    unset( $item );
                }
                $total = (int) ( $decoded['total'] ?? 0 );
                $per_page = (int) ( $decoded['per_page'] ?? 20 );
            } else {
                $this->add_notice( __( 'Could not load keys.', 'pixlab-license-bridge' ), 'error' );
            }
        } else {
            $this->add_notice( $response->get_error_message(), 'error' );
        }
        ?>
        <h2><?php esc_html_e( 'Keys Settings', 'pixlab-license-bridge' ); ?></h2>
        <form method="post" style="margin-bottom:15px;">
            <?php wp_nonce_field( 'dsb_save_settings', 'dsb_settings_nonce' ); ?>
            <input type="hidden" name="allow_provision_without_refs" value="0" />
            <label><input type="checkbox" name="allow_provision_without_refs" value="1" <?php checked( $settings['allow_provision_without_refs'], 1 ); ?> /> <?php esc_html_e( 'Allow manual provisioning without Subscription/Order', 'pixlab-license-bridge' ); ?></label>
            <?php submit_button( __( 'Save', 'pixlab-license-bridge' ), 'secondary', 'submit', false ); ?>
        </form>
        <form method="get">
            <input type="hidden" name="page" value="davix-bridge" />
            <input type="hidden" name="tab" value="keys" />
            <p class="search-box">
                <label class="screen-reader-text" for="dsb-search">Search Keys</label>
                <input type="search" id="dsb-search" name="s" value="<?php echo esc_attr( $search ); ?>" />
                <?php submit_button( __( 'Search', 'pixlab-license-bridge' ), '', '', false ); ?>
            </p>
        </form>
        <p>
            <button type="button" class="button button-primary dsb-open-key-modal"><?php echo esc_html( $labels['label_create_key'] ); ?></button>
        </p>
        <form method="post" style="margin-bottom:15px;">
            <?php wp_nonce_field( 'dsb_reprovision_key', 'dsb_reprovision_nonce' ); ?>
            <label for="dsb-reprovision-user"><?php esc_html_e( 'Re-provision API key for user ID (optional):', 'pixlab-license-bridge' ); ?></label>
            <input type="number" id="dsb-reprovision-user" name="reprovision_user_id" min="1" step="1" style="width:120px;" />
            <?php submit_button( __( 'Re-provision API key', 'pixlab-license-bridge' ), 'secondary', 'dsb_reprovision_submit', false ); ?>
            <p class="description"><?php esc_html_e( 'Leave blank to re-provision for the currently logged-in user.', 'pixlab-license-bridge' ); ?></p>
        </form>
        <div class="dsb-table-wrap">
        <table class="widefat">
            <thead><tr><th><?php esc_html_e( 'Subscription ID', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Email', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Plan', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Status', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Key Prefix', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Key Last4', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Valid From', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Valid Until', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Updated', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Actions', 'pixlab-license-bridge' ); ?></th></tr></thead>
            <tbody>
            <?php if ( empty( $items ) ) : ?>
                <tr><td colspan="10"><?php esc_html_e( 'No keys found.', 'pixlab-license-bridge' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $items as $item ) : ?>
                    <?php
                    $api_key_id = 0;
                    if ( isset( $item['api_key_id'] ) ) {
                        $api_key_id = absint( $item['api_key_id'] );
                    } elseif ( isset( $item['id'] ) ) {
                        $api_key_id = absint( $item['id'] );
                    }
                    $status_value = strtolower( (string) ( $item['status'] ?? '' ) );
                    if ( in_array( $status_value, [ 'active', 'enabled', 'ok' ], true ) ) {
                        $status_class = 'is-active';
                    } elseif ( in_array( $status_value, [ 'disabled', 'inactive', 'revoked' ], true ) ) {
                        $status_class = 'is-disabled';
                    } else {
                        $status_class = 'is-unknown';
                    }
                    ?>
                    <?php $purge_disabled = ! $api_key_id; ?>
                    <tr>
                        <td><?php echo esc_html( $item['subscription_id'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $item['customer_email'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $item['plan_slug'] ?? '' ); ?></td>
                        <td><span class="dsb-status-card <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $item['status'] ?? '' ); ?></span></td>
                        <td><?php echo esc_html( $item['key_prefix'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $item['key_last4'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $this->format_admin_datetime( $item['valid_from'] ?? '' ) ); ?></td>
                        <td><?php echo esc_html( $this->format_admin_datetime( $item['valid_until'] ?? '' ) ); ?></td>
                        <td><?php echo esc_html( $this->format_admin_datetime( $item['updated_at'] ?? '' ) ); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'dsb_key_action', 'dsb_key_action_nonce' ); ?>
                                <input type="hidden" name="dsb_action" value="rotate" />
                                <input type="hidden" name="subscription_id" value="<?php echo esc_attr( $item['subscription_id'] ?? '' ); ?>" />
                                <input type="hidden" name="customer_email" value="<?php echo esc_attr( $item['customer_email'] ?? '' ); ?>" />
                                <input type="hidden" name="wp_user_id" value="<?php echo esc_attr( $item['wp_user_id'] ?? '' ); ?>" />
                                <input type="hidden" name="api_key_id" value="<?php echo esc_attr( $api_key_id ); ?>" />
                                <?php submit_button( __( 'Rotate', 'pixlab-license-bridge' ), 'link', '', false ); ?>
                            </form>
                            |
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'dsb_key_action', 'dsb_key_action_nonce' ); ?>
                                <input type="hidden" name="dsb_action" value="disable" />
                                <input type="hidden" name="subscription_id" value="<?php echo esc_attr( $item['subscription_id'] ?? '' ); ?>" />
                                <input type="hidden" name="customer_email" value="<?php echo esc_attr( $item['customer_email'] ?? '' ); ?>" />
                                <input type="hidden" name="wp_user_id" value="<?php echo esc_attr( $item['wp_user_id'] ?? '' ); ?>" />
                                <input type="hidden" name="api_key_id" value="<?php echo esc_attr( $api_key_id ); ?>" />
                                <?php submit_button( __( 'Disable', 'pixlab-license-bridge' ), 'link', '', false ); ?>
                            </form>
                            |
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'dsb_key_action', 'dsb_key_action_nonce' ); ?>
                                <input type="hidden" name="dsb_action" value="purge" />
                                <input type="hidden" name="subscription_id" value="<?php echo esc_attr( $item['subscription_id'] ?? '' ); ?>" />
                                <input type="hidden" name="customer_email" value="<?php echo esc_attr( $item['customer_email'] ?? '' ); ?>" />
                                <input type="hidden" name="wp_user_id" value="<?php echo esc_attr( $item['wp_user_id'] ?? '' ); ?>" />
                                <input type="hidden" name="api_key_id" value="<?php echo esc_attr( $api_key_id ); ?>" />
                                <?php
                                $purge_attributes = $purge_disabled
                                    ? 'disabled="disabled" title="' . esc_attr__( 'Cannot purge without api_key_id.', 'pixlab-license-bridge' ) . '"'
                                    : '';
                                submit_button( __( 'Purge', 'pixlab-license-bridge' ), 'link', '', false, $purge_attributes );
                                ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php
        $total_pages = $per_page > 0 ? ceil( $total / $per_page ) : 1;
        if ( $total_pages > 1 ) {
            $page_links = paginate_links(
                [
                    'base'      => add_query_arg( [ 'paged' => '%#%', 'page' => 'davix-bridge', 'tab' => 'keys', 's' => $search ] ),
                    'format'    => '',
                    'prev_text' => __( '&laquo;', 'pixlab-license-bridge' ),
                    'next_text' => __( '&raquo;', 'pixlab-license-bridge' ),
                    'total'     => $total_pages,
                    'current'   => $page,
                ]
            );
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $page_links ) . '</div></div>';
        }
        ?>
        <style>
            .dsb-admin-modal { display: none; position: fixed; inset: 0; align-items: center; justify-content: center; z-index: 100000; }
            .dsb-admin-modal.is-open { display: flex; }
            .dsb-admin-modal__overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.5); }
            .dsb-admin-modal__dialog { position: relative; background: #fff; padding: 24px; width: min(640px, 95%); max-height: 90vh; overflow: auto; box-shadow: 0 12px 24px rgba(0,0,0,0.2); z-index: 1; }
            .dsb-admin-modal__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
            .dsb-admin-modal__close { font-size: 20px; line-height: 1; }
        </style>
        <div class="dsb-admin-modal" data-dsb-modal>
            <div class="dsb-admin-modal__overlay" data-dsb-modal-close></div>
            <div class="dsb-admin-modal__dialog" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $labels['label_create_api_key_title'] ); ?>">
                <div class="dsb-admin-modal__header">
                    <h3><?php echo esc_html( $labels['label_create_api_key_title'] ); ?></h3>
                    <button type="button" class="button-link dsb-admin-modal__close" data-dsb-modal-close aria-label="<?php esc_attr_e( 'Close modal', 'pixlab-license-bridge' ); ?>">&times;</button>
                </div>
                <form method="post">
                    <?php wp_nonce_field( 'dsb_manual_key', 'dsb_manual_nonce' ); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th><?php esc_html_e( 'WordPress User', 'pixlab-license-bridge' ); ?></th>
                            <td>
                                <select id="dsb-user" name="wp_user_id" class="dsb-select-ajax" data-action="dsb_search_users" data-placeholder="<?php esc_attr_e( 'Search by email', 'pixlab-license-bridge' ); ?>" style="width:300px"></select>
                                <p class="description"><?php esc_html_e( 'Choose the WordPress user who will own the API key.', 'pixlab-license-bridge' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'PMPro Level', 'pixlab-license-bridge' ); ?></th>
                            <td>
                                <select id="dsb-level" name="pmpro_level_id" style="width:300px">
                                    <option value=""><?php esc_html_e( 'Select membership level', 'pixlab-license-bridge' ); ?></option>
                                    <?php if ( is_array( $levels ) ) : ?>
                                        <?php foreach ( $levels as $level ) : ?>
                                            <?php
                                            $level_id   = isset( $level->id ) ? (int) $level->id : 0;
                                            $level_name = isset( $level->name ) ? (string) $level->name : '';
                                            if ( $level_id <= 0 || '' === $level_name ) {
                                                continue;
                                            }
                                            ?>
                                            <option value="<?php echo esc_attr( $level_id ); ?>"><?php echo esc_html( $level_name ); ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Assign this PMPro level before provisioning the API key.', 'pixlab-license-bridge' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Valid From', 'pixlab-license-bridge' ); ?></th>
                            <td>
                                <input type="datetime-local" name="valid_from" value="<?php echo esc_attr( $valid_from_value ); ?>" />
                                <p class="description"><?php esc_html_e( 'Optional start of the validity window (WordPress timezone). These dates override PMPro billing dates for API access.', 'pixlab-license-bridge' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Valid Until', 'pixlab-license-bridge' ); ?></th>
                            <td>
                                <input type="datetime-local" name="valid_until" value="<?php echo esc_attr( $valid_until_value ); ?>" />
                                <p class="description"><?php esc_html_e( 'Optional end/expiry of the validity window (WordPress timezone). These dates override PMPro billing dates for API access.', 'pixlab-license-bridge' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( $labels['label_create_api_key_submit'] ); ?>
                </form>
            </div>
        </div>
        <?php
    }

    protected function render_logs_tab(): void {
        $settings = $this->client->get_settings();
        ?>
        <h2><?php esc_html_e( 'Bridge Logs', 'pixlab-license-bridge' ); ?></h2>
        <form method="post" style="margin-bottom:10px;display:flex;gap:10px;align-items:center;">
            <?php wp_nonce_field( 'dsb_save_settings', 'dsb_settings_nonce' ); ?>
            <input type="hidden" name="enable_logging" value="0" />
            <label><input type="checkbox" name="enable_logging" value="1" <?php checked( $settings['enable_logging'], 1 ); ?> /> <?php esc_html_e( 'Enable logging (Store last 200 events)', 'pixlab-license-bridge' ); ?></label>
            <?php submit_button( __( 'Save', 'pixlab-license-bridge' ), 'secondary', 'submit', false ); ?>
        </form>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:15px;display:inline-block;">
            <?php wp_nonce_field( 'dsb_clear_db_logs', 'dsb_clear_db_logs_nonce' ); ?>
            <input type="hidden" name="action" value="dsb_clear_db_logs" />
            <?php submit_button( __( 'Clear all logs', 'pixlab-license-bridge' ), 'delete', 'submit', false, [ 'onclick' => "return confirm('" . esc_js( __( 'Are you sure you want to clear all bridge logs?', 'pixlab-license-bridge' ) ) . "');" ] ); ?>
        </form>
        <button type="button" class="button" id="dsb-logs-refresh" style="margin-bottom:15px;"><?php esc_html_e( 'Refresh', 'pixlab-license-bridge' ); ?></button>
        <div id="dsb-logs-table">
            <?php $this->render_logs_table(); ?>
        </div>
        <?php
    }

    protected function render_logs_table(): void {
        $logs = $this->db->get_logs();
        ?>
        <div class="dsb-table-wrap">
        <table class="widefat">
            <thead><tr><th><?php esc_html_e( 'Time', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Event', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Subscription', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Order', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Email', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Response', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'HTTP', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Error', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Details', 'pixlab-license-bridge' ); ?></th></tr></thead>
            <tbody>
            <?php foreach ( $logs as $log ) : ?>
                <?php
                $context_json = '';
                if ( ! empty( $log['context_json'] ) ) {
                    $decoded_context = json_decode( (string) $log['context_json'], true );
                    $context_json = is_array( $decoded_context )
                        ? wp_json_encode( $decoded_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
                        : (string) $log['context_json'];
                }
                ?>
                <tr>
                    <td><?php echo esc_html( $log['created_at'] ); ?></td>
                    <td><?php echo esc_html( $log['event'] ); ?></td>
                    <td><?php echo esc_html( $log['subscription_id'] ); ?></td>
                    <td><?php echo esc_html( $log['order_id'] ); ?></td>
                    <td><?php echo esc_html( $log['customer_email'] ); ?></td>
                    <td><?php echo esc_html( $log['response_action'] ); ?></td>
                    <td><?php echo esc_html( $log['http_code'] ); ?></td>
                    <td><?php echo esc_html( $log['error_excerpt'] ); ?></td>
                    <td>
                        <?php if ( $context_json ) : ?>
                            <details>
                                <summary><?php esc_html_e( 'View', 'pixlab-license-bridge' ); ?></summary>
                                <pre><?php echo esc_html( $context_json ); ?></pre>
                            </details>
                        <?php else : ?>
                            <?php esc_html_e( '—', 'pixlab-license-bridge' ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $logs ) ) : ?>
                <tr><td colspan="9"><?php esc_html_e( 'No logs yet.', 'pixlab-license-bridge' ); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    protected function render_alerts_tab(): void {
        $settings = $this->client->get_settings();
        $masked_telegram = '';
        if ( ! empty( $settings['telegram_bot_token'] ) && function_exists( 'dsb_mask_string' ) ) {
            $masked_telegram = dsb_mask_string( (string) $settings['telegram_bot_token'] );
        }
        ?>
        <h2><?php esc_html_e( 'Alert System', 'pixlab-license-bridge' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'dsb_save_settings', 'dsb_settings_nonce' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Alert emails', 'pixlab-license-bridge' ); ?></th>
                    <td><textarea name="alert_emails" rows="3" class="large-text" placeholder="admin@example.com&#10;ops@example.com"><?php echo esc_textarea( $settings['alert_emails'] ?? '' ); ?></textarea><p class="description"><?php esc_html_e( 'Comma or newline separated.', 'pixlab-license-bridge' ); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Email From Name', 'pixlab-license-bridge' ); ?></th>
                    <td><input type="text" name="alert_email_from_name" class="regular-text" value="<?php echo esc_attr( $settings['alert_email_from_name'] ?? '' ); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Telegram bot token', 'pixlab-license-bridge' ); ?></th>
                    <td>
                        <div class="dsb-telegram-token-wrap">
                            <input type="password" name="telegram_bot_token" class="regular-text dsb-telegram-token" value="" autocomplete="off" />
                            <button type="button" class="button dsb-telegram-toggle" data-label-show="<?php esc_attr_e( 'Show', 'pixlab-license-bridge' ); ?>" data-label-hide="<?php esc_attr_e( 'Hide', 'pixlab-license-bridge' ); ?>"><?php esc_html_e( 'Show', 'pixlab-license-bridge' ); ?></button>
                        </div>
                        <?php if ( $masked_telegram ) : ?>
                            <p class="description"><?php echo esc_html( sprintf( __( 'Stored token: %s', 'pixlab-license-bridge' ), $masked_telegram ) ); ?></p>
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e( 'Leave blank to keep existing token.', 'pixlab-license-bridge' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Telegram chat IDs', 'pixlab-license-bridge' ); ?></th>
                    <td><textarea name="telegram_chat_ids" rows="3" class="large-text" placeholder="123456789&#10;-100123456789"><?php echo esc_textarea( $settings['telegram_chat_ids'] ?? '' ); ?></textarea><p class="description"><?php esc_html_e( 'Comma or newline separated chat IDs or @channel handles.', 'pixlab-license-bridge' ); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Alert email subject', 'pixlab-license-bridge' ); ?></th>
                    <td><input type="text" name="alert_email_subject" class="regular-text" value="<?php echo esc_attr( $settings['alert_email_subject'] ?? '' ); ?>" /><p class="description"><?php esc_html_e( 'Supports placeholders like {job_name}, {site}, {time}.', 'pixlab-license-bridge' ); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Recovery email subject', 'pixlab-license-bridge' ); ?></th>
                    <td><input type="text" name="recovery_email_subject" class="regular-text" value="<?php echo esc_attr( $settings['recovery_email_subject'] ?? '' ); ?>" /><p class="description"><?php esc_html_e( 'Supports placeholders like {job_name}, {site}, {time}.', 'pixlab-license-bridge' ); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Alert template', 'pixlab-license-bridge' ); ?></th>
                    <td><textarea name="alert_template" rows="3" class="large-text" placeholder="{job_name} failed on {site} with {error_excerpt}"><?php echo esc_textarea( $settings['alert_template'] ?? '' ); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Recovery template', 'pixlab-license-bridge' ); ?></th>
                    <td><textarea name="recovery_template" rows="3" class="large-text" placeholder="{job_name} recovered on {site} at {time}"><?php echo esc_textarea( $settings['recovery_template'] ?? '' ); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Alert threshold', 'pixlab-license-bridge' ); ?></th>
                    <td><input type="number" name="alert_threshold" min="1" value="<?php echo esc_attr( (int) ( $settings['alert_threshold'] ?? 3 ) ); ?>" /> <p class="description"><?php esc_html_e( 'Consecutive failures before alerting.', 'pixlab-license-bridge' ); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Alert cooldown (minutes)', 'pixlab-license-bridge' ); ?></th>
                    <td><input type="number" name="alert_cooldown_minutes" min="1" value="<?php echo esc_attr( (int) ( $settings['alert_cooldown_minutes'] ?? 60 ) ); ?>" /> <p class="description"><?php esc_html_e( 'Minimum minutes between alerts for the same job or trigger.', 'pixlab-license-bridge' ); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Alert triggers', 'pixlab-license-bridge' ); ?></th>
                    <td>
                        <input type="hidden" name="alerts_enable_cron" value="0" />
                        <label><input type="checkbox" name="alerts_enable_cron" value="1" <?php checked( ! empty( $settings['alerts_enable_cron'] ) ); ?> /> <?php esc_html_e( 'Enable cron job alerts', 'pixlab-license-bridge' ); ?></label><br />
                        <input type="hidden" name="alerts_enable_db_connectivity" value="0" />
                        <label><input type="checkbox" name="alerts_enable_db_connectivity" value="1" <?php checked( ! empty( $settings['alerts_enable_db_connectivity'] ) ); ?> /> <?php esc_html_e( 'Enable DB connectivity alerts', 'pixlab-license-bridge' ); ?></label><br />
                        <input type="hidden" name="alerts_enable_license_validation" value="0" />
                        <label><input type="checkbox" name="alerts_enable_license_validation" value="1" <?php checked( ! empty( $settings['alerts_enable_license_validation'] ) ); ?> /> <?php esc_html_e( 'Enable license validation alerts', 'pixlab-license-bridge' ); ?></label><br />
                        <input type="hidden" name="alerts_enable_api_error_rate" value="0" />
                        <label><input type="checkbox" name="alerts_enable_api_error_rate" value="1" <?php checked( ! empty( $settings['alerts_enable_api_error_rate'] ) ); ?> /> <?php esc_html_e( 'Enable API error rate alerts', 'pixlab-license-bridge' ); ?></label><br />
                        <input type="hidden" name="alerts_enable_admin_security" value="0" />
                        <label><input type="checkbox" name="alerts_enable_admin_security" value="1" <?php checked( ! empty( $settings['alerts_enable_admin_security'] ) ); ?> /> <?php esc_html_e( 'Enable admin security alerts', 'pixlab-license-bridge' ); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'API error rate window (minutes)', 'pixlab-license-bridge' ); ?></th>
                    <td><input type="number" name="alerts_api_error_window_minutes" min="1" value="<?php echo esc_attr( (int) ( $settings['alerts_api_error_window_minutes'] ?? 15 ) ); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'API error rate threshold', 'pixlab-license-bridge' ); ?></th>
                    <td><input type="number" name="alerts_api_error_threshold" min="1" value="<?php echo esc_attr( (int) ( $settings['alerts_api_error_threshold'] ?? 10 ) ); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'API error rate cooldown (minutes)', 'pixlab-license-bridge' ); ?></th>
                    <td><input type="number" name="alerts_api_error_cooldown_minutes" min="1" value="<?php echo esc_attr( (int) ( $settings['alerts_api_error_cooldown_minutes'] ?? 30 ) ); ?>" /></td>
                </tr>
            </table>

            <h3><?php esc_html_e( 'Cron Job Alerts', 'pixlab-license-bridge' ); ?></h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Purge Worker', 'pixlab-license-bridge' ); ?></th>
                    <td>
                        <input type="hidden" name="enable_alerts_purge_worker" value="0" />
                        <label><input type="checkbox" name="enable_alerts_purge_worker" value="1" <?php checked( ! empty( $settings['enable_alerts_purge_worker'] ) ); ?> /> <?php esc_html_e( 'Enable alerts', 'pixlab-license-bridge' ); ?></label><br />
                        <input type="hidden" name="enable_recovery_purge_worker" value="0" />
                        <label><input type="checkbox" name="enable_recovery_purge_worker" value="1" <?php checked( ! empty( $settings['enable_recovery_purge_worker'] ) ); ?> /> <?php esc_html_e( 'Send recovery notice', 'pixlab-license-bridge' ); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Provision Worker', 'pixlab-license-bridge' ); ?></th>
                    <td>
                        <input type="hidden" name="enable_alerts_provision_worker" value="0" />
                        <label><input type="checkbox" name="enable_alerts_provision_worker" value="1" <?php checked( ! empty( $settings['enable_alerts_provision_worker'] ) ); ?> /> <?php esc_html_e( 'Enable alerts', 'pixlab-license-bridge' ); ?></label><br />
                        <input type="hidden" name="enable_recovery_provision_worker" value="0" />
                        <label><input type="checkbox" name="enable_recovery_provision_worker" value="1" <?php checked( ! empty( $settings['enable_recovery_provision_worker'] ) ); ?> /> <?php esc_html_e( 'Send recovery notice', 'pixlab-license-bridge' ); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Node Poll Sync', 'pixlab-license-bridge' ); ?></th>
                    <td>
                        <input type="hidden" name="enable_alerts_node_poll" value="0" />
                        <label><input type="checkbox" name="enable_alerts_node_poll" value="1" <?php checked( ! empty( $settings['enable_alerts_node_poll'] ) ); ?> /> <?php esc_html_e( 'Enable alerts', 'pixlab-license-bridge' ); ?></label><br />
                        <input type="hidden" name="enable_recovery_node_poll" value="0" />
                        <label><input type="checkbox" name="enable_recovery_node_poll" value="1" <?php checked( ! empty( $settings['enable_recovery_node_poll'] ) ); ?> /> <?php esc_html_e( 'Send recovery notice', 'pixlab-license-bridge' ); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Daily Resync', 'pixlab-license-bridge' ); ?></th>
                    <td>
                        <input type="hidden" name="enable_alerts_resync" value="0" />
                        <label><input type="checkbox" name="enable_alerts_resync" value="1" <?php checked( ! empty( $settings['enable_alerts_resync'] ) ); ?> /> <?php esc_html_e( 'Enable alerts', 'pixlab-license-bridge' ); ?></label><br />
                        <input type="hidden" name="enable_recovery_resync" value="0" />
                        <label><input type="checkbox" name="enable_recovery_resync" value="1" <?php checked( ! empty( $settings['enable_recovery_resync'] ) ); ?> /> <?php esc_html_e( 'Send recovery notice', 'pixlab-license-bridge' ); ?></label>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Save alert settings', 'pixlab-license-bridge' ) ); ?>
        </form>

        <div class="dsb-alert-test" style="margin-top:10px;">
            <button type="submit" class="button" form="dsb-test-alert-form"><?php esc_html_e( 'Test Alert Routing', 'pixlab-license-bridge' ); ?></button>
            <p class="description"><?php esc_html_e( 'Send a test alert to verify email and Telegram routing.', 'pixlab-license-bridge' ); ?></p>
        </div>

        <form id="dsb-test-alert-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'dsb_test_alert_routing', 'dsb_test_alert_nonce' ); ?>
            <input type="hidden" name="action" value="dsb_test_alert_routing" />
        </form>

        <h3><?php esc_html_e( 'Recent Alerts', 'pixlab-license-bridge' ); ?></h3>
        <div class="dsb-alerts-actions" style="margin-bottom:10px;display:flex;gap:10px;align-items:center;">
            <button type="button" class="button" id="dsb-alerts-refresh"><?php esc_html_e( 'Refresh', 'pixlab-license-bridge' ); ?></button>
            <button type="button" class="button" id="dsb-alerts-clear"><?php esc_html_e( 'Clear', 'pixlab-license-bridge' ); ?></button>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'dsb_download_alerts', 'dsb_download_alerts_nonce' ); ?>
                <input type="hidden" name="action" value="dsb_download_alerts" />
                <?php submit_button( __( 'Download', 'pixlab-license-bridge' ), 'secondary', 'submit', false ); ?>
            </form>
        </div>
        <div id="dsb-recent-alerts">
            <?php $this->render_recent_alerts_table(); ?>
        </div>
        <?php
    }

    protected function render_recent_alerts_table(): void {
        $alerts = function_exists( __NAMESPACE__ . '\\dsb_alert_log_tail' ) ? dsb_alert_log_tail( 50 ) : [];
        ?>
        <div class="dsb-table-wrap">
        <table class="widefat">
            <thead><tr><th><?php esc_html_e( 'Time', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Channel', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Severity', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Code', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Status', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Message', 'pixlab-license-bridge' ); ?></th><th><?php esc_html_e( 'Details', 'pixlab-license-bridge' ); ?></th></tr></thead>
            <tbody>
            <?php foreach ( $alerts as $alert ) : ?>
                <?php
                $context_json = '';
                if ( ! empty( $alert['context'] ) && is_array( $alert['context'] ) ) {
                    $context_json = wp_json_encode( $alert['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
                }
                $details = [];
                if ( ! empty( $alert['error'] ) ) {
                    $details[] = sprintf( 'Error: %s', (string) $alert['error'] );
                }
                if ( $context_json ) {
                    $details[] = "Context:\n" . $context_json;
                }
                ?>
                <tr>
                    <td><?php echo esc_html( $alert['ts'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $alert['channel'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $alert['severity'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $alert['code'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $alert['status'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $alert['message'] ?? '' ); ?></td>
                    <td>
                        <?php if ( ! empty( $details ) ) : ?>
                            <details>
                                <summary><?php esc_html_e( 'View', 'pixlab-license-bridge' ); ?></summary>
                                <pre><?php echo esc_html( implode( "\n\n", $details ) ); ?></pre>
                            </details>
                        <?php else : ?>
                            <?php esc_html_e( '—', 'pixlab-license-bridge' ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ( empty( $alerts ) ) : ?>
                <tr><td colspan="7"><?php esc_html_e( 'No alerts yet.', 'pixlab-license-bridge' ); ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    protected function render_debug_tab(): void {
        $settings = $this->client->get_settings();
        $levels   = [ 'debug', 'info', 'warn', 'error' ];
        $tail     = dsb_get_log_tail( 200 );
        $log_path = dsb_get_latest_log_file();
        $log_dir  = dsb_get_log_dir();
        $log_is_public = function_exists( __NAMESPACE__ . '\\dsb_is_log_path_public' ) ? dsb_is_log_path_public( $log_dir ) : false;
        $log_blocked = function_exists( __NAMESPACE__ . '\\dsb_is_production_env' ) ? ( dsb_is_production_env() && $log_is_public ) : false;
        ?>
        <h2><?php esc_html_e( 'Debug Logging', 'pixlab-license-bridge' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Writes structured debug entries to Pixlab License Bridge Logs stored in the pixlab-license-bridge-logs folder (wp-content by default, uploads fallback). Avoid storing secrets; sensitive tokens are masked automatically.', 'pixlab-license-bridge' ); ?></p>
        <?php if ( $log_blocked ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Debug logging is blocked because the log directory is publicly accessible. Move logs outside the web root to enable.', 'pixlab-license-bridge' ); ?></p></div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field( 'dsb_save_settings', 'dsb_settings_nonce' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Enable debug logging', 'pixlab-license-bridge' ); ?></th>
                    <td>
                        <input type="hidden" name="debug_enabled" value="0" />
                        <label><input type="checkbox" name="debug_enabled" value="1" <?php checked( $settings['debug_enabled'], 1 ); ?> <?php disabled( $log_blocked ); ?> /> <?php esc_html_e( 'Turn on file-based debug logging', 'pixlab-license-bridge' ); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Minimum level', 'pixlab-license-bridge' ); ?></th>
                    <td>
                        <select name="debug_level">
                            <?php foreach ( $levels as $level ) : ?>
                                <option value="<?php echo esc_attr( $level ); ?>" <?php selected( $settings['debug_level'], $level ); ?>><?php echo esc_html( ucfirst( $level ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Only messages at or above this level will be stored.', 'pixlab-license-bridge' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Retention (days)', 'pixlab-license-bridge' ); ?></th>
                    <td>
                        <input type="number" name="debug_retention_days" min="1" value="<?php echo esc_attr( $settings['debug_retention_days'] ); ?>" />
                        <p class="description"><?php esc_html_e( 'Older log files are pruned automatically.', 'pixlab-license-bridge' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save Debug Settings', 'pixlab-license-bridge' ) ); ?>
        </form>

        <h3><?php esc_html_e( 'Log preview (last 200 lines)', 'pixlab-license-bridge' ); ?></h3>
        <p class="description"><?php esc_html_e( 'Sensitive values are masked. Use download for the complete file.', 'pixlab-license-bridge' ); ?></p>
        <textarea class="large-text code" rows="12" readonly id="dsb-debug-log-preview"><?php echo esc_textarea( $tail ); ?></textarea>
        <p class="description"><?php echo esc_html( $log_path ? sprintf( __( 'Current file: %s', 'pixlab-license-bridge' ), $log_path ) : __( 'No log file yet.', 'pixlab-license-bridge' ) ); ?></p>

        <button type="button" class="button" id="dsb-debug-refresh" style="margin-right:8px;"><?php esc_html_e( 'Refresh', 'pixlab-license-bridge' ); ?></button>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
            <?php wp_nonce_field( 'dsb_download_log', 'dsb_download_log_nonce' ); ?>
            <input type="hidden" name="action" value="dsb_download_log" />
            <?php submit_button( __( 'Download log', 'pixlab-license-bridge' ), 'secondary', 'submit', false ); ?>
        </form>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
            <?php wp_nonce_field( 'dsb_clear_log', 'dsb_clear_log_nonce' ); ?>
            <input type="hidden" name="action" value="dsb_clear_log" />
            <?php submit_button( __( 'Clear log', 'pixlab-license-bridge' ), 'delete', 'submit', false, [ 'onclick' => "return confirm('" . esc_js( __( 'Are you sure you want to clear the debug log?', 'pixlab-license-bridge' ) ) . "');" ] ); ?>
        </form>
        <?php
    }

    protected function run_request_log_diagnostics(): array {
        $result   = $this->client->fetch_request_log_diagnostics();
        $response = $result['response'] ?? null;

        if ( is_wp_error( $response ) ) {
            $message = sprintf(
                '%s %s',
                __( 'Diagnostics request failed.', 'pixlab-license-bridge' ),
                $response->get_error_message()
            );
            $this->add_notice( $message, 'error' );

            return [
                'code' => 0,
                'body' => $response->get_error_message(),
            ];
        }

        $masked = $this->mask_sensitive_fields( $result['decoded'] ?? null );
        $body   = $masked !== null ? wp_json_encode( $masked, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : '';

        if ( ! $body && ! is_wp_error( $response ) ) {
            $body = wp_remote_retrieve_body( $response );
        }
        if ( is_string( $body ) && '' !== $body ) {
            $body = $this->mask_diagnostic_string( $body );
        }

        $this->add_notice( __( 'Diagnostics response loaded. Copy/paste below output for support.', 'pixlab-license-bridge' ) );

        return [
            'code' => $result['code'] ?? 0,
            'body' => (string) $body,
        ];
    }

    public function handle_download_log(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'pixlab-license-bridge' ) );
        }

        if ( ! isset( $_POST['dsb_download_log_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_download_log_nonce'] ) ), 'dsb_download_log' ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'pixlab-license-bridge' ) );
        }

        $file = dsb_get_latest_log_file();
        if ( ! $file || ! file_exists( $file ) ) {
            dsb_log( 'warn', 'Download requested but log file missing' );
            wp_safe_redirect( add_query_arg( [ 'page' => 'davix-bridge', 'tab' => 'debug', 'dsb_log_action' => 'error' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        dsb_log( 'info', 'Debug log download initiated', [ 'file' => basename( $file ) ] );

        nocache_headers();
        header( 'Content-Type: text/plain' );
        header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
        readfile( $file );
        exit;
    }

    public function handle_clear_log(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'pixlab-license-bridge' ) );
        }

        if ( ! isset( $_POST['dsb_clear_log_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_clear_log_nonce'] ) ), 'dsb_clear_log' ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'pixlab-license-bridge' ) );
        }

        dsb_clear_logs();
        dsb_log( 'info', 'Debug log cleared by admin' );

        wp_safe_redirect( add_query_arg( [ 'page' => 'davix-bridge', 'tab' => 'debug', 'dsb_log_action' => 'cleared' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_clear_db_logs(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'pixlab-license-bridge' ) );
        }

        if ( ! isset( $_POST['dsb_clear_db_logs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_clear_db_logs_nonce'] ) ), 'dsb_clear_db_logs' ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'pixlab-license-bridge' ) );
        }

        global $wpdb;
        $table      = $wpdb->prefix . 'davix_bridge_logs';
        $cleared    = $wpdb->query( "TRUNCATE TABLE `{$table}`" );

        if ( false === $cleared ) {
            $cleared = $wpdb->query( "DELETE FROM `{$table}`" );
        }

        $log_action = $cleared === false ? 'error' : 'cleared';

        if ( false !== $cleared ) {
            dsb_log( 'info', 'Bridge logs table cleared by admin' );
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'davix-bridge', 'tab' => 'logs', 'dsb_logs_action' => $log_action ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_download_alerts(): void {
        if ( function_exists( __NAMESPACE__ . '\\dsb_alert_log_download' ) ) {
            dsb_alert_log_download();
        }
        wp_die( esc_html__( 'Alert log unavailable.', 'pixlab-license-bridge' ) );
    }

    public function handle_test_alert_routing(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'pixlab-license-bridge' ) );
        }

        if ( ! isset( $_POST['dsb_test_alert_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_test_alert_nonce'] ) ), 'dsb_test_alert_routing' ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'pixlab-license-bridge' ) );
        }

        $result = DSB_Cron_Alerts::send_test_routing();
        $sent   = ! empty( $result['sent'] );

        dsb_log( 'info', 'Test alert routing invoked', [
            'sent' => $sent,
            'telegram_send_attempted' => ! empty( $result['telegram_attempted'] ),
            'email_send_attempted' => ! empty( $result['email_attempted'] ),
        ] );

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => 'davix-bridge',
                    'tab'  => 'alerts',
                    'dsb_alert_test' => $sent ? 'success' : 'error',
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public function ajax_get_recent_alerts(): void {
        check_ajax_referer( 'dsb_admin_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'pixlab-license-bridge' ) ], 403 );
        }

        ob_start();
        $this->render_recent_alerts_table();
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    public function ajax_clear_alerts(): void {
        check_ajax_referer( 'dsb_admin_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'pixlab-license-bridge' ) ], 403 );
        }

        if ( function_exists( __NAMESPACE__ . '\\dsb_alert_log_clear' ) ) {
            dsb_alert_log_clear();
        }

        ob_start();
        $this->render_recent_alerts_table();
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    public function ajax_get_debug_log_tail(): void {
        check_ajax_referer( 'dsb_admin_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'pixlab-license-bridge' ) ], 403 );
        }

        $tail = dsb_get_log_tail( 200 );
        wp_send_json_success( [ 'tail' => $tail ] );
    }

    public function ajax_get_logs_table(): void {
        check_ajax_referer( 'dsb_admin_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'pixlab-license-bridge' ) ], 403 );
        }

        ob_start();
        $this->render_logs_table();
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    protected function mask_sensitive_fields( $data ) {
        if ( is_array( $data ) ) {
            $clean = [];
            foreach ( $data as $key => $value ) {
                $value = $this->mask_sensitive_fields( $value );
                if ( is_string( $key ) && $this->is_sensitive_key( $key ) ) {
                    $value = $this->mask_value( $value );
                }
                $clean[ $key ] = $value;
            }
            return $clean;
        }

        return $data;
    }

    protected function mask_value( $value ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $k => $v ) {
                $value[ $k ] = $this->mask_value( $v );
            }
            return $value;
        }

        if ( is_scalar( $value ) ) {
            $value = (string) $value;
            $len   = strlen( $value );
            if ( $len <= 4 ) {
                return str_repeat( '*', $len );
            }

            $mask_length = max( 3, $len - 4 );
            return substr( $value, 0, 2 ) . str_repeat( '*', $mask_length ) . substr( $value, -2 );
        }

        return $value;
    }

    protected function is_sensitive_key( string $key ): bool {
        $key       = strtolower( $key );
        $sensitive = [ 'token', 'secret', 'password', 'auth', 'key', 'cookie', 'credential' ];
        foreach ( $sensitive as $word ) {
            if ( false !== strpos( $key, $word ) ) {
                return true;
            }
        }

        return false;
    }

    protected function mask_diagnostic_string( string $value ): string {
        if ( function_exists( 'dsb_mask_string' ) ) {
            $value = dsb_mask_string( $value );
        }

        return (string) preg_replace_callback(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            static function ( array $matches ): string {
                $email = sanitize_email( $matches[0] );
                if ( ! $email ) {
                    return '';
                }
                $parts = explode( '@', $email, 2 );
                $local = $parts[0] ?? '';
                $domain = $parts[1] ?? '';
                $prefix = '' !== $local ? substr( $local, 0, 1 ) : '';
                return $prefix . '***' . ( $domain ? '@' . $domain : '' );
            },
            $value
        );
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
        if ( ! self::woocommerce_active() ) {
            wp_send_json_error( [ 'message' => __( 'WooCommerce is not active.', 'pixlab-license-bridge' ) ] );
        }
        check_ajax_referer( 'dsb_admin_ajax', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

        $subscription_types = [ 'wps_subscriptions', 'shop_subscription', 'wps_sfw_subscription', 'wps-subscription' ];
        $args               = [
            'type'    => $subscription_types,
            'limit'   => 20,
            'orderby' => 'date',
            'order'   => 'DESC',
        ];

        if ( $term ) {
            $args['search'] = '*' . $term . '*';

            if ( is_numeric( $term ) ) {
                $args['include'] = [ absint( $term ) ];
            }

            if ( is_email( $term ) ) {
                $args['billing_email'] = $term;
            }
        }

        $orders  = wc_get_orders( $args );
        $results = [];

        foreach ( $orders as $order ) {
            if ( ! $order instanceof \WC_Order ) {
                continue;
            }

            $email = $order->get_billing_email();
            $name  = trim( $order->get_formatted_billing_full_name() );
            $label = $email ?: $name;

            $results[] = [
                'id'   => (string) $order->get_id(),
                'text' => sprintf(
                    '%1$s — %2$s — %3$s',
                    $order->get_id(),
                    $order->get_status(),
                    $label ?: __( '(no email)', 'pixlab-license-bridge' )
                ),
            ];
        }

        wp_send_json( [ 'results' => $results ] );
    }

    protected function render_cron_tab(): void {
        $settings      = $this->client->get_settings();
        $purge_status  = $this->purge_worker->get_last_status();
        $provision_status = $this->provision_worker->get_last_status();
        $node_status   = $this->node_poll->get_last_status();
        $resync_status = $this->resync->get_last_status();
        $now_ts        = (int) current_time( 'timestamp', true );
        $warnings      = [];

        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            $warnings[] = __( 'WP-Cron is disabled (DISABLE_WP_CRON). Ensure a real cron job is hitting wp-cron.php.', 'pixlab-license-bridge' );
        }

        if ( dsb_is_production_env() && ! DSB_Cron_Logger::is_logging_allowed() ) {
            $warnings[] = __( 'Cron debug logging is disabled because the log directory is public. Configure a non-public log path to enable it.', 'pixlab-license-bridge' );
        }

        $overdue_jobs = [];
        $overdue_check = function ( string $job, array $status, int $interval_seconds, bool $enabled ) use ( $now_ts, &$overdue_jobs ): void {
            if ( ! $enabled ) {
                return;
            }
            $last_run_ts = isset( $status['last_run_ts'] ) ? (int) $status['last_run_ts'] : 0;
            if ( ! $last_run_ts ) {
                $last_run = $status['last_run_at'] ?? '';
                if ( ! $last_run ) {
                    return;
                }
                $last_run_ts = strtotime( $last_run . ' UTC' );
                if ( ! $last_run_ts ) {
                    $last_run_ts = strtotime( (string) $last_run );
                }
            }
            if ( ! $last_run_ts ) {
                return;
            }
            if ( $last_run_ts && ( $now_ts - $last_run_ts ) > ( 2 * $interval_seconds ) ) {
                $overdue_jobs[] = $job;
            }
        };

        $overdue_check( __( 'Purge Worker', 'pixlab-license-bridge' ), $purge_status, 5 * MINUTE_IN_SECONDS, ! empty( $settings['enable_purge_worker'] ) );
        $overdue_check( __( 'Provision Worker', 'pixlab-license-bridge' ), $provision_status, MINUTE_IN_SECONDS, true );
        $overdue_check(
            __( 'Node Poll Sync', 'pixlab-license-bridge' ),
            $node_status,
            max( 5, min( 60, (int) ( $settings['node_poll_interval_minutes'] ?? 10 ) ) ) * MINUTE_IN_SECONDS,
            ! empty( $settings['enable_node_poll_sync'] )
        );
        $overdue_check( __( 'Daily Resync', 'pixlab-license-bridge' ), $resync_status, DAY_IN_SECONDS, ! empty( $settings['enable_daily_resync'] ) );

        if ( $overdue_jobs ) {
            $warnings[] = sprintf(
                /* translators: 1: job list */
                __( 'Cron jobs appear overdue: %s', 'pixlab-license-bridge' ),
                implode( ', ', $overdue_jobs )
            );
        }

        $logs_link = add_query_arg(
            [ 'page' => 'davix-bridge', 'tab' => 'logs' ],
            admin_url( 'admin.php' )
        );
        ?>
        <div class="wrap dsb-cron-tab">
            <h2 class="dsb-cron-h1"><?php esc_html_e( 'Cron job settings', 'pixlab-license-bridge' ); ?></h2>
            <?php if ( $warnings ) : ?>
                <div class="notice notice-warning">
                    <p><?php echo esc_html( implode( ' ', $warnings ) ); ?></p>
                </div>
            <?php endif; ?>
            <form method="post" class="dsb-cron-settings">
                <?php wp_nonce_field( 'dsb_save_settings', 'dsb_settings_nonce' ); ?>
                <h3 class="dsb-cron-h2"><?php esc_html_e( 'Purge Worker', 'pixlab-license-bridge' ); ?></h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable purge worker', 'pixlab-license-bridge' ); ?></th>
                        <td>
                            <input type="hidden" name="enable_purge_worker" value="0" />
                            <label><input type="checkbox" name="enable_purge_worker" value="1" <?php checked( ! empty( $settings['enable_purge_worker'] ) ); ?> /> <?php esc_html_e( 'Process purge queue automatically', 'pixlab-license-bridge' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Lock duration (minutes)', 'pixlab-license-bridge' ); ?></th>
                        <td><input type="number" min="1" max="120" name="purge_lock_minutes" value="<?php echo esc_attr( (int) ( $settings['purge_lock_minutes'] ?? 10 ) ); ?>" /> <p class="description"><?php esc_html_e( 'Prevents overlapping runs.', 'pixlab-license-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Lease duration (minutes)', 'pixlab-license-bridge' ); ?></th>
                        <td><input type="number" min="1" max="240" name="purge_lease_minutes" value="<?php echo esc_attr( (int) ( $settings['purge_lease_minutes'] ?? 15 ) ); ?>" /> <p class="description"><?php esc_html_e( 'How long a worker keeps claimed jobs.', 'pixlab-license-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Batch size', 'pixlab-license-bridge' ); ?></th>
                        <td><input type="number" min="1" max="100" name="purge_batch_size" value="<?php echo esc_attr( (int) ( $settings['purge_batch_size'] ?? 20 ) ); ?>" /> <p class="description"><?php esc_html_e( 'Maximum purge jobs processed per run.', 'pixlab-license-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Cron debug log', 'pixlab-license-bridge' ); ?></th>
                        <td>
                            <input type="hidden" name="enable_cron_debug_purge_worker" value="0" />
                            <label><input type="checkbox" name="enable_cron_debug_purge_worker" value="1" <?php checked( ! empty( $settings['enable_cron_debug_purge_worker'] ) ); ?> /> <?php esc_html_e( 'Enable purge worker cron debug log', 'pixlab-license-bridge' ); ?></label>
                        </td>
                    </tr>
                </table>

                <h3 class="dsb-cron-h2"><?php esc_html_e( 'Provision Worker', 'pixlab-license-bridge' ); ?></h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Cron debug log', 'pixlab-license-bridge' ); ?></th>
                        <td>
                            <input type="hidden" name="enable_cron_debug_provision_worker" value="0" />
                            <label><input type="checkbox" name="enable_cron_debug_provision_worker" value="1" <?php checked( ! empty( $settings['enable_cron_debug_provision_worker'] ) ); ?> /> <?php esc_html_e( 'Enable provision worker cron debug log', 'pixlab-license-bridge' ); ?></label>
                        </td>
                    </tr>
                </table>

                <h3 class="dsb-cron-h2"><?php esc_html_e( 'Node Poll Sync', 'pixlab-license-bridge' ); ?></h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable Node poll sync', 'pixlab-license-bridge' ); ?></th>
                        <td>
                            <input type="hidden" name="enable_node_poll_sync" value="0" />
                            <label><input type="checkbox" name="enable_node_poll_sync" value="1" <?php checked( $settings['enable_node_poll_sync'], 1 ); ?> /> <?php esc_html_e( 'Fetch truth from Node export on a schedule.', 'pixlab-license-bridge' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Node poll interval (minutes)', 'pixlab-license-bridge' ); ?></th>
                        <td><input type="number" name="node_poll_interval_minutes" min="5" max="60" value="<?php echo esc_attr( (int) $settings['node_poll_interval_minutes'] ); ?>" /> <p class="description"><?php esc_html_e( 'How often to poll (5-60).', 'pixlab-license-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Node poll page size', 'pixlab-license-bridge' ); ?></th>
                        <td><input type="number" name="node_poll_per_page" min="1" max="500" value="<?php echo esc_attr( (int) $settings['node_poll_per_page'] ); ?>" /> <p class="description"><?php esc_html_e( 'Records fetched per page (max 500).', 'pixlab-license-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Delete stale mirror rows', 'pixlab-license-bridge' ); ?></th>
                        <td>
                            <input type="hidden" name="node_poll_delete_stale" value="0" />
                            <label><input type="checkbox" name="node_poll_delete_stale" value="1" <?php checked( $settings['node_poll_delete_stale'], 1 ); ?> /> <?php esc_html_e( 'Remove davix_bridge keys/users missing from Node export.', 'pixlab-license-bridge' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Node poll lock window (minutes)', 'pixlab-license-bridge' ); ?></th>
                        <td><input type="number" name="node_poll_lock_minutes" min="1" value="<?php echo esc_attr( (int) $settings['node_poll_lock_minutes'] ); ?>" /> <p class="description"><?php esc_html_e( 'Prevents overlapping poll runs.', 'pixlab-license-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Cron debug log', 'pixlab-license-bridge' ); ?></th>
                        <td>
                            <input type="hidden" name="enable_cron_debug_node_poll" value="0" />
                            <label><input type="checkbox" name="enable_cron_debug_node_poll" value="1" <?php checked( ! empty( $settings['enable_cron_debug_node_poll'] ) ); ?> /> <?php esc_html_e( 'Enable Node poll cron debug log', 'pixlab-license-bridge' ); ?></label>
                        </td>
                    </tr>
                </table>

                <h3 class="dsb-cron-h2"><?php esc_html_e( 'Daily Resync', 'pixlab-license-bridge' ); ?></h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable daily resync', 'pixlab-license-bridge' ); ?></th>
                        <td>
                            <input type="hidden" name="enable_daily_resync" value="0" />
                            <label><input type="checkbox" name="enable_daily_resync" value="1" <?php checked( $settings['enable_daily_resync'], 1 ); ?> /> <?php esc_html_e( 'Fetch WPS subscriptions daily and reconcile Node.', 'pixlab-license-bridge' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Resync batch size', 'pixlab-license-bridge' ); ?></th>
                        <td><input type="number" name="resync_batch_size" min="20" max="500" value="<?php echo esc_attr( (int) $settings['resync_batch_size'] ); ?>" /> <p class="description"><?php esc_html_e( 'Subscriptions processed per run (20-500).', 'pixlab-license-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Resync lock window (minutes)', 'pixlab-license-bridge' ); ?></th>
                        <td><input type="number" name="resync_lock_minutes" min="5" value="<?php echo esc_attr( (int) $settings['resync_lock_minutes'] ); ?>" /> <p class="description"><?php esc_html_e( 'Prevents overlapping resync runs.', 'pixlab-license-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Preferred run hour', 'pixlab-license-bridge' ); ?></th>
                        <td><input type="number" name="resync_run_hour" min="0" max="23" value="<?php echo esc_attr( (int) $settings['resync_run_hour'] ); ?>" /> <p class="description"><?php esc_html_e( 'Local site time hour for the daily schedule.', 'pixlab-license-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Disable non-active users', 'pixlab-license-bridge' ); ?></th>
                        <td>
                            <input type="hidden" name="resync_disable_non_active" value="0" />
                            <label><input type="checkbox" name="resync_disable_non_active" value="1" <?php checked( $settings['resync_disable_non_active'], 1 ); ?> /> <?php esc_html_e( 'Send disable events for cancelled/expired/paused/payment_failed.', 'pixlab-license-bridge' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Cron debug log', 'pixlab-license-bridge' ); ?></th>
                        <td>
                            <input type="hidden" name="enable_cron_debug_resync" value="0" />
                            <label><input type="checkbox" name="enable_cron_debug_resync" value="1" <?php checked( ! empty( $settings['enable_cron_debug_resync'] ) ); ?> /> <?php esc_html_e( 'Enable resync cron debug log', 'pixlab-license-bridge' ); ?></label>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save cron settings', 'pixlab-license-bridge' ) ); ?>
            </form>

            <h2 class="dsb-cron-h1"><?php esc_html_e( 'Cron Job Status', 'pixlab-license-bridge' ); ?></h2>

            <?php $this->render_cron_job_status_section( 'purge_worker', __( 'Purge Worker', 'pixlab-license-bridge' ), $settings['enable_purge_worker'] ?? 0, 'Every 5 minutes', wp_next_scheduled( DSB_Purge_Worker::CRON_HOOK ), $purge_status, $logs_link ); ?>
            <?php $this->render_cron_job_status_section( 'provision_worker', __( 'Provision Worker', 'pixlab-license-bridge' ), 1, __( 'Every minute', 'pixlab-license-bridge' ), wp_next_scheduled( DSB_Provision_Worker::CRON_HOOK ), $provision_status, $logs_link ); ?>
            <?php $this->render_cron_job_status_section( 'node_poll', __( 'Node Poll Sync', 'pixlab-license-bridge' ), $settings['enable_node_poll_sync'] ?? 0, sprintf( __( 'Every %d minutes', 'pixlab-license-bridge' ), (int) ( $settings['node_poll_interval_minutes'] ?? 10 ) ), wp_next_scheduled( DSB_Node_Poll::CRON_HOOK ), $node_status, $logs_link ); ?>
            <?php $this->render_cron_job_status_section( 'resync', __( 'Daily Resync', 'pixlab-license-bridge' ), $settings['enable_daily_resync'] ?? 0, sprintf( __( 'Daily at %02d:00', 'pixlab-license-bridge' ), (int) ( $settings['resync_run_hour'] ?? 3 ) ), wp_next_scheduled( DSB_Resync::CRON_HOOK ), $resync_status, $logs_link ); ?>
        </div>
        <?php
    }

    protected function render_cron_job_status_section( string $job_key, string $label, int $enabled, string $schedule, $next_run, array $status, string $logs_link ): void {
        $lock_until   = (int) ( $status['lock_until'] ?? 0 );
        $lock_active  = $lock_until > time();
        $lock_stale   = $lock_until && $lock_until < time();
        $last_run     = $status['last_run_at'] ?? '';
        $last_result  = $status['last_result'] ?? '';
        $last_error   = $status['last_error'] ?? '';
        $last_duration = isset( $status['last_duration_ms'] ) ? (int) $status['last_duration_ms'] : (int) ( $status['last_duration'] ?? 0 );
        $log_tail     = DSB_Cron_Logger::tail( $job_key );
        $job_anchor   = 'dsb-cron-' . sanitize_html_class( $job_key );
        $refresh_url  = add_query_arg( [ 'page' => 'davix-bridge', 'tab' => 'cron' ], admin_url( 'admin.php' ) ) . '#' . $job_anchor;
        ?>
        <div class="dsb-cron-job" id="<?php echo esc_attr( $job_anchor ); ?>" style="margin-top:20px;">
            <h3 class="dsb-cron-h2"><?php echo esc_html( $label ); ?></h3>
            <table class="widefat" style="max-width:900px;">
                <tbody>
                    <tr>
                        <th><?php esc_html_e( 'Enabled', 'pixlab-license-bridge' ); ?></th>
                        <td><?php echo $enabled ? esc_html__( 'Yes', 'pixlab-license-bridge' ) : esc_html__( 'No', 'pixlab-license-bridge' ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Schedule', 'pixlab-license-bridge' ); ?></th>
                        <td><?php echo $enabled ? esc_html( $schedule ) : esc_html__( 'Disabled', 'pixlab-license-bridge' ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Next run time', 'pixlab-license-bridge' ); ?></th>
                        <td>
                            <?php
                            if ( $enabled ) {
                                echo $next_run ? esc_html( gmdate( 'Y-m-d H:i:s', (int) $next_run ) ) : esc_html__( 'Waiting for WP-Cron trigger', 'pixlab-license-bridge' );
                            } else {
                                esc_html_e( 'Not scheduled', 'pixlab-license-bridge' );
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Last run time', 'pixlab-license-bridge' ); ?></th>
                        <td><?php echo esc_html( $last_run ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Last result', 'pixlab-license-bridge' ); ?></th>
                        <td><?php echo esc_html( $last_result ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Last duration (ms)', 'pixlab-license-bridge' ); ?></th>
                        <td><?php echo esc_html( (string) $last_duration ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Lock status', 'pixlab-license-bridge' ); ?></th>
                        <td>
                            <?php
                            if ( $lock_active ) {
                                printf( esc_html__( 'Locked until %s', 'pixlab-license-bridge' ), esc_html( gmdate( 'Y-m-d H:i:s', $lock_until ) ) );
                            } elseif ( $lock_stale ) {
                                esc_html_e( 'Lock stale', 'pixlab-license-bridge' );
                            } else {
                                esc_html_e( 'Not locked', 'pixlab-license-bridge' );
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Last error', 'pixlab-license-bridge' ); ?></th>
                        <td><?php echo esc_html( $last_error ); ?></td>
                    </tr>
                </tbody>
            </table>

            <div style="margin-top:10px;">
                <?php if ( 'purge_worker' === $job_key ) : ?>
                    <form method="post" style="display:inline-block;">
                        <?php wp_nonce_field( 'dsb_run_purge_worker' ); ?>
                        <button type="submit" name="dsb_run_purge_worker" class="button button-secondary"><?php esc_html_e( 'Run now', 'pixlab-license-bridge' ); ?></button>
                    </form>
                <?php elseif ( 'provision_worker' === $job_key ) : ?>
                    <form method="post" style="display:inline-block;">
                        <?php wp_nonce_field( 'dsb_run_provision_worker' ); ?>
                        <button type="submit" name="dsb_run_provision_worker" class="button button-secondary"><?php esc_html_e( 'Run now', 'pixlab-license-bridge' ); ?></button>
                    </form>
                <?php elseif ( 'node_poll' === $job_key ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                        <input type="hidden" name="action" value="dsb_run_node_poll_now" />
                        <input type="hidden" name="dsb_target_tab" value="cron" />
                        <?php wp_nonce_field( 'dsb_run_node_poll_now', 'dsb_run_node_poll_nonce' ); ?>
                        <button type="submit" class="button button-secondary"><?php esc_html_e( 'Run now', 'pixlab-license-bridge' ); ?></button>
                    </form>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                        <input type="hidden" name="action" value="dsb_run_resync_now" />
                        <input type="hidden" name="dsb_target_tab" value="cron" />
                        <?php wp_nonce_field( 'dsb_run_resync_now', 'dsb_run_resync_nonce' ); ?>
                        <button type="submit" class="button button-secondary"><?php esc_html_e( 'Run now', 'pixlab-license-bridge' ); ?></button>
                    </form>
                <?php endif; ?>

                <?php if ( 'purge_worker' === $job_key ) : ?>
                    <form method="post" style="display:inline-block;margin-left:8px;">
                        <?php wp_nonce_field( 'dsb_clear_purge_lock' ); ?>
                        <button type="submit" name="dsb_clear_purge_lock" class="button" <?php disabled( ! $lock_stale ); ?>><?php esc_html_e( 'Clear lock', 'pixlab-license-bridge' ); ?></button>
                    </form>
                <?php elseif ( 'provision_worker' === $job_key ) : ?>
                    <form method="post" style="display:inline-block;margin-left:8px;">
                        <?php wp_nonce_field( 'dsb_clear_provision_lock' ); ?>
                        <button type="submit" name="dsb_clear_provision_lock" class="button" <?php disabled( ! $lock_stale ); ?>><?php esc_html_e( 'Clear lock', 'pixlab-license-bridge' ); ?></button>
                    </form>
                <?php elseif ( 'node_poll' === $job_key ) : ?>
                    <form method="post" style="display:inline-block;margin-left:8px;">
                        <?php wp_nonce_field( 'dsb_clear_node_poll_lock' ); ?>
                        <input type="hidden" name="dsb_clear_node_poll_lock" value="1" />
                        <button type="submit" class="button" <?php disabled( ! $lock_stale ); ?>><?php esc_html_e( 'Clear lock', 'pixlab-license-bridge' ); ?></button>
                    </form>
                <?php else : ?>
                    <form method="post" style="display:inline-block;margin-left:8px;">
                        <?php wp_nonce_field( 'dsb_clear_resync_lock' ); ?>
                        <input type="hidden" name="dsb_clear_resync_lock" value="1" />
                        <button type="submit" class="button" <?php disabled( ! $lock_stale ); ?>><?php esc_html_e( 'Clear lock', 'pixlab-license-bridge' ); ?></button>
                    </form>
                <?php endif; ?>

                <a class="button" style="margin-left:8px;" href="<?php echo esc_url( $logs_link ); ?>"><?php esc_html_e( 'View DB logs', 'pixlab-license-bridge' ); ?></a>
            </div>

            <div style="margin-top:10px;">
                <h4><?php esc_html_e( 'Cron debug log (last 200 lines)', 'pixlab-license-bridge' ); ?></h4>
                <textarea class="large-text code" rows="8" readonly><?php echo esc_textarea( $log_tail ); ?></textarea>
                <div style="margin-top:6px;">
                    <a class="button" href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( 'Refresh', 'pixlab-license-bridge' ); ?></a>
                    <form method="post" style="display:inline-block;margin-left:6px;">
                        <?php wp_nonce_field( 'dsb_clear_cron_log' ); ?>
                        <input type="hidden" name="dsb_clear_cron_log" value="<?php echo esc_attr( $job_key ); ?>" />
                        <button type="submit" class="button" <?php disabled( empty( $log_tail ) ); ?>><?php esc_html_e( 'Clear cron debug log', 'pixlab-license-bridge' ); ?></button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    public function ajax_search_orders(): void {
        if ( ! self::woocommerce_active() ) {
            wp_send_json_error( [ 'message' => __( 'WooCommerce is not active.', 'pixlab-license-bridge' ) ] );
        }
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
