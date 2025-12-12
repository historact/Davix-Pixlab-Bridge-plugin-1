<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Shortcode {
    protected $client;

    public function __construct( DSB_Client $client ) {
        $this->client = $client;
    }

    public function init(): void {
        add_shortcode( 'davix_api_dashboard', [ $this, 'render_dashboard' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
        add_action( 'wp_ajax_dsb_user_summary', [ $this, 'ajax_user_summary' ] );
        add_action( 'wp_ajax_dsb_user_rotate_key', [ $this, 'ajax_rotate_key' ] );
    }

    public function maybe_enqueue_assets(): void {
        if ( ! is_singular() ) {
            return;
        }

        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'davix_api_dashboard' ) ) {
            return;
        }

        wp_register_script(
            'dsb-user-dashboard',
            DSB_PLUGIN_URL . 'assets/js/dsb-user-dashboard.js',
            [ 'jquery' ],
            DSB_VERSION,
            true
        );

        wp_localize_script(
            'dsb-user-dashboard',
            'dsbUserDashboard',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'dsb_user_dashboard' ),
                'strings' => [
                    'loading'  => __( 'Loading usage…', 'davix-sub-bridge' ),
                    'error'    => __( 'Unable to load usage right now.', 'davix-sub-bridge' ),
                    'copynote' => __( 'Copy it now — it will be hidden soon.', 'davix-sub-bridge' ),
                ],
            ]
        );

        wp_enqueue_script( 'dsb-user-dashboard' );
    }

    public function render_dashboard(): string {
        if ( ! is_user_logged_in() ) {
            return '<div class="dsb-dashboard-message">' . esc_html__( 'Please log in to view your API usage.', 'davix-sub-bridge' ) . '</div>';
        }

        ob_start();
        ?>
        <div class="dsb-user-dashboard" data-nonce="<?php echo esc_attr( wp_create_nonce( 'dsb_user_dashboard' ) ); ?>">
            <div class="dsb-user-dashboard__status" role="status"></div>
            <div class="dsb-user-dashboard__card" aria-live="polite">
                <h3><?php esc_html_e( 'Davix API Usage', 'davix-sub-bridge' ); ?></h3>
                <div class="dsb-user-dashboard__row">
                    <strong><?php esc_html_e( 'API Key', 'davix-sub-bridge' ); ?>:</strong>
                    <span class="dsb-user-dashboard__key" data-key-display><?php esc_html_e( 'Loading…', 'davix-sub-bridge' ); ?></span>
                </div>
                <div class="dsb-user-dashboard__row" data-plan-row>
                    <strong><?php esc_html_e( 'Plan', 'davix-sub-bridge' ); ?>:</strong>
                    <span class="dsb-user-dashboard__plan" data-plan-display><?php esc_html_e( 'Loading…', 'davix-sub-bridge' ); ?></span>
                </div>
                <div class="dsb-user-dashboard__row" data-usage-row>
                    <strong><?php esc_html_e( 'Monthly usage', 'davix-sub-bridge' ); ?>:</strong>
                    <span class="dsb-user-dashboard__usage" data-usage-display><?php esc_html_e( 'Loading…', 'davix-sub-bridge' ); ?></span>
                </div>
                <div class="dsb-user-dashboard__row" data-endpoint-usage>
                    <strong><?php esc_html_e( 'Per-endpoint usage', 'davix-sub-bridge' ); ?>:</strong>
                    <div class="dsb-user-dashboard__endpoints" data-endpoint-display></div>
                </div>
                <button type="button" class="dsb-user-dashboard__rotate button" data-rotate><?php esc_html_e( 'Regenerate Key', 'davix-sub-bridge' ); ?></button>
                <div class="dsb-user-dashboard__new-key" data-new-key hidden>
                    <p><strong><?php esc_html_e( 'New API Key:', 'davix-sub-bridge' ); ?></strong> <span data-new-key-value></span></p>
                    <p class="description"><?php esc_html_e( 'Copy it now — it will be hidden in 60 seconds.', 'davix-sub-bridge' ); ?></p>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function ajax_user_summary(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Authentication required.', 'davix-sub-bridge' ) ], 401 );
        }

        $nonce = isset( $_POST['_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'dsb_user_dashboard' ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid request.', 'davix-sub-bridge' ) ], 403 );
        }

        $user    = wp_get_current_user();
        $payload = $this->build_user_payload( $user );

        $result = $this->client->fetch_user_summary( $payload );
        if ( is_wp_error( $result['response'] ) ) {
            wp_send_json_error(
                [
                    'message' => $result['response']->get_error_message(),
                ],
                500
            );
        }

        if ( 200 !== $result['code'] || ! isset( $result['decoded']['status'] ) || 'ok' !== $result['decoded']['status'] ) {
            wp_send_json_error(
                [
                    'message' => __( 'Unable to fetch usage.', 'davix-sub-bridge' ),
                    'code'    => $result['code'],
                ],
                500
            );
        }

        wp_send_json_success( [ 'data' => $result['decoded'] ] );
    }

    public function ajax_rotate_key(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Authentication required.', 'davix-sub-bridge' ) ], 401 );
        }

        $nonce = isset( $_POST['_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'dsb_user_dashboard' ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid request.', 'davix-sub-bridge' ) ], 403 );
        }

        $user = wp_get_current_user();
        $now  = time();
        $key  = '_dsb_last_key_rotation';
        $last = (int) get_user_meta( $user->ID, $key, true );
        if ( $last && ( $now - $last ) < 60 ) {
            wp_send_json_error( [ 'message' => __( 'Please wait before rotating again.', 'davix-sub-bridge' ) ], 429 );
        }

        $payload = $this->build_user_payload( $user );
        $result  = $this->client->rotate_user_key( $payload );
        if ( is_wp_error( $result['response'] ) ) {
            wp_send_json_error(
                [ 'message' => $result['response']->get_error_message() ],
                500
            );
        }

        if ( 200 !== $result['code'] || ! isset( $result['decoded']['status'] ) || 'ok' !== $result['decoded']['status'] ) {
            wp_send_json_error(
                [
                    'message' => __( 'Unable to rotate key.', 'davix-sub-bridge' ),
                    'code'    => $result['code'],
                ],
                500
            );
        }

        update_user_meta( $user->ID, $key, $now );

        wp_send_json_success( [ 'data' => $result['decoded'] ] );
    }

    protected function build_user_payload( \WP_User $user ): array {
        $payload = [
            'customer_email' => sanitize_email( $user->user_email ),
        ];

        $subscription_id = $this->find_subscription_id( $user );
        if ( $subscription_id ) {
            $payload['subscription_id'] = $subscription_id;
        }

        return $payload;
    }

    protected function find_subscription_id( \WP_User $user ) {
        $post_types = [ 'shop_subscription', 'wps_sfw_subscription', 'wps_subscriptions', 'subscription' ];

        $query_args = [
            'post_type'      => $post_types,
            'post_status'    => 'any',
            'numberposts'    => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'   => '_customer_user',
                    'value' => (int) $user->ID,
                ],
                [
                    'key'   => '_billing_email',
                    'value' => sanitize_email( $user->user_email ),
                ],
            ],
        ];

        $subscription = get_posts( $query_args );
        if ( ! empty( $subscription ) ) {
            return (string) $subscription[0]->ID;
        }

        $by_author = get_posts(
            [
                'post_type'   => $post_types,
                'post_status' => 'any',
                'numberposts' => 1,
                'author'      => $user->ID,
                'orderby'     => 'date',
                'order'       => 'DESC',
            ]
        );

        if ( ! empty( $by_author ) ) {
            return (string) $by_author[0]->ID;
        }

        return null;
    }
}

