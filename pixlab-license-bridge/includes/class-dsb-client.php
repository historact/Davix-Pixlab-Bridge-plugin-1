<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Client {
    const OPTION_SETTINGS      = 'dsb_settings';
    const OPTION_PRODUCT_PLANS = 'dsb_product_plans';
    const OPTION_PLAN_PRODUCTS = 'dsb_plan_products';
    const OPTION_PLAN_SYNC     = 'dsb_plan_sync';
    const OPTION_LEVEL_PLANS   = 'dsb_level_plans';

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
                'free_level_id' => '',
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
                'enable_alerts_provision_worker'   => 0,
                'enable_recovery_provision_worker' => 0,
                'enable_alerts_node_poll'      => 0,
                'enable_recovery_node_poll'    => 0,
                'enable_alerts_resync'         => 0,
                'enable_recovery_resync'       => 0,
                'enable_cron_debug_purge_worker' => 0,
                'enable_cron_debug_provision_worker' => 0,
                'enable_cron_debug_node_poll'    => 0,
                'enable_cron_debug_resync'       => 0,
                'settings_access' => [
                    'enabled'       => 0,
                    'allowed_roles' => [],
                ],
            ],
            $this->get_style_defaults(),
            $this->get_label_defaults()
        );
        $options  = get_option( self::OPTION_SETTINGS, [] );
        return wp_parse_args( is_array( $options ) ? $options : [], $defaults );
    }

    public function has_external_bridge_token(): bool {
        if ( defined( 'DSB_BRIDGE_TOKEN' ) && DSB_BRIDGE_TOKEN ) {
            return true;
        }

        $env = getenv( 'DSB_BRIDGE_TOKEN' );
        return is_string( $env ) && '' !== trim( $env );
    }

    public function is_settings_locked(): bool {
        if ( defined( 'DSB_LOCK_SETTINGS' ) && DSB_LOCK_SETTINGS ) {
            return true;
        }

        if ( defined( 'WP_ENV' ) && 'production' === WP_ENV ) {
            return true;
        }

        $env = getenv( 'WP_ENV' );
        return is_string( $env ) && 'production' === $env;
    }

    public function get_bridge_token(): string {
        if ( defined( 'DSB_BRIDGE_TOKEN' ) && DSB_BRIDGE_TOKEN ) {
            return trim( (string) DSB_BRIDGE_TOKEN );
        }

        $env = getenv( 'DSB_BRIDGE_TOKEN' );
        if ( is_string( $env ) && '' !== trim( $env ) ) {
            return trim( $env );
        }

        if ( $this->is_settings_locked() ) {
            return '';
        }

        $settings = $this->get_settings();
        return (string) ( $settings['bridge_token'] ?? '' );
    }

    public function get_style_defaults(): array {
        return [
            'style_header_text'              => '#f8fafc',

            'style_plan_title_size'        => '24px',
            'style_plan_title_weight'      => '700',
            'style_header_plan_title_color'=> '#f8fafc',
            'style_header_plan_title_opacity' => '1',
            'style_header_eyebrow_color'   => '#94a3b8',
            'style_header_meta_color'      => '#cbd5e1',
            'style_header_billing_color'   => '#cbd5e1',
            'style_eyebrow_color'          => '#94a3b8',
            'style_eyebrow_size'           => '12px',
            'style_eyebrow_spacing'        => '0.08em',

            // New grouped cards defaults.
            'style_cards_bg'                => '#0b1220',
            'style_cards_border'            => '#1e293b',
            'style_cards_shadow_color'      => 'rgba(0,0,0,0.4)',
            'style_cards_text'              => '#f8fafc',
            'style_dashboard_shadow_color'  => 'rgba(0,0,0,0.4)',
            'style_dashboard_border_color'  => '#1e293b',

            'style_card_header_color'      => '#f8fafc',
            'style_card_header_size'       => '18px',
            'style_card_header_weight'     => '700',
            'style_card_text_color'        => '#f8fafc',
            'style_card_label_color'       => '#94a3b8',
            'style_card_hint_color'        => '',
            'style_endpoint_eyebrow_color' => '',
            'style_dashboard_bg'          => '#0f172a',
            'style_card_bg'               => '#0b1220',
            'style_card_border'           => '#1e293b',
            'style_card_shadow'           => 'rgba(0,0,0,0.4)',
            'style_card_radius'           => '12px',
            'style_card_shadow_blur'      => '16px',
            'style_card_shadow_spread'    => '0px',
            'style_container_padding'     => '24px',
            'style_text_primary'          => '#f8fafc',
            'style_text_secondary'        => '#cbd5e1',
            'style_text_muted'            => '#94a3b8',
            'style_button_bg'             => '#0ea5e9', // legacy
            'style_button_text'           => '#0b1220', // legacy
            'style_button_border'         => '#0ea5e9', // legacy
            'style_button_hover_bg'       => '#0ea5e9', // legacy
            'style_button_hover_border'   => '#0ea5e9', // legacy
            'style_button_active_bg'      => '#0ea5e9', // legacy
            'style_btn_primary_bg'        => '#0ea5e9',
            'style_btn_primary_text'      => '#0b1220',
            'style_btn_primary_border'    => '#0ea5e9',
            'style_btn_primary_hover_bg'  => '#0ea5e9',
            'style_btn_primary_hover_text'=> '#0b1220',
            'style_btn_primary_hover_border' => '#0ea5e9',
            'style_btn_primary_shadow_color'  => 'rgba(14,165,233,0.25)',
            'style_btn_primary_shadow_strength' => '1',
            // New grouped primary button states.
            'style_btn_primary_normal_bg'    => '#0ea5e9',
            'style_btn_primary_normal_border'=> '#0ea5e9',
            'style_btn_primary_normal_text'  => '#0b1220',
            'style_btn_primary_hover_text'   => '#0b1220',
            'style_btn_primary_hover_border' => '#0ea5e9',
            'style_btn_primary_active_bg'    => '#0ea5e9',
            'style_btn_primary_active_border'=> '#0ea5e9',
            'style_btn_primary_active_text'  => '#0b1220',
            'style_btn_outline_bg'        => 'transparent',
            'style_btn_outline_text'      => '#f8fafc',
            'style_btn_outline_border'    => '#0ea5e9',
            'style_btn_outline_hover_bg'  => '#0ea5e9',
            'style_btn_outline_hover_text'=> '#0b1220',
            'style_btn_outline_hover_border' => '#0ea5e9',
            'style_btn_outline_normal_bg'    => 'transparent',
            'style_btn_outline_normal_border'=> '#0ea5e9',
            'style_btn_outline_normal_text'  => '#f8fafc',
            'style_btn_outline_active_bg'    => '#0ea5e9',
            'style_btn_outline_active_border'=> '#0ea5e9',
            'style_btn_outline_active_text'  => '#0b1220',
            'style_btn_ghost_bg'          => 'transparent',
            'style_btn_ghost_text'        => '#f8fafc',
            'style_btn_ghost_border'      => '#0ea5e9',
            'style_btn_ghost_hover_bg'    => '#0ea5e9',
            'style_btn_ghost_hover_text'  => '#0b1220',
            'style_btn_ghost_hover_border'=> '#0ea5e9',
            'style_btn_ghost_normal_bg'      => 'transparent',
            'style_btn_ghost_normal_border'  => '#0ea5e9',
            'style_btn_ghost_normal_text'    => '#f8fafc',
            'style_btn_ghost_active_bg'      => '#0ea5e9',
            'style_btn_ghost_active_border'  => '#0ea5e9',
            'style_btn_ghost_active_text'    => '#0b1220',
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
            'style_progress_fill_hover'   => '#22c55e',
            'style_progress_track_border' => 'transparent',
            'style_progress_text'         => '#cbd5e1',
            'style_table_bg'              => '#0b1220',
            'style_table_header_bg'       => '#0f172a',
            'style_table_header_text'     => '#cbd5e1',
            'style_table_border'          => '#1e293b',
            'style_table_row_bg'          => '#0e1627',
            'style_table_row_text'        => '#f8fafc',
            'style_table_row_border'      => '',
            'style_table_row_hover_bg'    => '#111827',
            'style_table_empty_text_color'=> '#94a3b8',
            'style_table_error_text'      => '#f87171',
            'style_status_success_text'   => '#22c55e',
            'style_status_error_text'     => '#f87171',
            // New grouped table defaults (explicit).
            'style_table_body_text'       => '#f8fafc',
            'style_table_empty_text'      => '#94a3b8',
            'style_overlay_color'         => 'rgba(0,0,0,0.6)',
        ];
    }

    public function get_label_defaults(): array {
        return [
            'label_current_plan'          => __( 'Current Plan', 'pixlab-license-bridge' ),
            'label_usage_metered'         => __( 'Monthly limit', 'pixlab-license-bridge' ),
            'label_api_key'               => __( 'API Key', 'pixlab-license-bridge' ),
            'label_key'                   => __( 'Key', 'pixlab-license-bridge' ),
            'label_created'               => __( 'Created', 'pixlab-license-bridge' ),
            'label_disable_key'           => __( 'Disable Key', 'pixlab-license-bridge' ),
            'label_enable_key'            => __( 'Enable Key', 'pixlab-license-bridge' ),
            'dsb_label_enabled'           => __( 'Enabled', 'pixlab-license-bridge' ),
            'dsb_label_disabled'          => __( 'Disabled', 'pixlab-license-bridge' ),
            'dsb_label_provisioning'      => __( 'Provisioning...', 'pixlab-license-bridge' ),
            'dsb_label_expired_message'   => __( 'Your API key is expired. Please renew to enable.', 'pixlab-license-bridge' ),
            'label_regenerate_key'        => __( 'Regenerate Key', 'pixlab-license-bridge' ),
            'label_usage_this_period'     => __( 'Usage this period', 'pixlab-license-bridge' ),
            'label_used_calls'            => __( 'Used Calls', 'pixlab-license-bridge' ),
            'label_history'               => __( 'History', 'pixlab-license-bridge' ),
            'label_h2i'                   => __( 'H2I', 'pixlab-license-bridge' ),
            'label_image'                 => __( 'IMAGE', 'pixlab-license-bridge' ),
            'label_pdf'                   => __( 'PDF', 'pixlab-license-bridge' ),
            'label_tools'                 => __( 'TOOLS', 'pixlab-license-bridge' ),
            'label_date_time'             => __( 'Date/Time', 'pixlab-license-bridge' ),
            'label_endpoint'              => __( 'Endpoint', 'pixlab-license-bridge' ),
            'label_action'                => __( 'Action', 'pixlab-license-bridge' ),
            'label_files'                 => __( 'Files', 'pixlab-license-bridge' ),
            'label_bytes_in'              => __( 'Bytes In', 'pixlab-license-bridge' ),
            'label_bytes_out'             => __( 'Bytes Out', 'pixlab-license-bridge' ),
            'label_error'                 => __( 'Error', 'pixlab-license-bridge' ),
            'label_status'                => __( 'Status', 'pixlab-license-bridge' ),
            'label_create_key'            => __( 'Create Key', 'pixlab-license-bridge' ),
            'label_create_api_key_title'  => __( 'Create API Key', 'pixlab-license-bridge' ),
            'label_create_api_key_submit' => __( 'Create API Key', 'pixlab-license-bridge' ),
            'label_usage_metered_hint'    => __( 'Usage metered', 'pixlab-license-bridge' ),
            'label_login_required'        => __( 'Please log in to view your API usage.', 'pixlab-license-bridge' ),
            'label_loading'               => __( 'Loading…', 'pixlab-license-bridge' ),
            'label_no_requests'           => __( 'No requests yet.', 'pixlab-license-bridge' ),
            'label_pagination_previous'   => __( 'Previous', 'pixlab-license-bridge' ),
            'label_pagination_next'       => __( 'Next', 'pixlab-license-bridge' ),
            'label_modal_title'           => __( 'Your new API key', 'pixlab-license-bridge' ),
            'label_modal_hint'            => __( 'Shown once — copy it now.', 'pixlab-license-bridge' ),
            'label_modal_close'           => __( 'Close', 'pixlab-license-bridge' ),
        ];
    }

    public function get_style_settings(): array {
        $defaults = $this->get_style_defaults();
        $raw_settings = get_option( self::OPTION_SETTINGS, [] );
        $settings = is_array( $raw_settings ) ? $raw_settings : [];
        $resolved = [];

        foreach ( $defaults as $key => $default ) {
            $value = array_key_exists( $key, $settings ) ? (string) $settings[ $key ] : '';
            $resolved[ $key ] = '' === $value ? $default : $value;
        }

        $legacy_fallbacks = [
            'style_btn_primary_bg'           => 'style_button_bg',
            'style_btn_primary_text'         => 'style_button_text',
            'style_btn_primary_border'       => 'style_button_border',
            'style_btn_primary_hover_bg'     => 'style_button_hover_bg',
            'style_btn_primary_hover_border' => 'style_button_hover_border',
            'style_btn_primary_hover_text'   => 'style_button_text',
        ];

        foreach ( $legacy_fallbacks as $new_key => $legacy_key ) {
            if ( isset( $settings[ $legacy_key ] ) && ( ! isset( $settings[ $new_key ] ) || '' === $settings[ $new_key ] ) ) {
                $resolved[ $new_key ] = sanitize_text_field( (string) $settings[ $legacy_key ] );
            }
        }

        // Seed new grouped header styles when not explicitly set.
        $seed_if_absent = function ( string $target_key, array $candidate_keys ) use ( &$resolved, $raw_settings ) {
            if ( array_key_exists( $target_key, $raw_settings ) && '' !== (string) $raw_settings[ $target_key ] ) {
                return;
            }
            foreach ( $candidate_keys as $candidate ) {
                if ( isset( $resolved[ $candidate ] ) && '' !== (string) $resolved[ $candidate ] ) {
                    $resolved[ $target_key ] = $resolved[ $candidate ];
                    break;
                }
            }
        };

        $seed_if_absent( 'style_header_text', [ 'style_header_plan_title_color', 'style_text_primary' ] );
        $seed_if_absent( 'style_dashboard_border_color', [ 'style_header_border', 'style_card_border' ] );
        $seed_if_absent( 'style_dashboard_shadow_color', [ 'style_header_shadow_color', 'style_card_shadow' ] );

        // Seed new grouped card styles.
        $seed_if_absent( 'style_cards_text', [ 'style_card_text_color', 'style_text_primary' ] );
        $seed_if_absent( 'style_cards_bg', [ 'style_card_bg' ] );
        $seed_if_absent( 'style_cards_border', [ 'style_card_border' ] );
        $seed_if_absent( 'style_cards_shadow_color', [ 'style_card_shadow' ] );

        // Seed new grouped primary button states from legacy/new combos.
        $seed_if_absent( 'style_btn_primary_normal_bg', [ 'style_btn_primary_bg', 'style_button_bg' ] );
        $seed_if_absent( 'style_btn_primary_normal_border', [ 'style_btn_primary_border', 'style_button_border' ] );
        $seed_if_absent( 'style_btn_primary_normal_text', [ 'style_btn_primary_text', 'style_button_text' ] );
        $seed_if_absent( 'style_btn_primary_hover_bg', [ 'style_btn_primary_hover_bg', 'style_button_hover_bg', 'style_btn_primary_bg' ] );
        $seed_if_absent( 'style_btn_primary_hover_border', [ 'style_btn_primary_hover_border', 'style_button_hover_border', 'style_btn_primary_border' ] );
        $seed_if_absent( 'style_btn_primary_hover_text', [ 'style_btn_primary_hover_text', 'style_btn_primary_text', 'style_button_text' ] );
        $seed_if_absent( 'style_btn_primary_active_bg', [ 'style_button_active_bg', 'style_btn_primary_hover_bg', 'style_btn_primary_bg' ] );
        $seed_if_absent( 'style_btn_primary_active_border', [ 'style_btn_primary_hover_border', 'style_btn_primary_border' ] );
        $seed_if_absent( 'style_btn_primary_active_text', [ 'style_btn_primary_hover_text', 'style_btn_primary_text', 'style_button_text' ] );

        // Seed outline buttons.
        $seed_if_absent( 'style_btn_outline_normal_bg', [ 'style_btn_outline_bg', 'style_btn_outline_hover_bg' ] );
        $seed_if_absent( 'style_btn_outline_normal_border', [ 'style_btn_outline_border', 'style_btn_outline_hover_border', 'style_button_border' ] );
        $seed_if_absent( 'style_btn_outline_normal_text', [ 'style_btn_outline_text', 'style_btn_outline_hover_text', 'style_text_primary' ] );
        $seed_if_absent( 'style_btn_outline_hover_bg', [ 'style_btn_outline_hover_bg', 'style_btn_outline_bg' ] );
        $seed_if_absent( 'style_btn_outline_hover_border', [ 'style_btn_outline_hover_border', 'style_btn_outline_border' ] );
        $seed_if_absent( 'style_btn_outline_hover_text', [ 'style_btn_outline_hover_text', 'style_btn_outline_text' ] );
        $seed_if_absent( 'style_btn_outline_active_bg', [ 'style_btn_outline_hover_bg', 'style_btn_outline_bg' ] );
        $seed_if_absent( 'style_btn_outline_active_border', [ 'style_btn_outline_hover_border', 'style_btn_outline_border' ] );
        $seed_if_absent( 'style_btn_outline_active_text', [ 'style_btn_outline_hover_text', 'style_btn_outline_text' ] );

        // Seed ghost buttons.
        $seed_if_absent( 'style_btn_ghost_normal_bg', [ 'style_btn_ghost_bg', 'style_btn_ghost_hover_bg' ] );
        $seed_if_absent( 'style_btn_ghost_normal_border', [ 'style_btn_ghost_border', 'style_btn_ghost_hover_border', 'style_button_border' ] );
        $seed_if_absent( 'style_btn_ghost_normal_text', [ 'style_btn_ghost_text', 'style_btn_ghost_hover_text', 'style_text_primary' ] );
        $seed_if_absent( 'style_btn_ghost_hover_bg', [ 'style_btn_ghost_hover_bg', 'style_btn_ghost_bg' ] );
        $seed_if_absent( 'style_btn_ghost_hover_border', [ 'style_btn_ghost_hover_border', 'style_btn_ghost_border' ] );
        $seed_if_absent( 'style_btn_ghost_hover_text', [ 'style_btn_ghost_hover_text', 'style_btn_ghost_text' ] );
        $seed_if_absent( 'style_btn_ghost_active_bg', [ 'style_btn_ghost_hover_bg', 'style_btn_ghost_bg' ] );
        $seed_if_absent( 'style_btn_ghost_active_border', [ 'style_btn_ghost_hover_border', 'style_btn_ghost_border' ] );
        $seed_if_absent( 'style_btn_ghost_active_text', [ 'style_btn_ghost_hover_text', 'style_btn_ghost_text' ] );

        // Seed status bubbles from legacy badges.
        $seed_if_absent( 'style_status_active_bg', [ 'style_badge_active_bg' ] );
        $seed_if_absent( 'style_status_active_border', [ 'style_badge_active_border' ] );
        $seed_if_absent( 'style_status_active_text', [ 'style_badge_active_text' ] );
        $seed_if_absent( 'style_status_disabled_bg', [ 'style_badge_disabled_bg' ] );
        $seed_if_absent( 'style_status_disabled_border', [ 'style_badge_disabled_border' ] );
        $seed_if_absent( 'style_status_disabled_text', [ 'style_badge_disabled_text' ] );

        // Seed table group.
        $seed_if_absent( 'style_table_body_text', [ 'style_table_row_text' ] );
        $seed_if_absent( 'style_table_empty_text', [ 'style_table_empty_text_color' ] );

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

    public function get_level_plans(): array {
        $plans = get_option( self::OPTION_LEVEL_PLANS, [] );
        if ( ! is_array( $plans ) ) {
            return [];
        }

        $clean = [];
        foreach ( $plans as $level_id => $plan_slug ) {
            $lid = absint( $level_id );
            if ( $lid <= 0 ) {
                continue;
            }
            $clean[ $lid ] = dsb_normalize_plan_slug( $plan_slug );
        }

        if ( $clean !== $plans ) {
            update_option( self::OPTION_LEVEL_PLANS, $clean );
        }

        return $clean;
    }

    public function save_level_plans( array $map ): void {
        $clean = [];
        foreach ( $map as $level_id => $plan_slug ) {
            $lid = absint( $level_id );
            if ( $lid <= 0 ) {
                continue;
            }
            $slug = dsb_normalize_plan_slug( $plan_slug );
            if ( '' !== $slug ) {
                $clean[ $lid ] = $slug;
            }
        }

        update_option( self::OPTION_LEVEL_PLANS, $clean );
    }

    public function plan_slug_for_level( int $level_id ): string {
        $plans = $this->get_level_plans();
        if ( isset( $plans[ $level_id ] ) ) {
            return $plans[ $level_id ];
        }

        if ( function_exists( 'pmpro_getLevel' ) ) {
            $level = pmpro_getLevel( $level_id );
            if ( $level && isset( $level->name ) ) {
                return dsb_normalize_plan_slug( sanitize_text_field( (string) $level->name ) );
            }
        }

        return '';
    }

    public function get_plan_sync_status(): array {
        $status = get_option( self::OPTION_PLAN_SYNC, [] );
        if ( ! is_array( $status ) ) {
            return [];
        }
        return $status;
    }

    public function save_settings( array $data ): void {
        $existing_raw = get_option( self::OPTION_SETTINGS, [] );
        $existing = $this->get_settings();

        $debug_enabled = 0;
        if ( isset( $data['debug_enabled'] ) ) {
            $debug_value   = is_array( $data['debug_enabled'] ) ? end( $data['debug_enabled'] ) : $data['debug_enabled'];
            $debug_enabled = (int) ( '1' === (string) $debug_value );
        }
        if ( $debug_enabled && function_exists( __NAMESPACE__ . '\\dsb_is_production_env' ) && dsb_is_production_env() && function_exists( __NAMESPACE__ . '\\dsb_is_log_path_public' ) && dsb_is_log_path_public( dsb_get_log_dir() ) ) {
            $debug_enabled = 0;
        }

        $enable_logging_value = isset( $data['enable_logging'] ) ? ( is_array( $data['enable_logging'] ) ? end( $data['enable_logging'] ) : $data['enable_logging'] ) : null;
        $allow_without_refs_value = isset( $data['allow_provision_without_refs'] ) ? ( is_array( $data['allow_provision_without_refs'] ) ? end( $data['allow_provision_without_refs'] ) : $data['allow_provision_without_refs'] ) : null;

        $bool_from_post = function ( array $source, string $key, $existing_default ) {
            if ( array_key_exists( $key, $source ) ) {
                $value = is_array( $source[ $key ] ) ? end( $source[ $key ] ) : $source[ $key ];
                return (int) ( '1' === (string) $value );
            }
            return (int) $existing_default;
        };

        $node_base_url = esc_url_raw( $data['node_base_url'] ?? ( $existing['node_base_url'] ?? '' ) );
        if ( $node_base_url && ! $this->is_node_url_allowed( $node_base_url ) ) {
            $masked = function_exists( 'dsb_mask_string' ) ? dsb_mask_string( $node_base_url ) : $node_base_url;
            dsb_log( 'error', 'Rejected node base URL', [ 'node_base_url' => $masked ] );
            $fallback = $existing['node_base_url'] ?? '';
            $node_base_url = $fallback && $this->is_node_url_allowed( $fallback ) ? $fallback : '';
        }

        $bridge_token = $existing['bridge_token'] ?? '';
        $bridge_token_clear = isset( $data['bridge_token_clear'] ) && '1' === (string) ( is_array( $data['bridge_token_clear'] ) ? end( $data['bridge_token_clear'] ) : $data['bridge_token_clear'] );
        if ( $this->has_external_bridge_token() || $this->is_settings_locked() ) {
            $bridge_token = '';
        } elseif ( $bridge_token_clear ) {
            $bridge_token = '';
        } elseif ( array_key_exists( 'bridge_token', $data ) ) {
            $incoming_token = sanitize_text_field( $data['bridge_token'] );
            if ( '' !== trim( $incoming_token ) ) {
                $bridge_token = $incoming_token;
            }
        }

        $clean = [
            'node_base_url' => $node_base_url,
            'bridge_token'  => $bridge_token,
            'enable_logging'=> $enable_logging_value !== null ? (int) ( '1' === (string) $enable_logging_value ) : ( $existing['enable_logging'] ?? 0 ),
            'debug_enabled' => $debug_enabled,
            'debug_level'   => isset( $data['debug_level'] ) ? sanitize_key( $data['debug_level'] ) : ( $existing['debug_level'] ?? 'info' ),
            'debug_retention_days' => isset( $data['debug_retention_days'] ) ? max( 1, (int) $data['debug_retention_days'] ) : ( $existing['debug_retention_days'] ?? 7 ),
            'delete_data'   => $bool_from_post( $data, 'delete_data', $existing['delete_data'] ?? 0 ),
            'allow_provision_without_refs' => $allow_without_refs_value !== null ? (int) ( '1' === (string) $allow_without_refs_value ) : ( $existing['allow_provision_without_refs'] ?? 0 ),
            'enable_daily_resync' => $bool_from_post( $data, 'enable_daily_resync', $existing['enable_daily_resync'] ?? 0 ),
            'resync_batch_size' => isset( $data['resync_batch_size'] ) ? (int) $data['resync_batch_size'] : ( $existing['resync_batch_size'] ?? 100 ),
            'resync_lock_minutes' => isset( $data['resync_lock_minutes'] ) ? (int) $data['resync_lock_minutes'] : ( $existing['resync_lock_minutes'] ?? 30 ),
            'resync_run_hour' => isset( $data['resync_run_hour'] ) ? (int) $data['resync_run_hour'] : ( $existing['resync_run_hour'] ?? 3 ),
            'resync_disable_non_active' => $bool_from_post( $data, 'resync_disable_non_active', $existing['resync_disable_non_active'] ?? 1 ),
            'free_level_id' => isset( $data['free_level_id'] ) ? sanitize_text_field( $data['free_level_id'] ) : ( $existing['free_level_id'] ?? '' ),
            'enable_node_poll_sync' => $bool_from_post( $data, 'enable_node_poll_sync', $existing['enable_node_poll_sync'] ?? 0 ),
            'node_poll_interval_minutes' => isset( $data['node_poll_interval_minutes'] ) ? (int) $data['node_poll_interval_minutes'] : ( $existing['node_poll_interval_minutes'] ?? 10 ),
            'node_poll_per_page' => isset( $data['node_poll_per_page'] ) ? (int) $data['node_poll_per_page'] : ( $existing['node_poll_per_page'] ?? 200 ),
            'node_poll_delete_stale' => $bool_from_post( $data, 'node_poll_delete_stale', $existing['node_poll_delete_stale'] ?? 1 ),
            'node_poll_lock_minutes' => isset( $data['node_poll_lock_minutes'] ) ? (int) $data['node_poll_lock_minutes'] : ( $existing['node_poll_lock_minutes'] ?? 10 ),
            'enable_purge_worker' => $bool_from_post( $data, 'enable_purge_worker', $existing['enable_purge_worker'] ?? 1 ),
            'purge_lock_minutes'  => isset( $data['purge_lock_minutes'] ) ? (int) $data['purge_lock_minutes'] : ( $existing['purge_lock_minutes'] ?? 10 ),
            'purge_lease_minutes' => isset( $data['purge_lease_minutes'] ) ? (int) $data['purge_lease_minutes'] : ( $existing['purge_lease_minutes'] ?? 15 ),
            'purge_batch_size'    => isset( $data['purge_batch_size'] ) ? (int) $data['purge_batch_size'] : ( $existing['purge_batch_size'] ?? 20 ),
            'alert_emails'        => isset( $data['alert_emails'] ) ? sanitize_textarea_field( $data['alert_emails'] ) : ( $existing['alert_emails'] ?? '' ),
            'telegram_bot_token'  => isset( $data['telegram_bot_token'] ) ? preg_replace( '/\s+/', '', sanitize_text_field( $data['telegram_bot_token'] ) ) : ( $existing['telegram_bot_token'] ?? '' ),
            'telegram_chat_ids'   => isset( $data['telegram_chat_ids'] ) ? sanitize_textarea_field( $data['telegram_chat_ids'] ) : ( $existing['telegram_chat_ids'] ?? '' ),
            'alert_template'      => isset( $data['alert_template'] ) ? wp_kses_post( $data['alert_template'] ) : ( $existing['alert_template'] ?? '' ),
            'recovery_template'   => isset( $data['recovery_template'] ) ? wp_kses_post( $data['recovery_template'] ) : ( $existing['recovery_template'] ?? '' ),
            'alert_threshold'     => isset( $data['alert_threshold'] ) ? (int) $data['alert_threshold'] : ( $existing['alert_threshold'] ?? 3 ),
            'alert_cooldown_minutes' => isset( $data['alert_cooldown_minutes'] ) ? (int) $data['alert_cooldown_minutes'] : ( $existing['alert_cooldown_minutes'] ?? 60 ),
            'enable_alerts_purge_worker'   => $bool_from_post( $data, 'enable_alerts_purge_worker', $existing['enable_alerts_purge_worker'] ?? 0 ),
            'enable_recovery_purge_worker' => $bool_from_post( $data, 'enable_recovery_purge_worker', $existing['enable_recovery_purge_worker'] ?? 0 ),
            'enable_alerts_provision_worker'   => $bool_from_post( $data, 'enable_alerts_provision_worker', $existing['enable_alerts_provision_worker'] ?? 0 ),
            'enable_recovery_provision_worker' => $bool_from_post( $data, 'enable_recovery_provision_worker', $existing['enable_recovery_provision_worker'] ?? 0 ),
            'enable_alerts_node_poll'      => $bool_from_post( $data, 'enable_alerts_node_poll', $existing['enable_alerts_node_poll'] ?? 0 ),
            'enable_recovery_node_poll'    => $bool_from_post( $data, 'enable_recovery_node_poll', $existing['enable_recovery_node_poll'] ?? 0 ),
            'enable_alerts_resync'         => $bool_from_post( $data, 'enable_alerts_resync', $existing['enable_alerts_resync'] ?? 0 ),
            'enable_recovery_resync'       => $bool_from_post( $data, 'enable_recovery_resync', $existing['enable_recovery_resync'] ?? 0 ),
            'enable_cron_debug_purge_worker' => $bool_from_post( $data, 'enable_cron_debug_purge_worker', $existing['enable_cron_debug_purge_worker'] ?? 0 ),
            'enable_cron_debug_provision_worker' => $bool_from_post( $data, 'enable_cron_debug_provision_worker', $existing['enable_cron_debug_provision_worker'] ?? 0 ),
            'enable_cron_debug_node_poll'    => $bool_from_post( $data, 'enable_cron_debug_node_poll', $existing['enable_cron_debug_node_poll'] ?? 0 ),
            'enable_cron_debug_resync'       => $bool_from_post( $data, 'enable_cron_debug_resync', $existing['enable_cron_debug_resync'] ?? 0 ),
        ];

        $settings_access_data = isset( $data['settings_access'] ) && is_array( $data['settings_access'] ) ? $data['settings_access'] : [];
        $settings_access_existing = isset( $existing['settings_access'] ) && is_array( $existing['settings_access'] ) ? $existing['settings_access'] : [];
        $settings_access_enabled_value = $settings_access_data['enabled'] ?? ( $settings_access_existing['enabled'] ?? 0 );
        $settings_access_enabled_value = is_array( $settings_access_enabled_value ) ? end( $settings_access_enabled_value ) : $settings_access_enabled_value;
        $settings_access_enabled = (int) ( '1' === (string) $settings_access_enabled_value );
        $settings_access_roles = $settings_access_data['allowed_roles'] ?? ( $settings_access_existing['allowed_roles'] ?? [] );
        $settings_access_roles = $this->normalize_role_keys( $settings_access_roles );
        $clean['settings_access'] = [
            'enabled'       => $settings_access_enabled,
            'allowed_roles' => $settings_access_roles,
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

        $plan_slug_meta        = isset( $data['dsb_plan_slug_meta'] ) && is_array( $data['dsb_plan_slug_meta'] ) ? $data['dsb_plan_slug_meta'] : [];
        $plan_products         = isset( $data['plan_products'] ) && is_array( $data['plan_products'] ) ? array_values( $data['plan_products'] ) : $this->get_plan_products();
        $plan_products         = array_filter( array_map( 'absint', $plan_products ) );
        $existing_product_plans = $this->get_product_plans();
        $existing_level_plans   = $this->get_level_plans();

        $style_defaults = $this->get_style_defaults();
        $optional_override_keys = [
            'style_header_plan_title_color',
            'style_header_plan_title_opacity',
            'style_header_eyebrow_color',
            'style_header_meta_color',
            'style_header_billing_color',
        ];
        foreach ( $style_defaults as $key => $default ) {
            if ( in_array( $key, $optional_override_keys, true ) && ! array_key_exists( $key, $data ) && ! array_key_exists( $key, $existing_raw ?? [] ) ) {
                continue;
            }
            $raw   = isset( $data[ $key ] ) ? $data[ $key ] : ( $existing[ $key ] ?? $default );
            $clean[ $key ] = $this->sanitize_style_value( $key, $raw, $default );
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

        $level_plans = $existing_level_plans;
        if ( isset( $data['level_plans'] ) && is_array( $data['level_plans'] ) ) {
            $level_plans = [];
            foreach ( $data['level_plans'] as $level_id => $plan_slug ) {
                $lid = absint( $level_id );
                if ( $lid <= 0 ) {
                    continue;
                }
                $normalized = dsb_normalize_plan_slug( sanitize_text_field( $plan_slug ) );
                if ( '' !== $normalized ) {
                    $level_plans[ $lid ] = $normalized;
                }
            }
        }

        if ( function_exists( 'wc_get_product' ) ) {
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
        }

        update_option( self::OPTION_SETTINGS, $clean );
        update_option( self::OPTION_PRODUCT_PLANS, $plans );
        update_option( self::OPTION_PLAN_PRODUCTS, $plan_products );
        update_option( self::OPTION_LEVEL_PLANS, $level_plans );
        update_option( DSB_DB::OPTION_DELETE_ON_UNINSTALL, $clean['delete_data'] );
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

    public function masked_token(): string {
        $settings = $this->get_settings();
        $token    = $settings['bridge_token'];
        if ( ! $token ) {
            return __( 'Not set', 'pixlab-license-bridge' );
        }
        $len = strlen( $token );
        if ( $len <= 6 ) {
            return str_repeat( '*', $len );
        }
        return substr( $token, 0, 3 ) . str_repeat( '*', $len - 6 ) . substr( $token, -3 );
    }

    protected function sanitize_style_value( string $key, $value, string $default ): string {
        $value = is_array( $value ) ? end( $value ) : $value;
        $value = is_string( $value ) ? trim( $value ) : '';

        if ( '' === $value ) {
            return $default;
        }

        $color_keys = [
            'style_header_text',
            'style_header_plan_title_color',
            'style_header_eyebrow_color',
            'style_header_meta_color',
            'style_header_billing_color',
            'style_eyebrow_color',
            'style_card_header_color',
            'style_card_text_color',
            'style_card_label_color',
            'style_card_hint_color',
            'style_endpoint_eyebrow_color',
            'style_cards_bg',
            'style_cards_border',
            'style_cards_shadow_color',
            'style_cards_text',
            'style_dashboard_bg',
            'style_dashboard_shadow_color',
            'style_dashboard_border_color',
            'style_card_bg',
            'style_card_border',
            'style_card_shadow',
            'style_text_primary',
            'style_text_secondary',
            'style_text_muted',
            'style_button_bg',
            'style_button_text',
            'style_button_border',
            'style_button_hover_bg',
            'style_button_hover_border',
            'style_button_active_bg',
            'style_btn_primary_bg',
            'style_btn_primary_text',
            'style_btn_primary_border',
            'style_btn_primary_hover_bg',
            'style_btn_primary_hover_text',
            'style_btn_primary_hover_border',
            'style_btn_primary_normal_bg',
            'style_btn_primary_normal_border',
            'style_btn_primary_normal_text',
            'style_btn_primary_active_bg',
            'style_btn_primary_active_border',
            'style_btn_primary_active_text',
            'style_btn_primary_shadow_color',
            'style_btn_outline_bg',
            'style_btn_outline_text',
            'style_btn_outline_border',
            'style_btn_outline_hover_bg',
            'style_btn_outline_hover_text',
            'style_btn_outline_hover_border',
            'style_btn_outline_normal_bg',
            'style_btn_outline_normal_border',
            'style_btn_outline_normal_text',
            'style_btn_outline_active_bg',
            'style_btn_outline_active_border',
            'style_btn_outline_active_text',
            'style_btn_ghost_bg',
            'style_btn_ghost_text',
            'style_btn_ghost_border',
            'style_btn_ghost_hover_bg',
            'style_btn_ghost_hover_text',
            'style_btn_ghost_hover_border',
            'style_btn_ghost_normal_bg',
            'style_btn_ghost_normal_border',
            'style_btn_ghost_normal_text',
            'style_btn_ghost_active_bg',
            'style_btn_ghost_active_border',
            'style_btn_ghost_active_text',
            'style_input_bg',
            'style_input_text',
            'style_input_border',
            'style_input_focus_border',
            'style_badge_active_bg',
            'style_badge_active_border',
            'style_badge_active_text',
            'style_badge_disabled_bg',
            'style_badge_disabled_border',
            'style_badge_disabled_text',
            'style_status_active_bg',
            'style_status_active_border',
            'style_status_active_text',
            'style_status_disabled_bg',
            'style_status_disabled_border',
            'style_status_disabled_text',
            'style_progress_track',
            'style_progress_fill',
            'style_progress_fill_hover',
            'style_progress_track_border',
            'style_progress_text',
            'style_table_bg',
            'style_table_header_bg',
            'style_table_header_text',
            'style_table_border',
            'style_table_row_bg',
            'style_table_row_text',
            'style_table_row_border',
            'style_table_row_hover_bg',
            'style_table_empty_text_color',
            'style_table_body_text',
            'style_table_empty_text',
            'style_table_error_text',
            'style_status_success_text',
            'style_status_error_text',
            'style_overlay_color',
        ];

        $unit_px_keys = [
            'style_plan_title_size',
            'style_eyebrow_size',
            'style_card_header_size',
            'style_card_radius',
            'style_card_shadow_blur',
            'style_card_shadow_spread',
            'style_container_padding',
        ];

        $allow_negative_px = [
            'style_card_shadow_spread',
        ];

        $unit_em_keys = [ 'style_eyebrow_spacing' ];

        $weight_keys = [
            'style_plan_title_weight',
            'style_card_header_weight',
        ];

        $opacity_keys = [ 'style_header_plan_title_opacity' ];

        $shadow_strength_keys = [ 'style_btn_primary_shadow_strength' ];

        if ( in_array( $key, $color_keys, true ) ) {
            $lower = strtolower( $value );
            $keywords = [ 'transparent', 'inherit', 'initial' ];
            if ( in_array( $lower, $keywords, true ) ) {
                return $lower;
            }

            if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
                return $value;
            }

            if ( preg_match( '/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*(0|1|0?\.\d+))?\s*\)$/i', $value ) ) {
                return $value;
            }

            return $default;
        }

        if ( in_array( $key, $unit_px_keys, true ) ) {
            $number = is_numeric( $value ) ? (float) $value : (float) preg_replace( '/[^\d.\-]/', '', $value );
            if ( $number < 0 && ! in_array( $key, $allow_negative_px, true ) ) {
                $number = 0;
            }
            return $number . 'px';
        }

        if ( in_array( $key, $unit_em_keys, true ) ) {
            $number = is_numeric( $value ) ? (float) $value : (float) preg_replace( '/[^\d.\-]/', '', $value );
            if ( $number < 0 ) {
                $number = 0;
            }
            return $number . 'em';
        }

        if ( in_array( $key, $weight_keys, true ) ) {
            $weight = (int) $value;
            $allowed_weights = [ 300, 400, 500, 600, 700, 800 ];
            return in_array( $weight, $allowed_weights, true ) ? (string) $weight : $default;
        }

        if ( in_array( $key, $shadow_strength_keys, true ) ) {
            $number = is_numeric( $value ) ? (float) $value : (float) preg_replace( '/[^\d.\-]/', '', $value );
            $number = max( 0, min( 3, $number ) );
            return (string) $number;
        }

        if ( in_array( $key, $opacity_keys, true ) ) {
            $number = is_numeric( $value ) ? (float) $value : (float) preg_replace( '/[^\d.\-]/', '', $value );
            $number = max( 0, min( 1, $number ) );
            return (string) $number;
        }

        return sanitize_text_field( $value );
    }

    protected function request( string $path, string $method = 'GET', ?array $body = [], array $query = [] ) {
        $settings = $this->get_settings();
        $method   = strtoupper( $method );

        if ( empty( $settings['node_base_url'] ) ) {
            return new \WP_Error( 'dsb_missing_base', __( 'Node base URL missing', 'pixlab-license-bridge' ) );
        }

        $bridge_token = $this->get_bridge_token();
        if ( empty( $bridge_token ) ) {
            return new \WP_Error( 'dsb_missing_token', __( 'Bridge token missing', 'pixlab-license-bridge' ) );
        }

        $url = $this->build_url( $path, $query );
        if ( ! $this->is_node_url_allowed( $url ) ) {
            $masked = function_exists( 'dsb_mask_string' ) ? dsb_mask_string( $url ) : $url;
            dsb_log( 'error', 'Blocked node request to invalid host', [ 'url' => $masked ] );
            return new \WP_Error( 'dsb_invalid_node_url', __( 'Invalid Node URL', 'pixlab-license-bridge' ) );
        }

        $args = [
            'timeout' => 25,
            'headers' => [
                'x-davix-bridge-token' => $bridge_token,
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

    protected function is_node_url_allowed( string $url ): bool {
        $parts  = wp_parse_url( $url );
        $scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
        $host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

        if ( 'https' !== $scheme || '' === $host ) {
            return false;
        }

        $host = trim( $host, '[]' );
        if ( 'localhost' === $host || ( strlen( $host ) > 10 && '.localhost' === substr( $host, -10 ) ) ) {
            return false;
        }

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return ! $this->is_private_ip( $host );
        }

        $ips = $this->resolve_host_ips( $host );
        foreach ( $ips as $ip ) {
            if ( $this->is_private_ip( $ip ) ) {
                return false;
            }
        }

        return true;
    }

    protected function resolve_host_ips( string $host ): array {
        $ips = [];

        if ( function_exists( 'dns_get_record' ) ) {
            $records = dns_get_record( $host, DNS_A | DNS_AAAA );
            if ( is_array( $records ) ) {
                foreach ( $records as $record ) {
                    if ( isset( $record['ip'] ) ) {
                        $ips[] = $record['ip'];
                    }
                    if ( isset( $record['ipv6'] ) ) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if ( empty( $ips ) ) {
            $ipv4 = gethostbyname( $host );
            if ( $ipv4 && $ipv4 !== $host ) {
                $ips[] = $ipv4;
            }
        }

        return array_values( array_unique( array_filter( $ips ) ) );
    }

    protected function is_private_ip( string $ip ): bool {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        return ! filter_var( $ip, FILTER_VALIDATE_IP, $flags );
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
        $status_value = is_array( $decoded ) && isset( $decoded['status'] ) ? strtolower( (string) $decoded['status'] ) : '';
        $action_value = is_array( $decoded ) && isset( $decoded['action'] ) ? strtolower( (string) $decoded['action'] ) : '';
        $validation   = $this->validate_response( '/internal/subscription/event', $decoded, $payload );
        $success      = $code >= 200 && $code < 300 && ! is_wp_error( $response ) && ( $validation['ok'] ?? false );
        $validation_error = $validation['error'] ?? '';
        $strict_validation = [ 'ok' => true, 'error' => '', 'missing' => [], 'plan_slug' => $payload['plan_slug'] ?? '' ];

        $subscription_identifier = sanitize_text_field( $payload['subscription_id'] ?? '' );
        if ( ! $subscription_identifier && isset( $payload['order_id'] ) ) {
            $subscription_identifier = sanitize_text_field( (string) $payload['order_id'] );
        }

        if ( $success ) {
            $strict_validation = $this->strict_validate_mirror_fields( $payload, $decoded );
            if ( ! $strict_validation['ok'] ) {
                $success          = false;
                $validation_error = $strict_validation['error'];
            } elseif ( ! empty( $strict_validation['plan_slug'] ) ) {
                $payload['plan_slug'] = $strict_validation['plan_slug'];
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
            'error_excerpt'   => is_wp_error( $response )
                ? $response->get_error_message()
                : ( $success
                    ? ( is_array( $decoded ) ? ( $decoded['status'] ?? '' ) : '' )
                    : ( $validation_error ?: ( is_array( $decoded ) ? ( $decoded['status'] ?? '' ) : ( $body ? substr( $body, 0, 200 ) : '' ) ) ) ),
        ];
        $settings = $this->get_settings();
        if ( $settings['enable_logging'] ) {
            $this->db->log_event( $log );
        }

        if ( $code < 200 || $code >= 300 ) {
            $body_excerpt = $body ? substr( $body, 0, 500 ) : '';
            dsb_log(
                'debug',
                'Node send_event non-2xx response',
                [
                    'http_code'       => $code,
                    'response_body'   => $body_excerpt,
                    'event'           => $payload['event'] ?? '',
                    'subscription_id' => $payload['subscription_id'] ?? '',
                    'plan_slug'       => $payload['plan_slug'] ?? '',
                    'wp_user_id'      => isset( $payload['wp_user_id'] ) ? (int) $payload['wp_user_id'] : null,
                    'customer_email'  => $payload['customer_email'] ?? '',
                ]
            );
        }

        $event_name = isset( $payload['event'] ) ? strtolower( (string) $payload['event'] ) : '';
        $disable_like_events = [ 'cancelled', 'expired', 'disabled', 'payment_failed', 'payment-failed', 'paused' ];

        if ( ! $success && 'missing_api_key_id' === $validation_error ) {
            $response = new \WP_Error(
                'missing_api_key_id',
                __( 'PixLab response missing api_key_id for active events; provisioning will retry.', 'pixlab-license-bridge' )
            );
            dsb_log(
                'error',
                'PixLab response missing api_key_id for active events; provisioning will retry.',
                [
                    'event'           => $payload['event'] ?? '',
                    'subscription_id' => $subscription_identifier,
                    'wp_user_id'      => isset( $payload['wp_user_id'] ) ? absint( $payload['wp_user_id'] ) : null,
                    'customer_email'  => $payload['customer_email'] ?? '',
                ]
            );
        }

        if ( ! $success && $validation_error && ! empty( $strict_validation['missing'] ) ) {
            dsb_log(
                'error',
                'Node response missing required mirror fields; mirrors untouched',
                [
                    'event'           => $payload['event'] ?? '',
                    'subscription_id' => $subscription_identifier,
                    'wp_user_id'      => isset( $payload['wp_user_id'] ) ? absint( $payload['wp_user_id'] ) : null,
                    'customer_email'  => $payload['customer_email'] ?? '',
                    'missing_fields'  => $strict_validation['missing'],
                ]
            );
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

            $subscription_status = isset( $payload['subscription_status'] ) ? sanitize_text_field( $payload['subscription_status'] ) : null;
            $should_clear_fields = in_array( $event_name, [ 'expired', 'disabled' ], true )
                || ( 'disabled' === $key_status && in_array( $subscription_status, [ 'expired', 'disabled' ], true ) );
            $node_plan_id = null;
            if ( isset( $decoded['plan_id'] ) && is_numeric( $decoded['plan_id'] ) ) {
                $node_plan_id = absint( $decoded['plan_id'] );
            }

            // For expired/disabled, always clear plan/validity regardless of existing values.
            if ( $should_clear_fields ) {
                $payload['plan_slug']   = '';
                $payload['product_id']  = null;
                $valid_from             = null;
                $valid_until            = null;
                $key_status             = 'disabled';
                $subscription_status    = $subscription_status ?: 'expired';
            } elseif ( 'cancelled' === $event_name && $valid_until ) {
                $key_status = 'active';
            }

            $node_api_key_id = $this->extract_node_api_key_id_from_response( $decoded );
            $key_data = [
                    'subscription_id' => $subscription_identifier,
                    'customer_email'  => sanitize_email( $payload['customer_email'] ?? '' ),
                    'wp_user_id'      => isset( $payload['wp_user_id'] ) ? absint( $payload['wp_user_id'] ) : null,
                    'customer_name'   => isset( $payload['customer_name'] ) ? sanitize_text_field( $payload['customer_name'] ) : null,
                    'subscription_status' => $subscription_status,
                    'plan_slug'       => sanitize_text_field( $payload['plan_slug'] ?? '' ),
                    'status'          => $key_status,
                    'key_prefix'      => isset( $decoded['key'] ) && is_string( $decoded['key'] ) ? substr( $decoded['key'], 0, 10 ) : ( $decoded['key_prefix'] ?? null ),
                    'key_last4'       => isset( $decoded['key'] ) && is_string( $decoded['key'] ) ? substr( $decoded['key'], -4 ) : ( $decoded['key_last4'] ?? null ),
                    'valid_from'      => $valid_from,
                    'valid_until'     => $valid_until,
                    'node_plan_id'    => $node_plan_id,
                    'last_action'     => $decoded['action'] ?? null,
                    'last_http_code'  => $code,
                    'last_error'      => null,
            ];
            if ( $node_api_key_id > 0 ) {
                $key_data['node_api_key_id'] = $node_api_key_id;
            }

            $this->db->upsert_key( $key_data );

            if ( isset( $payload['event'] ) && ( in_array( $payload['event'], [ 'activated', 'renewed', 'reactivated', 'active' ], true ) || in_array( $event_name, $disable_like_events, true ) ) ) {
                $user_status = $should_clear_fields ? 'disabled' : ( 'cancelled' === $event_name ? 'active' : ( in_array( $event_name, $disable_like_events, true ) ? 'disabled' : 'active' ) );

                $user_data = [
                        'wp_user_id'      => isset( $payload['wp_user_id'] ) ? absint( $payload['wp_user_id'] ) : 0,
                        'customer_email'  => sanitize_email( $payload['customer_email'] ?? '' ),
                        'subscription_id' => $subscription_identifier,
                        'order_id'        => isset( $payload['order_id'] ) ? absint( $payload['order_id'] ) : null,
                        'product_id'      => $should_clear_fields ? null : ( isset( $payload['product_id'] ) ? absint( $payload['product_id'] ) : null ),
                        'plan_slug'       => $should_clear_fields ? null : sanitize_text_field( $payload['plan_slug'] ?? '' ),
                        'status'          => $user_status,
                        'valid_from'      => $should_clear_fields ? null : $valid_from,
                        'valid_until'     => $should_clear_fields ? null : $valid_until,
                        'source'          => 'subscription_event',
                        'last_sync_at'    => current_time( 'mysql', true ),
                ];
                if ( $node_api_key_id > 0 ) {
                    $user_data['node_api_key_id'] = $node_api_key_id;
                }

                $this->db->upsert_user( $user_data );

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
        } elseif ( $settings['enable_logging'] && $validation_error ) {
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
                dsb_log(
                    'error',
                    'Node response failed validation; mirrors untouched',
                    [
                        'subscription_id' => $subscription_identifier,
                        'error'           => $validation_error,
                        'http_code'       => $code,
                    ]
                );
            }
        }

        return [
            'response' => $response,
            'decoded'  => $decoded,
            'code'     => $code,
            'success'  => $success,
            'validation_error' => $validation_error,
        ];
    }

    protected function validate_response( string $endpoint, $decoded, array $payload = [] ): array {
        if ( '/internal/subscription/event' !== $endpoint ) {
            return [ 'ok' => is_array( $decoded ), 'error' => is_array( $decoded ) ? '' : 'invalid_json' ];
        }

        if ( ! is_array( $decoded ) ) {
            return [ 'ok' => false, 'error' => 'invalid_json' ];
        }

        $status = isset( $decoded['status'] ) ? strtolower( (string) $decoded['status'] ) : '';
        $allowed_statuses = [ 'ok', 'active', 'disabled' ];
        if ( ! in_array( $status, $allowed_statuses, true ) ) {
            return [ 'ok' => false, 'error' => 'unexpected_status' ];
        }

        $subscription_id = $decoded['subscription_id'] ?? null;
        $api_key_id = $decoded['api_key_id'] ?? ( $decoded['key']['api_key_id'] ?? ( $decoded['key']['id'] ?? null ) );
        if ( empty( $subscription_id ) && empty( $api_key_id ) ) {
            return [ 'ok' => false, 'error' => 'missing_identifiers' ];
        }

        $event_name = isset( $payload['event'] ) ? strtolower( (string) $payload['event'] ) : '';
        $needs_key  = in_array( $event_name, [ 'activated', 'active', 'renewed', 'reactivated' ], true );
        if ( $needs_key ) {
            $node_api_key_id = $this->extract_node_api_key_id_from_response( $decoded );
            if ( $node_api_key_id <= 0 ) {
                return [ 'ok' => false, 'error' => 'missing_api_key_id' ];
            }
            $key_value = $decoded['key'] ?? null;
            $key_prefix = $decoded['key_prefix'] ?? ( is_array( $decoded['key'] ?? null ) ? ( $decoded['key']['prefix'] ?? $decoded['key']['key_prefix'] ?? null ) : null );
            $key_last4  = $decoded['key_last4'] ?? ( is_array( $decoded['key'] ?? null ) ? ( $decoded['key']['last4'] ?? $decoded['key']['key_last4'] ?? null ) : null );

            $has_key = ( is_string( $key_value ) && $key_value ) || ( $key_prefix && $key_last4 );
            if ( ! $has_key ) {
                return [ 'ok' => false, 'error' => 'missing_key_material' ];
            }
        }

        return [ 'ok' => true, 'error' => '' ];
    }

    protected function extract_node_api_key_id_from_response( $decoded ): int {
        if ( ! is_array( $decoded ) ) {
            return 0;
        }

        $api_key_id = $decoded['api_key_id']
            ?? ( $decoded['key']['api_key_id'] ?? ( $decoded['key']['id'] ?? null ) );

        return $api_key_id ? absint( $api_key_id ) : 0;
    }

    protected function strict_validate_mirror_fields( array $payload, $decoded ): array {
        $event_name    = isset( $payload['event'] ) ? strtolower( (string) $payload['event'] ) : '';
        $active_events = [ 'activated', 'active', 'renewed', 'reactivated' ];
        if ( ! in_array( $event_name, $active_events, true ) ) {
            return [
                'ok'        => true,
                'error'     => '',
                'missing'   => [],
                'plan_slug' => $payload['plan_slug'] ?? '',
            ];
        }

        $missing = [];
        $email   = sanitize_email( $payload['customer_email'] ?? '' );
        if ( '' === $email ) {
            $missing[] = 'customer_email';
        }

        $wp_user_id = isset( $payload['wp_user_id'] ) ? absint( $payload['wp_user_id'] ) : 0;
        if ( $wp_user_id <= 0 ) {
            $missing[] = 'wp_user_id';
        }

        $subscription_id = isset( $payload['subscription_id'] ) ? sanitize_text_field( (string) $payload['subscription_id'] ) : '';
        if ( '' === trim( $subscription_id ) ) {
            $missing[] = 'subscription_id';
        }

        $plan_slug = $payload['plan_slug'] ?? '';
        if ( ! $plan_slug && is_array( $decoded ) ) {
            $plan_slug = $decoded['plan_slug'] ?? ( isset( $decoded['plan'] ) && is_array( $decoded['plan'] ) ? ( $decoded['plan']['plan_slug'] ?? '' ) : '' );
        }
        $plan_slug = dsb_normalize_plan_slug( $plan_slug );
        if ( '' === $plan_slug ) {
            $missing[] = 'plan_slug';
        }

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
        if ( ! $valid_from && ! $valid_until ) {
            $missing[] = 'validity';
        }

        return [
            'ok'        => empty( $missing ),
            'error'     => empty( $missing ) ? '' : 'missing_required_fields',
            'missing'   => $missing,
            'plan_slug' => $plan_slug,
            'valid_from'  => $valid_from,
            'valid_until' => $valid_until,
        ];
    }

    public function test_connection(): array {
        $legacy   = false;
        $endpoint = '/internal/ping';
        $response = $this->request( $endpoint, 'GET' );
        $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        $ping_code = $code;

        if ( 404 === $code ) {
            $legacy   = true;
            $endpoint = '/internal/subscription/debug';
            $response = $this->request( $endpoint, 'GET' );
            $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        }

        $decoded = ! is_wp_error( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;

        return [
            'response' => $response,
            'decoded'  => $decoded,
            'code'     => $code,
            'legacy'   => $legacy,
            'endpoint' => $endpoint,
            'ping_code' => $ping_code,
        ];
    }

    public function fetch_wps_subscriptions_all() {
        return new \WP_Error( 'dsb_wps_deprecated', __( 'WPS integration removed in favor of Paid Memberships Pro.', 'pixlab-license-bridge' ) );
    }

    public function fetch_pmpro_memberships_all() {
        global $wpdb;

        if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) && ! class_exists( '\\MemberOrder' ) ) {
            return new \WP_Error( 'dsb_pmpro_missing', __( 'Paid Memberships Pro is not active.', 'pixlab-license-bridge' ) );
        }

        $table = $wpdb->prefix . 'pmpro_memberships_users';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

        if ( ! $exists ) {
            return new \WP_Error( 'dsb_pmpro_table_missing', __( 'PMPro membership table missing.', 'pixlab-license-bridge' ) );
        }

        $has_status_column = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'status' ) );
        $order_sql         = "ORDER BY user_id ASC, startdate DESC, id DESC";
        $query             = "SELECT * FROM {$table} {$order_sql}";

        $rows    = $wpdb->get_results( $query, ARRAY_A );
        $members = [];
        $now_ts  = time();

        foreach ( $rows as $row ) {
            $user_id = absint( $row['user_id'] ?? 0 );
            $level_id = absint( $row['membership_id'] ?? 0 );
            if ( $user_id <= 0 || $level_id <= 0 ) {
                continue;
            }

            $status = strtolower( (string) ( $row['status'] ?? '' ) );
            $status = $has_status_column ? $status : ( $status ?: 'active' );

            // Only consider active rows.
            if ( 'active' !== $status ) {
                continue;
            }

            $end    = $row['enddate'] ?? null;
            $end_ts = null;

            if ( is_numeric( $end ) && (int) $end > 0 ) {
                $end_ts = (int) $end;
            } elseif ( is_string( $end ) && '' !== $end ) {
                try {
                    $dt     = new \DateTimeImmutable( $end );
                    $end_ts = $dt->getTimestamp();
                } catch ( \Throwable $e ) {
                    $end_ts = null;
                }
            }

            $is_lifetime  = null === $end_ts || $end_ts <= 0;
            $is_time_valid = $is_lifetime || ( $end_ts > $now_ts );

            if ( ! $is_time_valid ) {
                continue;
            }

            if ( isset( $members[ $user_id ] ) ) {
                // First active+valid row per user (ordered by startdate DESC, id DESC).
                continue;
            }

            $user  = get_userdata( $user_id );
            $email = $user instanceof \WP_User ? $user->user_email : '';

            dsb_log(
                'debug',
                'PMPro resync include membership',
                [
                    'user_id'        => $user_id,
                    'membership_id'  => $level_id,
                    'status'         => $status,
                    'is_lifetime'    => $is_lifetime,
                    'valid_from'     => $row['startdate'] ?? null,
                    'valid_until_ts' => $is_lifetime ? null : $end_ts,
                ]
            );

            $members[ $user_id ] = [
                'user_id'    => $user_id,
                'email'      => $email ? sanitize_email( $email ) : '',
                'level_id'   => $level_id,
                'status'     => $status,
                'startdate'  => $row['startdate'] ?? null,
                'enddate'    => $end,
                'end_ts'     => $end_ts,
            ];
        }

        return array_values( $members );
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

        $attempts  = 0;
        $response  = null;
        $last_args = [
            'page'     => $page,
            'per_page' => $per_page,
        ];

        while ( $attempts < 2 ) {
            $attempts ++;
            $response = $this->request( '/internal/admin/keys/export', 'GET', null, $last_args );

            if ( ! is_wp_error( $response ) ) {
                break;
            }

            // Retry once on transport errors such as cURL 28 (DNS/timeout).
            sleep( 1 );
        }

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

    public function lookup_api_key_id_for_identity( array $payload ): array {
        $response = $this->post_internal( '/internal/user/lookup-key-id', $payload );
        $decoded  = $response['decoded'] ?? null;
        $response['api_key_id'] = $this->extract_node_api_key_id_from_response( $decoded );
        return $response;
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
