<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

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
            ] );
            $this->add_notice( __( 'Plan mappings saved.', 'davix-sub-bridge' ) );
        }

        if ( 'keys' === $tab ) {
            $this->handle_key_actions();
        }
    }

    protected function handle_key_actions(): void {
        if ( isset( $_POST['dsb_manual_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_manual_nonce'] ) ), 'dsb_manual_key' ) ) {
            $email          = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
            $plan_slug      = isset( $_POST['plan_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_slug'] ) ) : '';
            $subscriptionId = isset( $_POST['subscription_id'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription_id'] ) ) : '';
            $order_id       = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
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
            </table>
            <?php submit_button(); ?>
        </form>

        <form method="post" style="margin-top:20px;">
            <?php wp_nonce_field( 'dsb_test_connection' ); ?>
            <?php submit_button( __( 'Test Connection', 'davix-sub-bridge' ), 'secondary', 'dsb_test_connection', false ); ?>
        </form>
        <?php
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
                <tr><th><?php esc_html_e( 'Customer Email', 'davix-sub-bridge' ); ?></th><td><input type="email" name="customer_email" required /></td></tr>
                <tr><th><?php esc_html_e( 'Plan Slug', 'davix-sub-bridge' ); ?></th><td><input type="text" name="plan_slug" required /></td></tr>
                <tr><th><?php esc_html_e( 'Subscription ID', 'davix-sub-bridge' ); ?></th><td><input type="text" name="subscription_id" required /></td></tr>
                <tr><th><?php esc_html_e( 'Order ID (optional)', 'davix-sub-bridge' ); ?></th><td><input type="text" name="order_id" /></td></tr>
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
}
