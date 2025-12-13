<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Dashboard {
    protected $client;
    protected static $enqueued = false;

    public function __construct( DSB_Client $client ) {
        $this->client = $client;
    }

    public function init(): void {
        add_shortcode( 'PIXLAB_DASHBOARD', [ $this, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
    }

    public function render(): string {
        if ( ! is_user_logged_in() ) {
            return '<div class="dsb-dashboard__message">' . esc_html__( 'Please log in to view your API usage.', 'davix-sub-bridge' ) . '</div>';
        }

        $this->enqueue_assets();

        ob_start();
        ?>
        <div class="dsb-dashboard">
            <div class="dsb-dashboard__header">
                <div>
                    <p class="dsb-dashboard__eyebrow"><?php esc_html_e( 'Current Plan', 'davix-sub-bridge' ); ?></p>
                    <h2 class="dsb-dashboard__plan-name" data-plan-name><?php esc_html_e( 'Loading…', 'davix-sub-bridge' ); ?></h2>
                    <p class="dsb-dashboard__plan-meta" data-plan-limit></p>
                    <p class="dsb-dashboard__billing" data-billing-window></p>
                </div>
            </div>

            <div class="dsb-dashboard__grid">
                <div class="dsb-card dsb-card--key">
                    <div class="dsb-card__header">
                        <h3><?php esc_html_e( 'API Key', 'davix-sub-bridge' ); ?></h3>
                        <span class="dsb-status" data-key-status></span>
                    </div>
                    <div class="dsb-card__row">
                        <label><?php esc_html_e( 'Key', 'davix-sub-bridge' ); ?></label>
                        <div class="dsb-card__input-row">
                            <input type="text" readonly class="dsb-card__input" data-key-display value="" />
                        </div>
                        <p class="dsb-card__hint" data-key-created></p>
                    </div>
                    <div class="dsb-card__actions">
                        <button type="button" class="dsb-button dsb-button--outline" data-toggle-key></button>
                        <button type="button" class="dsb-button" data-rotate-key><?php esc_html_e( 'Regenerate Key', 'davix-sub-bridge' ); ?></button>
                    </div>
                </div>

                <div class="dsb-card dsb-card--usage">
                    <div class="dsb-card__header">
                        <h3><?php esc_html_e( 'Usage this period', 'davix-sub-bridge' ); ?></h3>
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
                    <p class="dsb-card__eyebrow">H2I</p>
                    <h4 data-endpoint-h2i>—</h4>
                </div>
                <div class="dsb-card dsb-card--endpoint">
                    <p class="dsb-card__eyebrow">Image</p>
                    <h4 data-endpoint-image>—</h4>
                </div>
                <div class="dsb-card dsb-card--endpoint">
                    <p class="dsb-card__eyebrow">PDF</p>
                    <h4 data-endpoint-pdf>—</h4>
                </div>
                <div class="dsb-card dsb-card--endpoint">
                    <p class="dsb-card__eyebrow">Tools</p>
                    <h4 data-endpoint-tools>—</h4>
                </div>
            </div>

            <div class="dsb-card dsb-card--history">
                <div class="dsb-card__header">
                    <h3><?php esc_html_e( 'History', 'davix-sub-bridge' ); ?></h3>
                </div>
                <div class="dsb-table" data-log-container>
                    <div class="dsb-table__loading" data-log-loading><?php esc_html_e( 'Loading…', 'davix-sub-bridge' ); ?></div>
                    <div class="dsb-table__empty" data-log-empty style="display:none;">&mdash; <?php esc_html_e( 'No requests yet.', 'davix-sub-bridge' ); ?> &mdash;</div>
                    <div class="dsb-table__scroller">
                        <table>
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Date/Time', 'davix-sub-bridge' ); ?></th>
                                    <th><?php esc_html_e( 'Endpoint', 'davix-sub-bridge' ); ?></th>
                                    <th><?php esc_html_e( 'Status', 'davix-sub-bridge' ); ?></th>
                                    <th><?php esc_html_e( 'Files', 'davix-sub-bridge' ); ?></th>
                                    <th><?php esc_html_e( 'Bytes In', 'davix-sub-bridge' ); ?></th>
                                    <th><?php esc_html_e( 'Bytes Out', 'davix-sub-bridge' ); ?></th>
                                    <th><?php esc_html_e( 'Error', 'davix-sub-bridge' ); ?></th>
                                </tr>
                            </thead>
                            <tbody data-log-rows></tbody>
                        </table>
                    </div>
                    <div class="dsb-pagination" data-log-pagination style="display:none;">
                        <button type="button" class="dsb-button dsb-button--ghost" data-log-prev><?php esc_html_e( 'Previous', 'davix-sub-bridge' ); ?></button>
                        <span data-log-page></span>
                        <button type="button" class="dsb-button dsb-button--ghost" data-log-next><?php esc_html_e( 'Next', 'davix-sub-bridge' ); ?></button>
                    </div>
                </div>
            </div>

            <div class="dsb-modal" data-modal aria-hidden="true">
                <div class="dsb-modal__overlay" data-modal-overlay></div>
                <div class="dsb-modal__content" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Your new API key', 'davix-sub-bridge' ); ?>">
                    <h3><?php esc_html_e( 'Your new API key', 'davix-sub-bridge' ); ?></h3>
                    <p class="dsb-modal__message" data-modal-message></p>
                    <div class="dsb-card__input-row">
                        <input type="text" readonly class="dsb-card__input" data-modal-key value="" />
                        <button type="button" class="dsb-button dsb-button--ghost" data-modal-copy>📋</button>
                    </div>
                    <p class="dsb-card__hint"><?php esc_html_e( 'Shown once — copy it now.', 'davix-sub-bridge' ); ?></p>
                    <div class="dsb-card__actions">
                        <button type="button" class="dsb-button" data-modal-close><?php esc_html_e( 'Close', 'davix-sub-bridge' ); ?></button>
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

        wp_register_style(
            'dsb-dashboard',
            DSB_PLUGIN_URL . 'assets/css/dsb-dashboard.css',
            [],
            DSB_VERSION
        );

        wp_register_script(
            'dsb-dashboard',
            DSB_PLUGIN_URL . 'assets/js/dsb-dashboard.js',
            [],
            DSB_VERSION,
            true
        );

        wp_localize_script(
            'dsb-dashboard',
            'dsbDashboardData',
            [
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'nonce'        => wp_create_nonce( 'dsb_dashboard_nonce' ),
                'isAdmin'      => current_user_can( 'manage_options' ),
                'strings'      => [
                    'loading'       => __( 'Loading…', 'davix-sub-bridge' ),
                    'error'         => __( 'Unable to load dashboard right now.', 'davix-sub-bridge' ),
                    'copied'        => __( 'Copied to clipboard.', 'davix-sub-bridge' ),
                    'copyFailed'    => __( 'Copy failed.', 'davix-sub-bridge' ),
                    'confirmRotate' => __( 'Are you sure you want to regenerate your API key?', 'davix-sub-bridge' ),
                    'rotateError'   => __( 'Unable to regenerate key.', 'davix-sub-bridge' ),
                    'shownOnce'     => __( 'Shown once — copy it now.', 'davix-sub-bridge' ),
                    'toggleOn'      => __( 'Enable Key', 'davix-sub-bridge' ),
                    'toggleOff'     => __( 'Disable Key', 'davix-sub-bridge' ),
                    'toggleError'   => __( 'Unable to update key.', 'davix-sub-bridge' ),
                    'toastSuccess'  => __( 'Updated', 'davix-sub-bridge' ),
                ],
            ]
        );

        wp_enqueue_style( 'dsb-dashboard' );
        wp_enqueue_script( 'dsb-dashboard' );
        self::$enqueued = true;
    }
}
