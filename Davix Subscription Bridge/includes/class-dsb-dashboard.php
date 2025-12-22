<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Dashboard {
    protected $client;
    protected static $enqueued = false;
    protected $asset_versions = [
        'css' => null,
        'js'  => null,
    ];

    public function __construct( DSB_Client $client ) {
        $this->client = $client;
    }

    public function init(): void {
        add_shortcode( 'PIXLAB_DASHBOARD', [ $this, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
    }

    public function render(): string {
        $labels  = $this->client->get_label_settings();
        if ( ! is_user_logged_in() ) {
            return '<div class="dsb-dashboard__message">' . esc_html( $labels['label_login_required'] ) . '</div>';
        }

        $styles  = $this->client->get_style_settings();
        $style_attr = $this->build_style_attribute( $styles );
        $raw_settings = get_option( DSB_Client::OPTION_SETTINGS, [] );

        $default_styles   = $this->client->get_style_defaults();
        $override_count   = 0;
        foreach ( $default_styles as $key => $default_value ) {
            if ( isset( $styles[ $key ] ) && $styles[ $key ] !== $default_value ) {
                $override_count ++;
            }
        }

        dsb_log( 'debug', 'Dashboard render preparing styles', [
            'style_attr_length' => strlen( $style_attr ),
            'override_count'    => $override_count,
            'uses_defaults'     => $override_count === 0,
        ] );

        $this->enqueue_assets();

        ob_start();
        ?>
        <?php if ( current_user_can( 'manage_options' ) ) : ?>
        <!-- DSB_STYLE_ATTR: <?php echo esc_html( $style_attr ); ?> -->
        <!-- DSB_STYLES_JSON: <?php echo esc_html( wp_json_encode( $styles ) ); ?> -->
        <!-- DSB_HEADER_VARS: plan_title=<?php echo array_key_exists( 'style_header_plan_title_color', $raw_settings ?? [] ) ? 'on' : 'off'; ?> eyebrow=<?php echo array_key_exists( 'style_header_eyebrow_color', $raw_settings ?? [] ) ? 'on' : 'off'; ?> meta=<?php echo array_key_exists( 'style_header_meta_color', $raw_settings ?? [] ) ? 'on' : 'off'; ?> billing=<?php echo array_key_exists( 'style_header_billing_color', $raw_settings ?? [] ) ? 'on' : 'off'; ?> -->
        <?php echo $this->asset_debug_comment(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php endif; ?>
        <div class="dsb-dashboard" style="<?php echo esc_attr( $style_attr ); ?>">
            <div class="dsb-dashboard__header">
                <div>
                    <p class="dsb-dashboard__eyebrow"><?php echo esc_html( $labels['label_current_plan'] ); ?></p>
                    <h2 class="dsb-dashboard__plan-name" data-plan-name><?php echo esc_html( $labels['label_loading'] ); ?></h2>
                    <p class="dsb-dashboard__plan-meta" data-plan-limit></p>
                    <p class="dsb-dashboard__billing" data-billing-window></p>
                </div>
            </div>

            <div class="dsb-dashboard__grid">
                <div class="dsb-card dsb-card--key">
                    <div class="dsb-card__header">
                        <h3><?php echo esc_html( $labels['label_api_key'] ); ?></h3>
                        <span class="dsb-status" data-key-status></span>
                    </div>
                    <div class="dsb-card__row">
                        <label><?php echo esc_html( $labels['label_key'] ); ?></label>
                        <div class="dsb-card__input-row">
                            <input type="text" readonly class="dsb-card__input" data-key-display value="" />
                        </div>
                        <p class="dsb-card__hint" data-key-created></p>
                    </div>
                    <div class="dsb-card__actions">
                        <button type="button" class="dsb-button dsb-button--outline" data-toggle-key></button>
                        <button type="button" class="dsb-button" data-rotate-key><?php echo esc_html( $labels['label_regenerate_key'] ); ?></button>
                    </div>
                </div>

                <div class="dsb-card dsb-card--usage">
                    <div class="dsb-card__header">
                        <h3><?php echo esc_html( $labels['label_usage_this_period'] ); ?></h3>
                    </div>
                    <div class="dsb-progress" aria-live="polite">
                        <div class="dsb-progress__labels">
                            <span data-usage-calls></span>
                            <span data-usage-percent></span>
                        </div>
                        <div class="dsb-progress__track">
                            <div class="dsb-progress__bar" data-progress-bar style="width:0%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dsb-endpoints">
                <div class="dsb-card dsb-card--endpoint">
                    <p class="dsb-card__eyebrow"><?php echo esc_html( $labels['label_h2i'] ); ?></p>
                    <h4 data-endpoint-h2i>—</h4>
                </div>
                <div class="dsb-card dsb-card--endpoint">
                    <p class="dsb-card__eyebrow"><?php echo esc_html( $labels['label_image'] ); ?></p>
                    <h4 data-endpoint-image>—</h4>
                </div>
                <div class="dsb-card dsb-card--endpoint">
                    <p class="dsb-card__eyebrow"><?php echo esc_html( $labels['label_pdf'] ); ?></p>
                    <h4 data-endpoint-pdf>—</h4>
                </div>
                <div class="dsb-card dsb-card--endpoint">
                    <p class="dsb-card__eyebrow"><?php echo esc_html( $labels['label_tools'] ); ?></p>
                    <h4 data-endpoint-tools>—</h4>
                </div>
            </div>

            <div class="dsb-card dsb-card--history">
                <div class="dsb-card__header">
                    <h3><?php echo esc_html( $labels['label_history'] ); ?></h3>
                </div>
                    <div class="dsb-table" data-log-container>
                    <div class="dsb-table__loading" data-log-loading><?php echo esc_html( $labels['label_loading'] ); ?></div>
                    <div class="dsb-table__empty" data-log-empty style="display:none;">&mdash; <?php echo esc_html( $labels['label_no_requests'] ); ?> &mdash;</div>
                    <div class="dsb-table__scroller">
                        <table>
                            <thead>
                                <tr>
                                    <th><?php echo esc_html( $labels['label_date_time'] ); ?></th>
                                    <th><?php echo esc_html( $labels['label_endpoint'] ); ?></th>
                                    <th><?php echo esc_html( $labels['label_files'] ); ?></th>
                                    <th><?php echo esc_html( $labels['label_bytes_in'] ); ?></th>
                                    <th><?php echo esc_html( $labels['label_bytes_out'] ); ?></th>
                                    <th><?php echo esc_html( $labels['label_error'] ); ?></th>
                                    <th><?php echo esc_html( $labels['label_status'] ); ?></th>
                                </tr>
                            </thead>
                            <tbody data-log-rows></tbody>
                        </table>
                    </div>
                    <div class="dsb-pagination" data-log-pagination style="display:none;">
                        <button type="button" class="dsb-button dsb-button--ghost" data-log-prev><?php echo esc_html( $labels['label_pagination_previous'] ); ?></button>
                        <span data-log-page></span>
                        <button type="button" class="dsb-button dsb-button--ghost" data-log-next><?php echo esc_html( $labels['label_pagination_next'] ); ?></button>
                    </div>
                </div>
            </div>

            <div class="dsb-modal" data-modal aria-hidden="true">
                <div class="dsb-modal__overlay" data-modal-overlay></div>
                <div class="dsb-modal__content" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $labels['label_modal_title'] ); ?>">
                    <h3><?php echo esc_html( $labels['label_modal_title'] ); ?></h3>
                    <p class="dsb-modal__message" data-modal-message><?php echo esc_html( $labels['label_modal_hint'] ); ?></p>
                    <div class="dsb-card__input-row">
                        <input type="text" readonly class="dsb-card__input" data-modal-key value="" />
                        <button type="button" class="dsb-button dsb-button--ghost" data-modal-copy>📋</button>
                    </div>
                    <p class="dsb-card__hint"><?php echo esc_html( $labels['label_modal_hint'] ); ?></p>
                    <div class="dsb-card__actions">
                        <button type="button" class="dsb-button" data-modal-close><?php echo esc_html( $labels['label_modal_close'] ); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function maybe_enqueue_assets(): void {
        if ( ! is_singular() ) {
            return;
        }

        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'PIXLAB_DASHBOARD' ) ) {
            return;
        }

        $this->enqueue_assets();
    }

    protected function enqueue_assets(): void {
        if ( self::$enqueued ) {
            return;
        }

        $labels = $this->client->get_label_settings();

        $css_path = DSB_PLUGIN_DIR . 'assets/css/dsb-dashboard.css';
        $css_ver  = file_exists( $css_path ) ? DSB_VERSION . '.' . filemtime( $css_path ) : DSB_VERSION;
        $this->asset_versions['css'] = $css_ver;

        wp_register_style(
            'dsb-dashboard',
            DSB_PLUGIN_URL . 'assets/css/dsb-dashboard.css',
            [],
            $css_ver
        );

        $js_path = DSB_PLUGIN_DIR . 'assets/js/dsb-dashboard.js';
        $js_ver  = file_exists( $js_path ) ? DSB_VERSION . '.' . filemtime( $js_path ) : DSB_VERSION;
        $this->asset_versions['js'] = $js_ver;

        wp_register_script(
            'dsb-dashboard',
            DSB_PLUGIN_URL . 'assets/js/dsb-dashboard.js',
            [],
            $js_ver,
            true
        );

        wp_localize_script(
            'dsb-dashboard',
            'dsbDashboardData',
            [
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'nonce'        => wp_create_nonce( 'dsb_dashboard_nonce' ),
                'isAdmin'      => current_user_can( 'manage_options' ),
                'labels'       => $labels,
                'strings'      => [
                    'loading'       => $labels['label_loading'],
                    'error'         => __( 'Unable to load dashboard right now.', 'davix-sub-bridge' ),
                    'copied'        => __( 'Copied to clipboard.', 'davix-sub-bridge' ),
                    'copyFailed'    => __( 'Copy failed.', 'davix-sub-bridge' ),
                    'confirmRotate' => __( 'Are you sure you want to regenerate your API key?', 'davix-sub-bridge' ),
                    'rotateError'   => __( 'Unable to regenerate key.', 'davix-sub-bridge' ),
                    'shownOnce'     => $labels['label_modal_hint'],
                    'toggleOn'      => $labels['label_enable_key'],
                    'toggleOff'     => $labels['label_disable_key'],
                    'toggleError'   => __( 'Unable to update key.', 'davix-sub-bridge' ),
                    'toastSuccess'  => __( 'Updated', 'davix-sub-bridge' ),
                ],
            ]
        );

        wp_enqueue_style( 'dsb-dashboard' );
        wp_enqueue_script( 'dsb-dashboard' );
        self::$enqueued = true;
    }

    protected function asset_debug_comment(): string {
        if ( ! current_user_can( 'manage_options' ) ) {
            return '';
        }

        $css_ver = $this->asset_versions['css'] ?? DSB_VERSION;
        $js_ver  = $this->asset_versions['js'] ?? DSB_VERSION;

        return sprintf(
            '<!-- DSB assets: dashboard.css ver=%s dashboard.js ver=%s -->',
            esc_html( $css_ver ),
            esc_html( $js_ver )
        );
    }

    protected function build_style_attribute( array $styles ): string {
        $raw_settings = get_option( DSB_Client::OPTION_SETTINGS, [] );
        $defaults     = $this->client->get_style_defaults();
        $optional_override_keys = [
            'style_header_plan_title_color',
            'style_header_plan_title_opacity',
            'style_header_eyebrow_color',
            'style_header_meta_color',
            'style_header_billing_color',
        ];
        $map = [
            '--dsb-plan-title-color'       => 'style_plan_title_color',
            '--dsb-plan-title-size'        => 'style_plan_title_size',
            '--dsb-plan-title-weight'      => 'style_plan_title_weight',
            '--dsb-header-plan-title-color'=> 'style_header_plan_title_color',
            '--dsb-header-plan-title-opacity' => 'style_header_plan_title_opacity',
            '--dsb-header-eyebrow-color'   => 'style_header_eyebrow_color',
            '--dsb-header-meta-color'      => 'style_header_meta_color',
            '--dsb-header-billing-color'   => 'style_header_billing_color',
            '--dsb-eyebrow-color'          => 'style_eyebrow_color',
            '--dsb-eyebrow-size'           => 'style_eyebrow_size',
            '--dsb-eyebrow-spacing'        => 'style_eyebrow_spacing',
            '--dsb-card-header-color'      => 'style_card_header_color',
            '--dsb-card-header-size'       => 'style_card_header_size',
            '--dsb-card-header-weight'     => 'style_card_header_weight',
            '--dsb-card-text'              => 'style_card_text_color',
            '--dsb-card-label'             => 'style_card_label_color',
            '--dsb-card-hint'              => 'style_card_hint_color',
            '--dsb-endpoint-eyebrow'       => 'style_endpoint_eyebrow_color',
            '--dsb-bg'                    => 'style_dashboard_bg',
            '--dsb-card-bg'               => 'style_card_bg',
            '--dsb-card-border'           => 'style_card_border',
            '--dsb-card-shadow'           => 'style_card_shadow',
            '--dsb-card-radius'           => 'style_card_radius',
            '--dsb-card-shadow-blur'      => 'style_card_shadow_blur',
            '--dsb-card-shadow-spread'    => 'style_card_shadow_spread',
            '--dsb-container-padding'     => 'style_container_padding',
            '--dsb-text-primary'          => 'style_text_primary',
            '--dsb-text-secondary'        => 'style_text_secondary',
            '--dsb-text-muted'            => 'style_text_muted',
            '--dsb-button-bg'             => 'style_button_bg',
            '--dsb-button-text'           => 'style_button_text',
            '--dsb-button-border'         => 'style_button_border',
            '--dsb-button-hover-bg'       => 'style_button_hover_bg',
            '--dsb-button-hover-border'   => 'style_button_hover_border',
            '--dsb-button-active-bg'      => 'style_button_active_bg',
            '--dsb-btn-primary-bg'        => 'style_btn_primary_bg',
            '--dsb-btn-primary-text'      => 'style_btn_primary_text',
            '--dsb-btn-primary-border'    => 'style_btn_primary_border',
            '--dsb-btn-primary-hover-bg'  => 'style_btn_primary_hover_bg',
            '--dsb-btn-primary-hover-text'=> 'style_btn_primary_hover_text',
            '--dsb-btn-primary-hover-border' => 'style_btn_primary_hover_border',
            '--dsb-btn-primary-shadow-color' => 'style_btn_primary_shadow_color',
            '--dsb-btn-primary-shadow-strength' => 'style_btn_primary_shadow_strength',
            '--dsb-btn-outline-bg'        => 'style_btn_outline_bg',
            '--dsb-btn-outline-text'      => 'style_btn_outline_text',
            '--dsb-btn-outline-border'    => 'style_btn_outline_border',
            '--dsb-btn-outline-hover-bg'  => 'style_btn_outline_hover_bg',
            '--dsb-btn-outline-hover-text'=> 'style_btn_outline_hover_text',
            '--dsb-btn-outline-hover-border' => 'style_btn_outline_hover_border',
            '--dsb-btn-ghost-bg'          => 'style_btn_ghost_bg',
            '--dsb-btn-ghost-text'        => 'style_btn_ghost_text',
            '--dsb-btn-ghost-border'      => 'style_btn_ghost_border',
            '--dsb-btn-ghost-hover-bg'    => 'style_btn_ghost_hover_bg',
            '--dsb-btn-ghost-hover-text'  => 'style_btn_ghost_hover_text',
            '--dsb-btn-ghost-hover-border'=> 'style_btn_ghost_hover_border',
            '--dsb-input-bg'              => 'style_input_bg',
            '--dsb-input-text'            => 'style_input_text',
            '--dsb-input-border'          => 'style_input_border',
            '--dsb-input-focus-border'    => 'style_input_focus_border',
            '--dsb-badge-active-bg'       => 'style_badge_active_bg',
            '--dsb-badge-active-border'   => 'style_badge_active_border',
            '--dsb-badge-active-text'     => 'style_badge_active_text',
            '--dsb-badge-disabled-bg'     => 'style_badge_disabled_bg',
            '--dsb-badge-disabled-border' => 'style_badge_disabled_border',
            '--dsb-badge-disabled-text'   => 'style_badge_disabled_text',
            '--dsb-progress-track'        => 'style_progress_track',
            '--dsb-progress-fill'         => 'style_progress_fill',
            '--dsb-progress-fill-hover'   => 'style_progress_fill_hover',
            '--dsb-progress-track-border' => 'style_progress_track_border',
            '--dsb-progress-text'         => 'style_progress_text',
            '--dsb-table-bg'              => 'style_table_bg',
            '--dsb-table-header-bg'       => 'style_table_header_bg',
            '--dsb-table-header-text'     => 'style_table_header_text',
            '--dsb-table-border'          => 'style_table_border',
            '--dsb-table-row-bg'          => 'style_table_row_bg',
            '--dsb-table-row-text'        => 'style_table_row_text',
            '--dsb-table-row-border'      => 'style_table_row_border',
            '--dsb-table-row-hover-bg'    => 'style_table_row_hover_bg',
            '--dsb-table-empty-text'      => 'style_table_empty_text_color',
            '--dsb-table-error-text'      => 'style_table_error_text',
            '--dsb-status-success-text'   => 'style_status_success_text',
            '--dsb-status-error-text'     => 'style_status_error_text',
            '--dsb-overlay'               => 'style_overlay_color',
        ];

        $pairs = [];
        foreach ( $map as $css_var => $setting_key ) {
            $value = $styles[ $setting_key ] ?? '';
            $is_optional = in_array( $setting_key, $optional_override_keys, true );
            if ( $is_optional ) {
                $has_raw = is_array( $raw_settings ) && array_key_exists( $setting_key, $raw_settings );
                $default_value = $defaults[ $setting_key ] ?? '';
                if ( ! $has_raw || '' === $value || $value === $default_value ) {
                    continue;
                }
            }
            if ( '' === $value ) {
                continue;
            }
            $pairs[] = $css_var . ':' . $value;
        }

        return implode( ';', $pairs );
    }
}
