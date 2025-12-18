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
    protected $node_poll;
    protected $notices = [];
    protected $synced_product_ids = [];
    protected $diagnostics_result = null;

    public function __construct( DSB_Client $client, DSB_DB $db, DSB_Events $events, DSB_Resync $resync, DSB_Purge_Worker $purge_worker, DSB_Node_Poll $node_poll ) {
        $this->client       = $client;
        $this->db           = $db;
        $this->events       = $events;
        $this->resync       = $resync;
        $this->purge_worker = $purge_worker;
        $this->node_poll    = $node_poll;
    }

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'handle_actions' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_dsb_download_log', [ $this, 'handle_download_log' ] );
        add_action( 'admin_post_dsb_clear_log', [ $this, 'handle_clear_log' ] );
        add_action( 'admin_post_dsb_clear_db_logs', [ $this, 'handle_clear_db_logs' ] );
        add_action( 'admin_post_dsb_run_resync_now', [ $this, 'handle_run_resync_now' ] );
        add_action( 'admin_post_dsb_run_node_poll_now', [ $this, 'handle_run_node_poll_now' ] );
        add_action( 'wp_ajax_dsb_search_users', [ $this, 'ajax_search_users' ] );
        add_action( 'wp_ajax_dsb_search_subscriptions', [ $this, 'ajax_search_subscriptions' ] );
        add_action( 'wp_ajax_dsb_search_orders', [ $this, 'ajax_search_orders' ] );
        add_action( 'wp_ajax_dsb_js_log', __NAMESPACE__ . '\\dsb_handle_js_log' );
        add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_plan_limits_tab' ] );
        add_action( 'woocommerce_product_data_panels', [ $this, 'render_plan_limits_panel' ] );
        add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_plan_limits_meta' ] );
        add_action( 'save_post_product', [ $this, 'maybe_sync_product_by_post' ], 20, 3 );
        add_action( 'woocommerce_update_product', [ $this, 'maybe_sync_product' ], 20, 1 );
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
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        $settings = $this->client->get_settings();

        dsb_log( 'debug', 'Admin enqueue called', [
            'hook' => $hook,
            'page' => $page,
            'tab'  => $tab,
        ] );

        if ( ! isset( $_GET['page'] ) || 'davix-bridge' !== $page ) {
            dsb_log( 'debug', 'Admin enqueue skipped: not Davix Bridge page', [ 'hook' => $hook, 'page' => $page ] );
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

        $css_path = plugin_dir_path( __FILE__ ) . '../assets/css/dsb-admin.css';
        $css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : DSB_VERSION;

        wp_register_style(
            'dsb-admin-styles',
            DSB_PLUGIN_URL . 'assets/css/dsb-admin.css',
            [ 'wp-color-picker' ],
            $css_ver
        );
        wp_enqueue_style( 'dsb-admin-styles' );

        if ( function_exists( 'wc' ) ) {
            wp_enqueue_style( 'woocommerce_admin_styles' );
        }

        $js_path = plugin_dir_path( __FILE__ ) . '../assets/js/dsb-admin.js';
        $js_ver  = file_exists( $js_path ) ? filemtime( $js_path ) : DSB_VERSION;

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
            'label'    => __( 'Davix Plan Limits', 'davix-sub-bridge' ),
            'target'   => 'dsb_plan_limits_panel',
            'class'    => [ 'show_if_simple', 'show_if_variable', 'show_if_subscription', 'show_if_variable-subscription' ],
            'priority' => 80,
        ];

        return $tabs;
    }

    public function render_plan_limits_panel(): void {
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
                        'label'       => __( 'Plan Slug', 'davix-sub-bridge' ),
                        'desc_tip'    => true,
                        'description' => __( 'Optional override. Defaults to a sanitized product slug.', 'davix-sub-bridge' ),
                        'value'       => $defaults['plan_slug'],
                    ]
                );
                woocommerce_wp_text_input(
                    [
                        'id'                => '_dsb_monthly_quota_files',
                        'label'             => __( 'Monthly quota (files)', 'davix-sub-bridge' ),
                        'type'              => 'number',
                        'custom_attributes' => [ 'min' => 1 ],
                        'value'             => $defaults['monthly_quota_files'],
                    ]
                );
                woocommerce_wp_text_input(
                    [
                        'id'                => '_dsb_max_files_per_request',
                        'label'             => __( 'Max files per request', 'davix-sub-bridge' ),
                        'type'              => 'number',
                        'custom_attributes' => [ 'min' => 1 ],
                        'value'             => $defaults['max_files_per_request'],
                    ]
                );
                woocommerce_wp_text_input(
                    [
                        'id'                => '_dsb_max_total_upload_mb',
                        'label'             => __( 'Max total upload (MB)', 'davix-sub-bridge' ),
                        'type'              => 'number',
                        'custom_attributes' => [ 'min' => 1 ],
                        'value'             => $defaults['max_total_upload_mb'],
                    ]
                );
                woocommerce_wp_text_input(
                    [
                        'id'                => '_dsb_max_dimension_px',
                        'label'             => __( 'Max dimension (px)', 'davix-sub-bridge' ),
                        'type'              => 'number',
                        'custom_attributes' => [ 'min' => 100 ],
                        'value'             => $defaults['max_dimension_px'],
                    ]
                );
                woocommerce_wp_text_input(
                    [
                        'id'                => '_dsb_timeout_seconds',
                        'label'             => __( 'Timeout (seconds)', 'davix-sub-bridge' ),
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
                        'label'       => __( 'Allow H2I', 'davix-sub-bridge' ),
                        'value'       => $defaults['allow_h2i'],
                        'desc_tip'    => true,
                        'description' => __( 'Permit H2I actions for this plan.', 'davix-sub-bridge' ),
                    ]
                );
                woocommerce_wp_checkbox(
                    [
                        'id'          => '_dsb_allow_image',
                        'label'       => __( 'Allow image', 'davix-sub-bridge' ),
                        'value'       => $defaults['allow_image'],
                    ]
                );
                woocommerce_wp_checkbox(
                    [
                        'id'          => '_dsb_allow_pdf',
                        'label'       => __( 'Allow PDF', 'davix-sub-bridge' ),
                        'value'       => $defaults['allow_pdf'],
                    ]
                );
                woocommerce_wp_checkbox(
                    [
                        'id'          => '_dsb_allow_tools',
                        'label'       => __( 'Allow tools', 'davix-sub-bridge' ),
                        'value'       => $defaults['allow_tools'],
                    ]
                );
                woocommerce_wp_checkbox(
                    [
                        'id'          => '_dsb_is_free',
                        'label'       => __( 'Free plan', 'davix-sub-bridge' ),
                        'value'       => $defaults['is_free'],
                        'desc_tip'    => true,
                        'description' => __( 'Mark plan as free when the price is zero.', 'davix-sub-bridge' ),
                    ]
                );
                ?>
            </div>
        </div>
        <?php
    }

    public function save_plan_limits_meta( \WC_Product $product ): void {
        $fields = [
            '_dsb_plan_slug'             => 'dsb_normalize_plan_slug',
            '_dsb_monthly_quota_files'   => 'absint',
            '_dsb_max_files_per_request' => 'absint',
            '_dsb_max_total_upload_mb'   => 'absint',
            '_dsb_max_dimension_px'      => 'absint',
            '_dsb_timeout_seconds'       => 'absint',
        ];

        foreach ( $fields as $key => $callback ) {
            $value = isset( $_POST[ $key ] ) ? call_user_func( $callback, wp_unslash( $_POST[ $key ] ) ) : '';
            if ( '_dsb_plan_slug' === $key ) {
                $value = $value ? dsb_normalize_plan_slug( $value ) : dsb_normalize_plan_slug( $product->get_slug() );
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

    public function handle_actions(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
        $previous_settings = $this->client->get_settings();

        if ( isset( $_GET['dsb_log_action'] ) ) {
            $action = sanitize_key( wp_unslash( $_GET['dsb_log_action'] ) );
            if ( 'cleared' === $action ) {
                $this->add_notice( __( 'Debug log cleared.', 'davix-sub-bridge' ) );
            } elseif ( 'error' === $action ) {
                $this->add_notice( __( 'Debug log action failed.', 'davix-sub-bridge' ), 'error' );
            }
        }

        if ( isset( $_GET['dsb_logs_action'] ) ) {
            $action = sanitize_key( wp_unslash( $_GET['dsb_logs_action'] ) );
            if ( 'cleared' === $action ) {
                $this->add_notice( __( 'Bridge logs cleared.', 'davix-sub-bridge' ) );
            } elseif ( 'error' === $action ) {
                $this->add_notice( __( 'Could not clear bridge logs.', 'davix-sub-bridge' ), 'error' );
            }
        }

        if ( isset( $_GET['dsb_resync_status'] ) ) {
            $status  = sanitize_key( wp_unslash( $_GET['dsb_resync_status'] ) );
            $message = isset( $_GET['dsb_resync_message'] ) ? sanitize_text_field( wp_unslash( $_GET['dsb_resync_message'] ) ) : '';

            if ( 'ok' === $status ) {
                $this->add_notice( __( 'Resync run completed.', 'davix-sub-bridge' ) );
            } elseif ( 'locked' === $status ) {
                $this->add_notice( __( 'Resync skipped because a run is already in progress.', 'davix-sub-bridge' ), 'error' );
            } else {
                $this->add_notice( $message ? $message : __( 'Resync encountered an error.', 'davix-sub-bridge' ), 'error' );
            }
        }

        if ( isset( $_GET['dsb_node_poll_status'] ) ) {
            $status  = sanitize_key( wp_unslash( $_GET['dsb_node_poll_status'] ) );
            $message = isset( $_GET['dsb_node_poll_message'] ) ? sanitize_text_field( wp_unslash( $_GET['dsb_node_poll_message'] ) ) : '';

            if ( 'ok' === $status ) {
                $this->add_notice( __( 'Node poll sync completed.', 'davix-sub-bridge' ) );
            } elseif ( 'locked' === $status ) {
                $this->add_notice( __( 'Node poll skipped because a run is already in progress.', 'davix-sub-bridge' ), 'error' );
            } elseif ( 'disabled' === $status ) {
                $this->add_notice( __( 'Node poll is disabled in settings.', 'davix-sub-bridge' ), 'error' );
            } else {
                $this->add_notice( $message ? $message : __( 'Node poll encountered an error.', 'davix-sub-bridge' ), 'error' );
            }
        }

        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['dsb_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_settings_nonce'] ) ), 'dsb_save_settings' ) ) {
            if ( 'style' === $tab ) {
                $style_keys = array_keys( $this->client->get_style_defaults() );
                $received   = [];
                foreach ( $style_keys as $style_key ) {
                    if ( isset( $_POST[ $style_key ] ) ) {
                        $received[] = $style_key;
                    }
                }
                dsb_log( 'info', 'Saving style settings', [ 'keys' => $received ] );
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
                    dsb_delete_all_logs();
                    $this->add_notice( __( 'Debug disabled. Logs deleted.', 'davix-sub-bridge' ) );
                } elseif ( empty( $previous_settings['debug_enabled'] ) && ! empty( $updated_settings['debug_enabled'] ) ) {
                    dsb_ensure_log_dir();
                    dsb_log( 'info', 'Debug enabled' );
                }

                $cron_debug_keys = [
                    'purge_worker' => __( 'Purge worker', 'davix-sub-bridge' ),
                    'node_poll'    => __( 'Node poll', 'davix-sub-bridge' ),
                    'resync'       => __( 'Daily resync', 'davix-sub-bridge' ),
                ];

                foreach ( $cron_debug_keys as $key => $label ) {
                    $setting_key = 'enable_cron_debug_' . $key;
                    if ( ! empty( $previous_settings[ $setting_key ] ) && empty( $updated_settings[ $setting_key ] ) ) {
                        DSB_Cron_Logger::clear( $key );
                        $this->add_notice( sprintf( __( '%s cron debug log cleared.', 'davix-sub-bridge' ), $label ) );
                    }
                }

                dsb_log( 'info', 'Settings saved', [ 'tab' => $tab, 'posted_keys' => array_keys( $_POST ) ] );
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

        if ( 'settings' === $tab && isset( $_POST['dsb_request_log_diagnostics_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_request_log_diagnostics_nonce'] ) ), 'dsb_request_log_diagnostics' ) ) {
            $this->diagnostics_result = $this->run_request_log_diagnostics();
        }

        $plan_mapping_nonce_valid = isset( $_POST['dsb_plans_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_plans_nonce'] ) ), 'dsb_save_plans' );

        if ( 'plan-mapping' === $tab && ( $plan_mapping_nonce_valid || ( isset( $_POST['dsb_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_settings_nonce'] ) ), 'dsb_save_settings' ) ) ) ) {
            $plans      = [];
            $ids        = isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ? array_values( $_POST['product_ids'] ) : [];
            $slugs      = isset( $_POST['plan_slugs'] ) && is_array( $_POST['plan_slugs'] ) ? array_values( $_POST['plan_slugs'] ) : [];
            $pair_count = min( count( $ids ), count( $slugs ) );
            for ( $i = 0; $i < $pair_count; $i ++ ) {
                $pid  = sanitize_text_field( $ids[ $i ] );
                $slug = dsb_normalize_plan_slug( sanitize_text_field( $slugs[ $i ] ) );
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
                'allow_provision_without_refs' => $settings['allow_provision_without_refs'],
                'plan_products' => $this->client->get_plan_products(),
            ] );
            $this->add_notice( __( 'Plan mappings saved.', 'davix-sub-bridge' ) );
        }

        if ( 'cron' === $tab && isset( $_POST['dsb_run_purge_worker'] ) && check_admin_referer( 'dsb_run_purge_worker' ) ) {
            $result = $this->purge_worker->run( true );
            $status = $result['status'] ?? '';

            if ( 'ok' === $status ) {
                $processed = isset( $result['processed'] ) ? (int) $result['processed'] : 0;
                $this->add_notice( sprintf( __( 'Purge worker ran successfully. Processed %d jobs.', 'davix-sub-bridge' ), $processed ) );
            } elseif ( 'skipped_locked' === $status ) {
                $this->add_notice( __( 'Purge worker skipped because a lock is active.', 'davix-sub-bridge' ), 'error' );
            } elseif ( 'skipped_disabled' === $status ) {
                $this->add_notice( __( 'Purge worker is disabled.', 'davix-sub-bridge' ), 'error' );
            } else {
                $error_message = isset( $result['error'] ) ? $result['error'] : __( 'Unexpected error', 'davix-sub-bridge' );
                $this->add_notice( sprintf( __( 'Purge worker failed: %s', 'davix-sub-bridge' ), sanitize_text_field( $error_message ) ), 'error' );
            }
        }

        if ( 'cron' === $tab && isset( $_POST['dsb_clear_purge_lock'] ) && check_admin_referer( 'dsb_clear_purge_lock' ) ) {
            $lock_until = (int) get_option( DSB_Purge_Worker::OPTION_LOCK_UNTIL, 0 );
            if ( $lock_until > time() ) {
                $this->add_notice( __( 'Purge worker lock is still active; not cleared.', 'davix-sub-bridge' ), 'error' );
            } elseif ( $lock_until <= 0 ) {
                $this->add_notice( __( 'Purge worker is not locked.', 'davix-sub-bridge' ) );
            } else {
                $this->purge_worker->clear_lock();
                $this->add_notice( __( 'Purge lock cleared.', 'davix-sub-bridge' ) );
            }
        }

        if ( 'cron' === $tab && isset( $_POST['dsb_clear_node_poll_lock'] ) && check_admin_referer( 'dsb_clear_node_poll_lock' ) ) {
            $lock_until = (int) get_option( DSB_Node_Poll::OPTION_LOCK_UNTIL, 0 );
            if ( $lock_until > time() ) {
                $this->add_notice( __( 'Node poll lock is still active; not cleared.', 'davix-sub-bridge' ), 'error' );
            } elseif ( $lock_until <= 0 ) {
                $this->add_notice( __( 'Node poll is not locked.', 'davix-sub-bridge' ) );
            } else {
                $this->node_poll->clear_lock();
                $this->add_notice( __( 'Node poll lock cleared.', 'davix-sub-bridge' ) );
            }
        }

        if ( 'cron' === $tab && isset( $_POST['dsb_clear_resync_lock'] ) && check_admin_referer( 'dsb_clear_resync_lock' ) ) {
            $lock_until = (int) get_option( DSB_Resync::OPTION_LOCK_UNTIL, 0 );
            if ( $lock_until > time() ) {
                $this->add_notice( __( 'Resync lock is still active; not cleared.', 'davix-sub-bridge' ), 'error' );
            } elseif ( $lock_until <= 0 ) {
                $this->add_notice( __( 'Resync job is not locked.', 'davix-sub-bridge' ) );
            } else {
                $this->resync->clear_lock();
                $this->add_notice( __( 'Resync lock cleared.', 'davix-sub-bridge' ) );
            }
        }

        if ( 'cron' === $tab && isset( $_POST['dsb_clear_cron_log'] ) && check_admin_referer( 'dsb_clear_cron_log' ) ) {
            $job = sanitize_key( wp_unslash( $_POST['dsb_clear_cron_log'] ) );
            DSB_Cron_Logger::clear( $job );
            $this->add_notice( __( 'Cron debug log cleared.', 'davix-sub-bridge' ) );
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

    public function handle_run_resync_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to run this action.', 'davix-sub-bridge' ) );
        }

        $nonce = isset( $_POST['dsb_run_resync_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['dsb_run_resync_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'dsb_run_resync_now' ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'davix-sub-bridge' ) );
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
            wp_die( esc_html__( 'You do not have permission to run this action.', 'davix-sub-bridge' ) );
        }

        $nonce = isset( $_POST['dsb_run_node_poll_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['dsb_run_node_poll_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'dsb_run_node_poll_now' ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'davix-sub-bridge' ) );
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
            $user_id        = isset( $_POST['customer_user_id'] ) ? absint( $_POST['customer_user_id'] ) : 0;
            $email          = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
            if ( ! $email && $user_id ) {
                $user = get_userdata( $user_id );
                if ( $user ) {
                    $email = $user->user_email;
                }
            }
            $plan_slug      = isset( $_POST['plan_slug'] ) ? dsb_normalize_plan_slug( sanitize_text_field( wp_unslash( $_POST['plan_slug'] ) ) ) : '';
            $subscriptionId = isset( $_POST['subscription_id'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription_id'] ) ) : '';
            $order_id       = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
            $valid_from_raw  = isset( $_POST['valid_from'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_from'] ) ) : '';
            $valid_until_raw = isset( $_POST['valid_until'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_until'] ) ) : '';

            $valid_from  = DSB_Util::to_iso_utc( $valid_from_raw );
            $valid_until = DSB_Util::to_iso_utc( $valid_until_raw );

            if ( $valid_from_raw && ! $valid_from ) {
                $this->add_notice( __( 'Invalid Valid From date. Please use a valid date/time.', 'davix-sub-bridge' ), 'error' );
                return;
            }

            if ( $valid_until_raw && ! $valid_until ) {
                $this->add_notice( __( 'Invalid Valid Until date. Please use a valid date/time.', 'davix-sub-bridge' ), 'error' );
                return;
            }

            if ( $valid_from && $valid_until && strtotime( $valid_until ) < strtotime( $valid_from ) ) {
                $this->add_notice( __( 'Valid Until must be after Valid From.', 'davix-sub-bridge' ), 'error' );
                return;
            }

            $settings = $this->client->get_settings();

            if ( ! $email || ! $plan_slug ) {
                $this->add_notice( __( 'Customer and plan are required.', 'davix-sub-bridge' ), 'error' );
                return;
            }

            $allow_without_refs = ! empty( $settings['allow_provision_without_refs'] );
            // Manual provisioning scenarios:
            // 1) customer + plan + order (subscription empty)
            // 2) customer + plan + subscription (order empty)
            // 3) customer + plan only (allowed when setting is enabled)
            if ( ! $allow_without_refs && '' === $subscriptionId && '' === $order_id ) {
                $this->add_notice( __( 'Please provide a subscription or order, or enable provisioning without references in settings.', 'davix-sub-bridge' ), 'error' );
                return;
            }

            $payload = [
                'customer_email' => $email,
                'plan_slug'      => $plan_slug,
            ];

            if ( '' !== $subscriptionId ) {
                $payload['subscription_id'] = $subscriptionId;
            }

            if ( '' !== $order_id ) {
                $payload['order_id'] = $order_id;
            }

            if ( $valid_from ) {
                $payload['valid_from'] = $valid_from;
            }

            if ( $valid_until ) {
                $payload['valid_until'] = $valid_until;
            }

            $response = $this->client->provision_key( $payload );
            $this->handle_key_response( $response, __( 'Provisioned', 'davix-sub-bridge' ) );
        }

        if ( isset( $_POST['dsb_key_action_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_key_action_nonce'] ) ), 'dsb_key_action' ) ) {
            $action          = isset( $_POST['dsb_action'] ) ? sanitize_key( wp_unslash( $_POST['dsb_action'] ) ) : '';
            $subscription_id = isset( $_POST['subscription_id'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription_id'] ) ) : '';
            $customer_email  = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
            $wp_user_id      = isset( $_POST['wp_user_id'] ) ? absint( $_POST['wp_user_id'] ) : 0;

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
            } elseif ( 'purge' === $action ) {
                $job_id = $this->db->enqueue_purge_job(
                    [
                        'wp_user_id'      => $wp_user_id ?: null,
                        'customer_email'  => $customer_email,
                        'subscription_id' => $subscription_id,
                        'reason'          => 'admin_purge',
                    ]
                );
                $this->purge_worker->run_once();
                $this->add_notice( sprintf( __( 'Purge enqueued (job #%d).', 'davix-sub-bridge' ), $job_id ?: 0 ) );
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
            'style'        => __( 'Style', 'davix-sub-bridge' ),
            'debug'        => __( 'Debug', 'davix-sub-bridge' ),
            'cron'         => __( 'Cron Jobs', 'davix-sub-bridge' ),
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
        } elseif ( 'style' === $tab ) {
            $this->render_style_tab();
        } elseif ( 'debug' === $tab ) {
            $this->render_debug_tab();
        } elseif ( 'cron' === $tab ) {
            $this->render_cron_tab();
        } else {
            $this->render_settings_tab();
        }

        echo '</div>';
    }

    protected function render_settings_tab(): void {
        $settings = $this->client->get_settings();
        $masked_secret = $this->client->masked_consumer_secret();
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
                    <th scope="row"><?php esc_html_e( 'Delete data on uninstall', 'davix-sub-bridge' ); ?></th>
                    <td><label><input type="checkbox" name="delete_data" value="1" <?php checked( $settings['delete_data'], 1 ); ?> /> <?php esc_html_e( 'Drop plugin tables/options on uninstall', 'davix-sub-bridge' ); ?></label></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'WPS Consumer Secret', 'davix-sub-bridge' ); ?></th>
                    <td>
                        <input type="password" name="wps_rest_consumer_secret" class="regular-text" value="<?php echo esc_attr( $settings['wps_rest_consumer_secret'] ); ?>" autocomplete="off" />
                        <p class="description"><?php printf( '%s %s', esc_html__( 'Used to read subscriptions from the WPS REST API.', 'davix-sub-bridge' ), esc_html( $masked_secret ) ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <form method="post" style="margin-top:20px;">
            <?php wp_nonce_field( 'dsb_test_connection' ); ?>
            <?php submit_button( __( 'Test Connection', 'davix-sub-bridge' ), 'secondary', 'dsb_test_connection', false ); ?>
        </form>

        <h2><?php esc_html_e( 'Request Log Diagnostics', 'davix-sub-bridge' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Fetches the latest Node request log payload for debugging. Output is masked for sensitive fields and not stored.', 'davix-sub-bridge' ); ?></p>
        <form method="post" style="margin-top:10px;">
            <?php wp_nonce_field( 'dsb_request_log_diagnostics', 'dsb_request_log_diagnostics_nonce' ); ?>
            <?php submit_button( __( 'Run Diagnostics', 'davix-sub-bridge' ), 'secondary', 'dsb_request_log_diagnostics', false ); ?>
        </form>
        <?php if ( $this->diagnostics_result ) : ?>
            <div class="dsb-diagnostics-output" style="margin-top:15px;">
                <p><strong><?php esc_html_e( 'HTTP code:', 'davix-sub-bridge' ); ?></strong> <?php echo esc_html( $this->diagnostics_result['code'] ?? '' ); ?></p>
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

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'Dashboard Layout', 'davix-sub-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Control the global dashboard canvas behind every card and section of the [PIXLAB_DASHBOARD] shortcode.', 'davix-sub-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_color_input_field( 'style_dashboard_bg', __( 'Dashboard Background Color', 'davix-sub-bridge' ), $styles['style_dashboard_bg'], __( 'Background behind all dashboard sections in the [PIXLAB_DASHBOARD] shortcode.', 'davix-sub-bridge' ) );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'Cards & Sections', 'davix-sub-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Style the cards and containers that hold API key, usage, endpoints, and history content.', 'davix-sub-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_color_input_field( 'style_card_bg', __( 'Card Background Color', 'davix-sub-bridge' ), $styles['style_card_bg'], __( 'Controls the fill color of all dashboard cards.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_card_border', __( 'Card Border Color', 'davix-sub-bridge' ), $styles['style_card_border'], __( 'Sets the border color around each card and table.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_card_shadow', __( 'Card Shadow Color', 'davix-sub-bridge' ), $styles['style_card_shadow'], __( 'Defines the drop shadow color behind cards (if shadows are enabled).', 'davix-sub-bridge' ) );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'Typography & Text', 'davix-sub-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Tune heading, body, and helper text colors used throughout the dashboard.', 'davix-sub-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_color_input_field( 'style_text_primary', __( 'Primary Text Color', 'davix-sub-bridge' ), $styles['style_text_primary'], __( 'Affects headings and key labels in the [PIXLAB_DASHBOARD] shortcode.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_text_secondary', __( 'Secondary Text Color', 'davix-sub-bridge' ), $styles['style_text_secondary'], __( 'Applies to description text such as plan limits and billing periods.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_text_muted', __( 'Muted Text Color', 'davix-sub-bridge' ), $styles['style_text_muted'], __( 'Used for helper labels, small captions, and placeholder hints across the dashboard.', 'davix-sub-bridge' ) );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'Buttons', 'davix-sub-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Customize all primary dashboard buttons including Rotate Key and pagination controls.', 'davix-sub-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_color_input_field( 'style_button_bg', __( 'Primary Button Background Color', 'davix-sub-bridge' ), $styles['style_button_bg'], __( 'Background color for main dashboard buttons such as Regenerate Key.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_button_text', __( 'Primary Button Text Color', 'davix-sub-bridge' ), $styles['style_button_text'], __( 'Text color for main dashboard buttons.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_button_border', __( 'Primary Button Border Color', 'davix-sub-bridge' ), $styles['style_button_border'], __( 'Border color for main dashboard buttons.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_button_hover_bg', __( 'Primary Button Hover Background Color', 'davix-sub-bridge' ), $styles['style_button_hover_bg'], __( 'Background color when hovering over main dashboard buttons.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_button_hover_border', __( 'Primary Button Hover Border Color', 'davix-sub-bridge' ), $styles['style_button_hover_border'], __( 'Border color when hovering over main dashboard buttons.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_button_active_bg', __( 'Primary Button Active Background Color', 'davix-sub-bridge' ), $styles['style_button_active_bg'], __( 'Background color when pressing a main dashboard button.', 'davix-sub-bridge' ) );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'API Key Field', 'davix-sub-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Full visual control of the API key input, allowing light or dark themes.', 'davix-sub-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_color_input_field( 'style_input_bg', __( 'API Key Input Background Color', 'davix-sub-bridge' ), $styles['style_input_bg'], __( 'Background of the API key display field in the dashboard.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_input_text', __( 'API Key Input Text Color', 'davix-sub-bridge' ), $styles['style_input_text'], __( 'Text color of the API key display field.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_input_border', __( 'API Key Input Border Color', 'davix-sub-bridge' ), $styles['style_input_border'], __( 'Border color surrounding the API key display field.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_input_focus_border', __( 'API Key Input Focus Border Color', 'davix-sub-bridge' ), $styles['style_input_focus_border'], __( 'Border color shown when the API key field is focused for copying.', 'davix-sub-bridge' ) );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'Status Badges', 'davix-sub-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Customize Active and Disabled badge colors shown next to the API key state.', 'davix-sub-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_color_input_field( 'style_badge_active_bg', __( 'Active Badge Background Color', 'davix-sub-bridge' ), $styles['style_badge_active_bg'], __( 'Background color of the Active badge shown for enabled keys.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_badge_active_border', __( 'Active Badge Border Color', 'davix-sub-bridge' ), $styles['style_badge_active_border'], __( 'Border color of the Active badge.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_badge_active_text', __( 'Active Badge Text Color', 'davix-sub-bridge' ), $styles['style_badge_active_text'], __( 'Text color of the Active badge.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_badge_disabled_bg', __( 'Disabled Badge Background Color', 'davix-sub-bridge' ), $styles['style_badge_disabled_bg'], __( 'Background color of the Disabled badge when the key is off.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_badge_disabled_border', __( 'Disabled Badge Border Color', 'davix-sub-bridge' ), $styles['style_badge_disabled_border'], __( 'Border color of the Disabled badge.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_badge_disabled_text', __( 'Disabled Badge Text Color', 'davix-sub-bridge' ), $styles['style_badge_disabled_text'], __( 'Text color of the Disabled badge.', 'davix-sub-bridge' ) );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'Usage Progress Bar', 'davix-sub-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Adjust the usage bar colors for metered call counts.', 'davix-sub-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_color_input_field( 'style_progress_track', __( 'Progress Bar Track Color', 'davix-sub-bridge' ), $styles['style_progress_track'], __( 'Background color behind the usage progress bar.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_progress_fill', __( 'Progress Bar Fill Color', 'davix-sub-bridge' ), $styles['style_progress_fill'], __( 'Fill color of the usage progress bar (currently green).', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_progress_text', __( 'Progress Bar Text Color', 'davix-sub-bridge' ), $styles['style_progress_text'], __( 'Text color for usage labels displayed next to the bar.', 'davix-sub-bridge' ) );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'History Table', 'davix-sub-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Color controls for the request history table including headers, rows, and statuses.', 'davix-sub-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_color_input_field( 'style_table_bg', __( 'Table Background Color', 'davix-sub-bridge' ), $styles['style_table_bg'], __( 'Base background behind the history table rows.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_table_header_bg', __( 'Table Header Background Color', 'davix-sub-bridge' ), $styles['style_table_header_bg'], __( 'Background for the history table header row.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_table_header_text', __( 'Table Header Text Color', 'davix-sub-bridge' ), $styles['style_table_header_text'], __( 'Text color for history table headers.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_table_border', __( 'Table Border Color', 'davix-sub-bridge' ), $styles['style_table_border'], __( 'Border color separating table cells and rows.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_table_row_bg', __( 'Table Row Background Color', 'davix-sub-bridge' ), $styles['style_table_row_bg'], __( 'Stripe background color for alternating history rows.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_table_row_hover_bg', __( 'Table Row Hover Background Color', 'davix-sub-bridge' ), $styles['style_table_row_hover_bg'], __( 'Background color when hovering over a history row.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_table_error_text', __( 'Error Text Color', 'davix-sub-bridge' ), $styles['style_table_error_text'], __( 'Text color for error messages inside the Error column.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_status_success_text', __( 'Status “Success” Text Color', 'davix-sub-bridge' ), $styles['style_status_success_text'], __( 'Text color used for Success statuses in the history table.', 'davix-sub-bridge' ) );
                    $this->render_color_input_field( 'style_status_error_text', __( 'Status “Error” Text Color', 'davix-sub-bridge' ), $styles['style_status_error_text'], __( 'Text color used for Error statuses in the history table.', 'davix-sub-bridge' ) );
                    ?>
                </table>
            </div>

            <div class="dsb-style-section">
                <h3><?php esc_html_e( 'Labels (Text Customization)', 'davix-sub-bridge' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Override any static label shown in the dashboard or Keys tab without changing dynamic data values.', 'davix-sub-bridge' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $this->render_text_input_field( 'label_current_plan', __( '“Current Plan” Label', 'davix-sub-bridge' ), $labels['label_current_plan'], __( 'Appears above the plan name in the [PIXLAB_DASHBOARD] shortcode.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_usage_metered', __( '“Usage metered” Label', 'davix-sub-bridge' ), $labels['label_usage_metered'], __( 'Prefix text for the plan limit line in the dashboard header.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_api_key', __( '“API Key” Heading', 'davix-sub-bridge' ), $labels['label_api_key'], __( 'Heading for the API Key card in the shortcode output.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_key', __( '“Key” Label', 'davix-sub-bridge' ), $labels['label_key'], __( 'Label next to the read-only API key field.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_created', __( '“Created” Label', 'davix-sub-bridge' ), $labels['label_created'], __( 'Prefix before the API key creation date.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_disable_key', __( '“Disable Key” Label', 'davix-sub-bridge' ), $labels['label_disable_key'], __( 'Text used for the toggle key button when disabling access.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_enable_key', __( '“Enable Key” Label', 'davix-sub-bridge' ), $labels['label_enable_key'], __( 'Text used for the toggle key button when enabling access.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_regenerate_key', __( '“Regenerate Key” Label', 'davix-sub-bridge' ), $labels['label_regenerate_key'], __( 'Label for the regenerate key action button.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_usage_this_period', __( '“Usage this period” Heading', 'davix-sub-bridge' ), $labels['label_usage_this_period'], __( 'Heading above the usage progress bar.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_used_calls', __( '“Used Calls” Label', 'davix-sub-bridge' ), $labels['label_used_calls'], __( 'Prefix text for the total calls count near the progress bar.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_history', __( '“History” Heading', 'davix-sub-bridge' ), $labels['label_history'], __( 'Title of the request history card.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_h2i', __( '“H2I” Label', 'davix-sub-bridge' ), $labels['label_h2i'], __( 'Label above the H2I endpoint usage summary.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_image', __( '“IMAGE” Label', 'davix-sub-bridge' ), $labels['label_image'], __( 'Label above the Image endpoint usage summary.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_pdf', __( '“PDF” Label', 'davix-sub-bridge' ), $labels['label_pdf'], __( 'Label above the PDF endpoint usage summary.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_tools', __( '“TOOLS” Label', 'davix-sub-bridge' ), $labels['label_tools'], __( 'Label above the Tools endpoint usage summary.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_date_time', __( 'Table Header: “Date / Time”', 'davix-sub-bridge' ), $labels['label_date_time'], __( 'Header label for the Date/Time column in history.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_endpoint', __( 'Table Header: “Endpoint”', 'davix-sub-bridge' ), $labels['label_endpoint'], __( 'Header label for the Endpoint column in history.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_files', __( 'Table Header: “Files”', 'davix-sub-bridge' ), $labels['label_files'], __( 'Header label for the Files column in history.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_bytes_in', __( 'Table Header: “Bytes In”', 'davix-sub-bridge' ), $labels['label_bytes_in'], __( 'Header label for the Bytes In column in history.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_bytes_out', __( 'Table Header: “Bytes Out”', 'davix-sub-bridge' ), $labels['label_bytes_out'], __( 'Header label for the Bytes Out column in history.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_error', __( 'Table Header: “Error”', 'davix-sub-bridge' ), $labels['label_error'], __( 'Header label for the Error column in history.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_status', __( 'Table Header: “Status”', 'davix-sub-bridge' ), $labels['label_status'], __( 'Header label for the Status column in history.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_create_key', __( '“Create Key” Button Label', 'davix-sub-bridge' ), $labels['label_create_key'], __( 'Button text that opens the manual provisioning modal in the Keys tab.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_create_api_key_title', __( 'Modal Title: “Create API Key”', 'davix-sub-bridge' ), $labels['label_create_api_key_title'], __( 'Heading displayed at the top of the Create API Key modal.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_create_api_key_submit', __( 'Modal Submit Button Label', 'davix-sub-bridge' ), $labels['label_create_api_key_submit'], __( 'Text shown on the Create API Key modal submit button.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_login_required', __( 'Login Prompt', 'davix-sub-bridge' ), $labels['label_login_required'], __( 'Message displayed when logged-out visitors view the dashboard shortcode.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_loading', __( 'Loading Text', 'davix-sub-bridge' ), $labels['label_loading'], __( 'Placeholder text shown while dashboard data is loading.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_no_requests', __( 'Empty History Text', 'davix-sub-bridge' ), $labels['label_no_requests'], __( 'Message displayed when the request history has no rows.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_pagination_previous', __( 'Pagination “Previous” Label', 'davix-sub-bridge' ), $labels['label_pagination_previous'], __( 'Text for the Previous button beneath the history table.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_pagination_next', __( 'Pagination “Next” Label', 'davix-sub-bridge' ), $labels['label_pagination_next'], __( 'Text for the Next button beneath the history table.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_modal_title', __( 'Key Modal Title', 'davix-sub-bridge' ), $labels['label_modal_title'], __( 'Heading at the top of the key reveal modal after regeneration.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_modal_hint', __( 'Key Modal Hint', 'davix-sub-bridge' ), $labels['label_modal_hint'], __( 'Helper text explaining that the regenerated key is shown once.', 'davix-sub-bridge' ) );
                    $this->render_text_input_field( 'label_modal_close', __( 'Modal Close Button Label', 'davix-sub-bridge' ), $labels['label_modal_close'], __( 'Text displayed on the close button inside the key modal.', 'davix-sub-bridge' ) );
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
                $summary['errors'][] = $payload['plan_slug'] . ': ' . ( is_array( $decoded ) ? wp_json_encode( $decoded ) : __( 'Unknown error', 'davix-sub-bridge' ) );
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
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        $product = wc_get_product( $post_id );
        $this->maybe_sync_product( $product );
    }

    public function maybe_sync_product( $product ): void {
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
                    'error_excerpt' => __( 'Missing plan slug; sync skipped.', 'davix-sub-bridge' ),
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
            $mappings = $this->client->get_product_plans();
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
        $plans           = $this->client->get_product_plans();
        $plan_products   = $this->client->get_plan_products();
        $plan_candidates = $this->discover_plan_products();
        $plan_sync       = $this->client->get_plan_sync_status();
        ?>
        <form method="post" id="dsb-plan-form">
            <?php wp_nonce_field( 'dsb_save_plans', 'dsb_plans_nonce' ); ?>
            <?php wp_nonce_field( 'dsb_save_settings', 'dsb_settings_nonce' ); ?>
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

            <table class="form-table" role="presentation" style="margin-top:20px;">
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
                                    $plan_slug_meta = dsb_normalize_plan_slug( get_post_meta( $pid, '_dsb_plan_slug', true ) );
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
            <?php submit_button( __( 'Save Changes', 'davix-sub-bridge' ) ); ?>
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

    protected function render_keys_tab(): void {
        $settings = $this->client->get_settings();
        $page   = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $labels = $this->client->get_label_settings();
        $response = $this->client->fetch_keys( $page, 20, $search );
        $items    = [];
        $total    = 0;
        $per_page = 20;
        $plan_options = $this->get_plan_options();
        $valid_from_value  = isset( $_POST['valid_from'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_from'] ) ) : '';
        $valid_until_value = isset( $_POST['valid_until'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_until'] ) ) : '';
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
        <h2><?php esc_html_e( 'Keys Settings', 'davix-sub-bridge' ); ?></h2>
        <form method="post" style="margin-bottom:15px;">
            <?php wp_nonce_field( 'dsb_save_settings', 'dsb_settings_nonce' ); ?>
            <input type="hidden" name="allow_provision_without_refs" value="0" />
            <label><input type="checkbox" name="allow_provision_without_refs" value="1" <?php checked( $settings['allow_provision_without_refs'], 1 ); ?> /> <?php esc_html_e( 'Allow manual provisioning without Subscription/Order', 'davix-sub-bridge' ); ?></label>
            <?php submit_button( __( 'Save', 'davix-sub-bridge' ), 'secondary', 'submit', false ); ?>
        </form>
        <form method="get">
            <input type="hidden" name="page" value="davix-bridge" />
            <input type="hidden" name="tab" value="keys" />
            <p class="search-box">
                <label class="screen-reader-text" for="dsb-search">Search Keys</label>
                <input type="search" id="dsb-search" name="s" value="<?php echo esc_attr( $search ); ?>" />
                <?php submit_button( __( 'Search', 'davix-sub-bridge' ), '', '', false ); ?>
            </p>
        </form>
        <p>
            <button type="button" class="button button-primary dsb-open-key-modal"><?php echo esc_html( $labels['label_create_key'] ); ?></button>
        </p>
        <table class="widefat">
            <thead><tr><th><?php esc_html_e( 'Subscription ID', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Email', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Plan', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Status', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Key Prefix', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Key Last4', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Valid From', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Valid Until', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Updated', 'davix-sub-bridge' ); ?></th><th><?php esc_html_e( 'Actions', 'davix-sub-bridge' ); ?></th></tr></thead>
            <tbody>
            <?php if ( empty( $items ) ) : ?>
                <tr><td colspan="10"><?php esc_html_e( 'No keys found.', 'davix-sub-bridge' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $items as $item ) : ?>
                    <tr>
                        <td><?php echo esc_html( $item['subscription_id'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $item['customer_email'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $item['plan_slug'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $item['status'] ?? '' ); ?></td>
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
                                <?php submit_button( __( 'Rotate', 'davix-sub-bridge' ), 'link', '', false ); ?>
                            </form>
                            |
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'dsb_key_action', 'dsb_key_action_nonce' ); ?>
                                <input type="hidden" name="dsb_action" value="disable" />
                                <input type="hidden" name="subscription_id" value="<?php echo esc_attr( $item['subscription_id'] ?? '' ); ?>" />
                                <input type="hidden" name="customer_email" value="<?php echo esc_attr( $item['customer_email'] ?? '' ); ?>" />
                                <input type="hidden" name="wp_user_id" value="<?php echo esc_attr( $item['wp_user_id'] ?? '' ); ?>" />
                                <?php submit_button( __( 'Disable', 'davix-sub-bridge' ), 'link', '', false ); ?>
                            </form>
                            |
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'dsb_key_action', 'dsb_key_action_nonce' ); ?>
                                <input type="hidden" name="dsb_action" value="purge" />
                                <input type="hidden" name="subscription_id" value="<?php echo esc_attr( $item['subscription_id'] ?? '' ); ?>" />
                                <input type="hidden" name="customer_email" value="<?php echo esc_attr( $item['customer_email'] ?? '' ); ?>" />
                                <input type="hidden" name="wp_user_id" value="<?php echo esc_attr( $item['wp_user_id'] ?? '' ); ?>" />
                                <?php submit_button( __( 'Purge', 'davix-sub-bridge' ), 'link', '', false ); ?>
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
                    <button type="button" class="button-link dsb-admin-modal__close" data-dsb-modal-close aria-label="<?php esc_attr_e( 'Close modal', 'davix-sub-bridge' ); ?>">&times;</button>
                </div>
                <form method="post">
                    <?php wp_nonce_field( 'dsb_manual_key', 'dsb_manual_nonce' ); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th><?php esc_html_e( 'Customer', 'davix-sub-bridge' ); ?></th>
                            <td>
                                <select id="dsb-customer" name="customer_user_id" class="dsb-select-ajax" data-action="dsb_search_users" data-placeholder="<?php esc_attr_e( 'Search by email', 'davix-sub-bridge' ); ?>" style="width:300px"></select>
                                <input type="hidden" name="customer_email" id="dsb-customer-email" />
                                <p class="description"><?php esc_html_e( 'Select the customer who will own the API key.', 'davix-sub-bridge' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Subscription', 'davix-sub-bridge' ); ?></th>
                            <td><select id="dsb-subscription" name="subscription_id" class="dsb-select-ajax" data-action="dsb_search_subscriptions" data-placeholder="<?php esc_attr_e( 'Search subscriptions', 'davix-sub-bridge' ); ?>" style="width:300px"></select>
                                <p class="description"><?php esc_html_e( 'Link the key to an existing subscription (recommended).', 'davix-sub-bridge' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Order', 'davix-sub-bridge' ); ?></th>
                            <td>
                                <select id="dsb-order" name="order_id" class="dsb-select-ajax" data-action="dsb_search_orders" data-placeholder="<?php esc_attr_e( 'Search orders by ID/email', 'davix-sub-bridge' ); ?>" style="width:300px"></select>
                                <p class="description"><?php esc_html_e( 'Optional: helps Node associate the key with a WooCommerce order.', 'davix-sub-bridge' ); ?></p>
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
                                <p class="description"><?php esc_html_e( 'Choose which Davix plan the API key should use.', 'davix-sub-bridge' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Valid From', 'davix-sub-bridge' ); ?></th>
                            <td>
                                <input type="datetime-local" name="valid_from" value="<?php echo esc_attr( $valid_from_value ); ?>" />
                                <p class="description"><?php esc_html_e( 'Optional start of the validity window (WordPress timezone).', 'davix-sub-bridge' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Valid Until', 'davix-sub-bridge' ); ?></th>
                            <td>
                                <input type="datetime-local" name="valid_until" value="<?php echo esc_attr( $valid_until_value ); ?>" />
                                <p class="description"><?php esc_html_e( 'Optional end/expiry of the validity window (WordPress timezone).', 'davix-sub-bridge' ); ?></p>
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
        $logs = $this->db->get_logs();
        ?>
        <h2><?php esc_html_e( 'Bridge Logs', 'davix-sub-bridge' ); ?></h2>
        <form method="post" style="margin-bottom:10px;display:flex;gap:10px;align-items:center;">
            <?php wp_nonce_field( 'dsb_save_settings', 'dsb_settings_nonce' ); ?>
            <input type="hidden" name="enable_logging" value="0" />
            <label><input type="checkbox" name="enable_logging" value="1" <?php checked( $settings['enable_logging'], 1 ); ?> /> <?php esc_html_e( 'Enable logging (Store last 200 events)', 'davix-sub-bridge' ); ?></label>
            <?php submit_button( __( 'Save', 'davix-sub-bridge' ), 'secondary', 'submit', false ); ?>
        </form>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:15px;display:inline-block;">
            <?php wp_nonce_field( 'dsb_clear_db_logs', 'dsb_clear_db_logs_nonce' ); ?>
            <input type="hidden" name="action" value="dsb_clear_db_logs" />
            <?php submit_button( __( 'Clear all logs', 'davix-sub-bridge' ), 'delete', 'submit', false, [ 'onclick' => "return confirm('" . esc_js( __( 'Are you sure you want to clear all bridge logs?', 'davix-sub-bridge' ) ) . "');" ] ); ?>
        </form>
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

    protected function render_debug_tab(): void {
        $settings = $this->client->get_settings();
        $levels   = [ 'debug', 'info', 'warn', 'error' ];
        $tail     = dsb_get_log_tail( 200 );
        $log_path = dsb_get_latest_log_file();
        ?>
        <h2><?php esc_html_e( 'Debug Logging', 'davix-sub-bridge' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Writes structured debug entries to wp-content/uploads/davix-bridge-logs/. Avoid storing secrets; sensitive tokens are masked automatically.', 'davix-sub-bridge' ); ?></p>
        <form method="post">
            <?php wp_nonce_field( 'dsb_save_settings', 'dsb_settings_nonce' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Enable debug logging', 'davix-sub-bridge' ); ?></th>
                    <td>
                        <input type="hidden" name="debug_enabled" value="0" />
                        <label><input type="checkbox" name="debug_enabled" value="1" <?php checked( $settings['debug_enabled'], 1 ); ?> /> <?php esc_html_e( 'Turn on file-based debug logging', 'davix-sub-bridge' ); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Minimum level', 'davix-sub-bridge' ); ?></th>
                    <td>
                        <select name="debug_level">
                            <?php foreach ( $levels as $level ) : ?>
                                <option value="<?php echo esc_attr( $level ); ?>" <?php selected( $settings['debug_level'], $level ); ?>><?php echo esc_html( ucfirst( $level ) ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Only messages at or above this level will be stored.', 'davix-sub-bridge' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Retention (days)', 'davix-sub-bridge' ); ?></th>
                    <td>
                        <input type="number" name="debug_retention_days" min="1" value="<?php echo esc_attr( $settings['debug_retention_days'] ); ?>" />
                        <p class="description"><?php esc_html_e( 'Older log files are pruned automatically.', 'davix-sub-bridge' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save Debug Settings', 'davix-sub-bridge' ) ); ?>
        </form>

        <h3><?php esc_html_e( 'Log preview (last 200 lines)', 'davix-sub-bridge' ); ?></h3>
        <p class="description"><?php esc_html_e( 'Sensitive values are masked. Use download for the complete file.', 'davix-sub-bridge' ); ?></p>
        <textarea class="large-text code" rows="12" readonly><?php echo esc_textarea( $tail ); ?></textarea>
        <p class="description"><?php echo esc_html( $log_path ? sprintf( __( 'Current file: %s', 'davix-sub-bridge' ), $log_path ) : __( 'No log file yet.', 'davix-sub-bridge' ) ); ?></p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
            <?php wp_nonce_field( 'dsb_download_log', 'dsb_download_log_nonce' ); ?>
            <input type="hidden" name="action" value="dsb_download_log" />
            <?php submit_button( __( 'Download log', 'davix-sub-bridge' ), 'secondary', 'submit', false ); ?>
        </form>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
            <?php wp_nonce_field( 'dsb_clear_log', 'dsb_clear_log_nonce' ); ?>
            <input type="hidden" name="action" value="dsb_clear_log" />
            <?php submit_button( __( 'Clear log', 'davix-sub-bridge' ), 'delete', 'submit', false, [ 'onclick' => "return confirm('" . esc_js( __( 'Are you sure you want to clear the debug log?', 'davix-sub-bridge' ) ) . "');" ] ); ?>
        </form>
        <?php
    }

    protected function run_request_log_diagnostics(): array {
        $result   = $this->client->fetch_request_log_diagnostics();
        $response = $result['response'] ?? null;

        if ( is_wp_error( $response ) ) {
            $message = sprintf(
                '%s %s',
                __( 'Diagnostics request failed.', 'davix-sub-bridge' ),
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

        $this->add_notice( __( 'Diagnostics response loaded. Copy/paste below output for support.', 'davix-sub-bridge' ) );

        return [
            'code' => $result['code'] ?? 0,
            'body' => (string) $body,
        ];
    }

    public function handle_download_log(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'davix-sub-bridge' ) );
        }

        if ( ! isset( $_POST['dsb_download_log_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_download_log_nonce'] ) ), 'dsb_download_log' ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'davix-sub-bridge' ) );
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
            wp_die( esc_html__( 'Unauthorized', 'davix-sub-bridge' ) );
        }

        if ( ! isset( $_POST['dsb_clear_log_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_clear_log_nonce'] ) ), 'dsb_clear_log' ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'davix-sub-bridge' ) );
        }

        dsb_clear_logs();
        dsb_log( 'info', 'Debug log cleared by admin' );

        wp_safe_redirect( add_query_arg( [ 'page' => 'davix-bridge', 'tab' => 'debug', 'dsb_log_action' => 'cleared' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_clear_db_logs(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'davix-sub-bridge' ) );
        }

        if ( ! isset( $_POST['dsb_clear_db_logs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_clear_db_logs_nonce'] ) ), 'dsb_clear_db_logs' ) ) {
            wp_die( esc_html__( 'Invalid nonce.', 'davix-sub-bridge' ) );
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
                    $label ?: __( '(no email)', 'davix-sub-bridge' )
                ),
            ];
        }

        wp_send_json( [ 'results' => $results ] );
    }

    protected function render_cron_tab(): void {
        $settings      = $this->client->get_settings();
        $purge_status  = $this->purge_worker->get_last_status();
        $node_status   = $this->node_poll->get_last_status();
        $resync_status = $this->resync->get_last_status();

        $logs_link = add_query_arg(
            [ 'page' => 'davix-bridge', 'tab' => 'logs' ],
            admin_url( 'admin.php' )
        );
        ?>
        <div class="wrap dsb-cron-tab">
            <h2 class="dsb-cron-h1"><?php esc_html_e( 'Cron job settings', 'davix-sub-bridge' ); ?></h2>
            <form method="post" class="dsb-cron-settings">
                <?php wp_nonce_field( 'dsb_save_settings', 'dsb_settings_nonce' ); ?>
                <h3 class="dsb-cron-h2"><?php esc_html_e( 'Purge Worker', 'davix-sub-bridge' ); ?></h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable purge worker', 'davix-sub-bridge' ); ?></th>
                        <td><label><input type="checkbox" name="enable_purge_worker" value="1" <?php checked( ! empty( $settings['enable_purge_worker'] ) ); ?> /> <?php esc_html_e( 'Process purge queue automatically', 'davix-sub-bridge' ); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Lock duration (minutes)', 'davix-sub-bridge' ); ?></th>
                        <td><input type="number" min="1" max="120" name="purge_lock_minutes" value="<?php echo esc_attr( (int) ( $settings['purge_lock_minutes'] ?? 10 ) ); ?>" /> <p class="description"><?php esc_html_e( 'Prevents overlapping runs.', 'davix-sub-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Lease duration (minutes)', 'davix-sub-bridge' ); ?></th>
                        <td><input type="number" min="1" max="240" name="purge_lease_minutes" value="<?php echo esc_attr( (int) ( $settings['purge_lease_minutes'] ?? 15 ) ); ?>" /> <p class="description"><?php esc_html_e( 'How long a worker keeps claimed jobs.', 'davix-sub-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Batch size', 'davix-sub-bridge' ); ?></th>
                        <td><input type="number" min="1" max="100" name="purge_batch_size" value="<?php echo esc_attr( (int) ( $settings['purge_batch_size'] ?? 20 ) ); ?>" /> <p class="description"><?php esc_html_e( 'Maximum purge jobs processed per run.', 'davix-sub-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Alerts', 'davix-sub-bridge' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="enable_alerts_purge_worker" value="1" <?php checked( ! empty( $settings['enable_alerts_purge_worker'] ) ); ?> /> <?php esc_html_e( 'Enable alerts', 'davix-sub-bridge' ); ?></label><br />
                            <label><input type="checkbox" name="enable_recovery_purge_worker" value="1" <?php checked( ! empty( $settings['enable_recovery_purge_worker'] ) ); ?> /> <?php esc_html_e( 'Send recovery notice', 'davix-sub-bridge' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Cron debug log', 'davix-sub-bridge' ); ?></th>
                        <td><label><input type="checkbox" name="enable_cron_debug_purge_worker" value="1" <?php checked( ! empty( $settings['enable_cron_debug_purge_worker'] ) ); ?> /> <?php esc_html_e( 'Enable purge worker cron debug log', 'davix-sub-bridge' ); ?></label></td>
                    </tr>
                </table>

                <h3 class="dsb-cron-h2"><?php esc_html_e( 'Node Poll Sync', 'davix-sub-bridge' ); ?></h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable Node poll sync', 'davix-sub-bridge' ); ?></th>
                        <td><label><input type="checkbox" name="enable_node_poll_sync" value="1" <?php checked( $settings['enable_node_poll_sync'], 1 ); ?> /> <?php esc_html_e( 'Fetch truth from Node export on a schedule.', 'davix-sub-bridge' ); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Node poll interval (minutes)', 'davix-sub-bridge' ); ?></th>
                        <td><input type="number" name="node_poll_interval_minutes" min="5" max="60" value="<?php echo esc_attr( (int) $settings['node_poll_interval_minutes'] ); ?>" /> <p class="description"><?php esc_html_e( 'How often to poll (5-60).', 'davix-sub-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Node poll page size', 'davix-sub-bridge' ); ?></th>
                        <td><input type="number" name="node_poll_per_page" min="1" max="500" value="<?php echo esc_attr( (int) $settings['node_poll_per_page'] ); ?>" /> <p class="description"><?php esc_html_e( 'Records fetched per page (max 500).', 'davix-sub-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Delete stale mirror rows', 'davix-sub-bridge' ); ?></th>
                        <td><label><input type="checkbox" name="node_poll_delete_stale" value="1" <?php checked( $settings['node_poll_delete_stale'], 1 ); ?> /> <?php esc_html_e( 'Remove davix_bridge keys/users missing from Node export.', 'davix-sub-bridge' ); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Node poll lock window (minutes)', 'davix-sub-bridge' ); ?></th>
                        <td><input type="number" name="node_poll_lock_minutes" min="1" value="<?php echo esc_attr( (int) $settings['node_poll_lock_minutes'] ); ?>" /> <p class="description"><?php esc_html_e( 'Prevents overlapping poll runs.', 'davix-sub-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Alerts', 'davix-sub-bridge' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="enable_alerts_node_poll" value="1" <?php checked( ! empty( $settings['enable_alerts_node_poll'] ) ); ?> /> <?php esc_html_e( 'Enable alerts', 'davix-sub-bridge' ); ?></label><br />
                            <label><input type="checkbox" name="enable_recovery_node_poll" value="1" <?php checked( ! empty( $settings['enable_recovery_node_poll'] ) ); ?> /> <?php esc_html_e( 'Send recovery notice', 'davix-sub-bridge' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Cron debug log', 'davix-sub-bridge' ); ?></th>
                        <td><label><input type="checkbox" name="enable_cron_debug_node_poll" value="1" <?php checked( ! empty( $settings['enable_cron_debug_node_poll'] ) ); ?> /> <?php esc_html_e( 'Enable Node poll cron debug log', 'davix-sub-bridge' ); ?></label></td>
                    </tr>
                </table>

                <h3 class="dsb-cron-h2"><?php esc_html_e( 'Daily Resync', 'davix-sub-bridge' ); ?></h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable daily resync', 'davix-sub-bridge' ); ?></th>
                        <td><label><input type="checkbox" name="enable_daily_resync" value="1" <?php checked( $settings['enable_daily_resync'], 1 ); ?> /> <?php esc_html_e( 'Fetch WPS subscriptions daily and reconcile Node.', 'davix-sub-bridge' ); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Resync batch size', 'davix-sub-bridge' ); ?></th>
                        <td><input type="number" name="resync_batch_size" min="20" max="500" value="<?php echo esc_attr( (int) $settings['resync_batch_size'] ); ?>" /> <p class="description"><?php esc_html_e( 'Subscriptions processed per run (20-500).', 'davix-sub-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Resync lock window (minutes)', 'davix-sub-bridge' ); ?></th>
                        <td><input type="number" name="resync_lock_minutes" min="5" value="<?php echo esc_attr( (int) $settings['resync_lock_minutes'] ); ?>" /> <p class="description"><?php esc_html_e( 'Prevents overlapping resync runs.', 'davix-sub-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Preferred run hour', 'davix-sub-bridge' ); ?></th>
                        <td><input type="number" name="resync_run_hour" min="0" max="23" value="<?php echo esc_attr( (int) $settings['resync_run_hour'] ); ?>" /> <p class="description"><?php esc_html_e( 'Local site time hour for the daily schedule.', 'davix-sub-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Disable non-active users', 'davix-sub-bridge' ); ?></th>
                        <td><label><input type="checkbox" name="resync_disable_non_active" value="1" <?php checked( $settings['resync_disable_non_active'], 1 ); ?> /> <?php esc_html_e( 'Send disable events for cancelled/expired/paused/payment_failed.', 'davix-sub-bridge' ); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Alerts', 'davix-sub-bridge' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="enable_alerts_resync" value="1" <?php checked( ! empty( $settings['enable_alerts_resync'] ) ); ?> /> <?php esc_html_e( 'Enable alerts', 'davix-sub-bridge' ); ?></label><br />
                            <label><input type="checkbox" name="enable_recovery_resync" value="1" <?php checked( ! empty( $settings['enable_recovery_resync'] ) ); ?> /> <?php esc_html_e( 'Send recovery notice', 'davix-sub-bridge' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Cron debug log', 'davix-sub-bridge' ); ?></th>
                        <td><label><input type="checkbox" name="enable_cron_debug_resync" value="1" <?php checked( ! empty( $settings['enable_cron_debug_resync'] ) ); ?> /> <?php esc_html_e( 'Enable resync cron debug log', 'davix-sub-bridge' ); ?></label></td>
                    </tr>
                </table>

                <h3 class="dsb-cron-h2"><?php esc_html_e( 'Global Alert Routing', 'davix-sub-bridge' ); ?></h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Alert emails', 'davix-sub-bridge' ); ?></th>
                        <td><textarea name="alert_emails" rows="3" class="large-text" placeholder="admin@example.com&#10;ops@example.com"><?php echo esc_textarea( $settings['alert_emails'] ?? '' ); ?></textarea><p class="description"><?php esc_html_e( 'Comma or newline separated.', 'davix-sub-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Telegram bot token', 'davix-sub-bridge' ); ?></th>
                        <td><input type="text" name="telegram_bot_token" class="regular-text" value="<?php echo esc_attr( $settings['telegram_bot_token'] ?? '' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Telegram chat IDs', 'davix-sub-bridge' ); ?></th>
                        <td><textarea name="telegram_chat_ids" rows="3" class="large-text" placeholder="123456789&#10;-100123456789"><?php echo esc_textarea( $settings['telegram_chat_ids'] ?? '' ); ?></textarea><p class="description"><?php esc_html_e( 'Comma or newline separated chat IDs or @channel handles.', 'davix-sub-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Alert template', 'davix-sub-bridge' ); ?></th>
                        <td><textarea name="alert_template" rows="3" class="large-text" placeholder="{job_name} failed on {site} with {error_excerpt}"><?php echo esc_textarea( $settings['alert_template'] ?? '' ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Recovery template', 'davix-sub-bridge' ); ?></th>
                        <td><textarea name="recovery_template" rows="3" class="large-text" placeholder="{job_name} recovered on {site} at {time}"><?php echo esc_textarea( $settings['recovery_template'] ?? '' ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Alert threshold', 'davix-sub-bridge' ); ?></th>
                        <td><input type="number" name="alert_threshold" min="1" value="<?php echo esc_attr( (int) ( $settings['alert_threshold'] ?? 3 ) ); ?>" /> <p class="description"><?php esc_html_e( 'Consecutive failures before alerting.', 'davix-sub-bridge' ); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Alert cooldown (minutes)', 'davix-sub-bridge' ); ?></th>
                        <td><input type="number" name="alert_cooldown_minutes" min="1" value="<?php echo esc_attr( (int) ( $settings['alert_cooldown_minutes'] ?? 60 ) ); ?>" /> <p class="description"><?php esc_html_e( 'Minimum minutes between alerts for the same job.', 'davix-sub-bridge' ); ?></p></td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save cron settings', 'davix-sub-bridge' ) ); ?>
            </form>

            <h2 class="dsb-cron-h1"><?php esc_html_e( 'Cron Job Status', 'davix-sub-bridge' ); ?></h2>

            <?php $this->render_cron_job_status_section( 'purge_worker', __( 'Purge Worker', 'davix-sub-bridge' ), $settings['enable_purge_worker'] ?? 0, 'Every 5 minutes', wp_next_scheduled( DSB_Purge_Worker::CRON_HOOK ), $purge_status, $logs_link ); ?>
            <?php $this->render_cron_job_status_section( 'node_poll', __( 'Node Poll Sync', 'davix-sub-bridge' ), $settings['enable_node_poll_sync'] ?? 0, sprintf( __( 'Every %d minutes', 'davix-sub-bridge' ), (int) ( $settings['node_poll_interval_minutes'] ?? 10 ) ), wp_next_scheduled( DSB_Node_Poll::CRON_HOOK ), $node_status, $logs_link ); ?>
            <?php $this->render_cron_job_status_section( 'resync', __( 'Daily Resync', 'davix-sub-bridge' ), $settings['enable_daily_resync'] ?? 0, sprintf( __( 'Daily at %02d:00', 'davix-sub-bridge' ), (int) ( $settings['resync_run_hour'] ?? 3 ) ), wp_next_scheduled( DSB_Resync::CRON_HOOK ), $resync_status, $logs_link ); ?>
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
                        <th><?php esc_html_e( 'Enabled', 'davix-sub-bridge' ); ?></th>
                        <td><?php echo $enabled ? esc_html__( 'Yes', 'davix-sub-bridge' ) : esc_html__( 'No', 'davix-sub-bridge' ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Schedule', 'davix-sub-bridge' ); ?></th>
                        <td><?php echo $enabled ? esc_html( $schedule ) : esc_html__( 'Disabled', 'davix-sub-bridge' ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Next run time', 'davix-sub-bridge' ); ?></th>
                        <td>
                            <?php
                            if ( $enabled ) {
                                echo $next_run ? esc_html( gmdate( 'Y-m-d H:i:s', (int) $next_run ) ) : esc_html__( 'Waiting for WP-Cron trigger', 'davix-sub-bridge' );
                            } else {
                                esc_html_e( 'Not scheduled', 'davix-sub-bridge' );
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Last run time', 'davix-sub-bridge' ); ?></th>
                        <td><?php echo esc_html( $last_run ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Last result', 'davix-sub-bridge' ); ?></th>
                        <td><?php echo esc_html( $last_result ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Last duration (ms)', 'davix-sub-bridge' ); ?></th>
                        <td><?php echo esc_html( (string) $last_duration ); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Lock status', 'davix-sub-bridge' ); ?></th>
                        <td>
                            <?php
                            if ( $lock_active ) {
                                printf( esc_html__( 'Locked until %s', 'davix-sub-bridge' ), esc_html( gmdate( 'Y-m-d H:i:s', $lock_until ) ) );
                            } elseif ( $lock_stale ) {
                                esc_html_e( 'Lock stale', 'davix-sub-bridge' );
                            } else {
                                esc_html_e( 'Not locked', 'davix-sub-bridge' );
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Last error', 'davix-sub-bridge' ); ?></th>
                        <td><?php echo esc_html( $last_error ); ?></td>
                    </tr>
                </tbody>
            </table>

            <div style="margin-top:10px;">
                <?php if ( 'purge_worker' === $job_key ) : ?>
                    <form method="post" style="display:inline-block;">
                        <?php wp_nonce_field( 'dsb_run_purge_worker' ); ?>
                        <button type="submit" name="dsb_run_purge_worker" class="button button-secondary"><?php esc_html_e( 'Run now', 'davix-sub-bridge' ); ?></button>
                    </form>
                <?php elseif ( 'node_poll' === $job_key ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                        <input type="hidden" name="action" value="dsb_run_node_poll_now" />
                        <input type="hidden" name="dsb_target_tab" value="cron" />
                        <?php wp_nonce_field( 'dsb_run_node_poll_now', 'dsb_run_node_poll_nonce' ); ?>
                        <button type="submit" class="button button-secondary"><?php esc_html_e( 'Run now', 'davix-sub-bridge' ); ?></button>
                    </form>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                        <input type="hidden" name="action" value="dsb_run_resync_now" />
                        <input type="hidden" name="dsb_target_tab" value="cron" />
                        <?php wp_nonce_field( 'dsb_run_resync_now', 'dsb_run_resync_nonce' ); ?>
                        <button type="submit" class="button button-secondary"><?php esc_html_e( 'Run now', 'davix-sub-bridge' ); ?></button>
                    </form>
                <?php endif; ?>

                <?php if ( 'purge_worker' === $job_key ) : ?>
                    <form method="post" style="display:inline-block;margin-left:8px;">
                        <?php wp_nonce_field( 'dsb_clear_purge_lock' ); ?>
                        <button type="submit" name="dsb_clear_purge_lock" class="button" <?php disabled( ! $lock_stale ); ?>><?php esc_html_e( 'Clear lock', 'davix-sub-bridge' ); ?></button>
                    </form>
                <?php elseif ( 'node_poll' === $job_key ) : ?>
                    <form method="post" style="display:inline-block;margin-left:8px;">
                        <?php wp_nonce_field( 'dsb_clear_node_poll_lock' ); ?>
                        <input type="hidden" name="dsb_clear_node_poll_lock" value="1" />
                        <button type="submit" class="button" <?php disabled( ! $lock_stale ); ?>><?php esc_html_e( 'Clear lock', 'davix-sub-bridge' ); ?></button>
                    </form>
                <?php else : ?>
                    <form method="post" style="display:inline-block;margin-left:8px;">
                        <?php wp_nonce_field( 'dsb_clear_resync_lock' ); ?>
                        <input type="hidden" name="dsb_clear_resync_lock" value="1" />
                        <button type="submit" class="button" <?php disabled( ! $lock_stale ); ?>><?php esc_html_e( 'Clear lock', 'davix-sub-bridge' ); ?></button>
                    </form>
                <?php endif; ?>

                <a class="button" style="margin-left:8px;" href="<?php echo esc_url( $logs_link ); ?>"><?php esc_html_e( 'View DB logs', 'davix-sub-bridge' ); ?></a>
            </div>

            <div style="margin-top:10px;">
                <h4><?php esc_html_e( 'Cron debug log (last 200 lines)', 'davix-sub-bridge' ); ?></h4>
                <textarea class="large-text code" rows="8" readonly><?php echo esc_textarea( $log_tail ); ?></textarea>
                <div style="margin-top:6px;">
                    <a class="button" href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( 'Refresh', 'davix-sub-bridge' ); ?></a>
                    <form method="post" style="display:inline-block;margin-left:6px;">
                        <?php wp_nonce_field( 'dsb_clear_cron_log' ); ?>
                        <input type="hidden" name="dsb_clear_cron_log" value="<?php echo esc_attr( $job_key ); ?>" />
                        <button type="submit" class="button" <?php disabled( empty( $log_tail ) ); ?>><?php esc_html_e( 'Clear cron debug log', 'davix-sub-bridge' ); ?></button>
                    </form>
                </div>
            </div>
        </div>
        <?php
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
