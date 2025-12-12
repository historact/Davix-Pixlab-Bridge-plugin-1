<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Admin {
    protected $client;
    protected $db;
    protected $events;

    public function __construct( DSB_Client $client, DSB_DB $db, DSB_Events $events ) {
        $this->client = $client;
        $this->db     = $db;
        $this->events = $events;
    }

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
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

    public function register_settings(): void {
        register_setting( 'davix-bridge', DSB_Client::OPTION_SETTINGS );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
        echo '<div class="wrap"><h1>Davix Subscription Bridge</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        $tabs = [ 'settings' => __( 'Settings', 'davix-sub-bridge' ), 'keys' => __( 'Keys', 'davix-sub-bridge' ), 'logs' => __( 'Logs', 'davix-sub-bridge' ) ];
        foreach ( $tabs as $key => $label ) {
            $class = $tab === $key ? 'nav-tab nav-tab-active' : 'nav-tab';
            printf( '<a href="%s" class="%s">%s</a>', esc_url( add_query_arg( [ 'page' => 'davix-bridge', 'tab' => $key ], admin_url( 'admin.php' ) ) ), esc_attr( $class ), esc_html( $label ) );
        }
        echo '</h2>';

        if ( isset( $_POST['dsb_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_settings_nonce'] ) ), 'dsb_save_settings' ) ) {
            $this->client->save_settings( $_POST );
            echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'davix-sub-bridge' ) . '</p></div>';
        }

        if ( 'settings' === $tab ) {
            $this->render_settings_tab();
        } elseif ( 'keys' === $tab ) {
            $this->handle_key_actions();
            $this->render_keys_tab();
        } else {
            $this->render_logs_tab();
        }

        echo '</div>';
    }

    protected function render_settings_tab(): void {
        $settings = $this->client->get_settings();

        if ( isset( $_POST['dsb_test_connection'] ) && check_admin_referer( 'dsb_test_connection' ) ) {
            $result = $this->client->test_connection();
            if ( isset( $result['error'] ) ) {
                echo '<div class="error"><p>' . esc_html( $result['error'] ) . '</p></div>';
            } elseif ( is_wp_error( $result['response'] ) ) {
                echo '<div class="error"><p>' . esc_html( $result['response']->get_error_message() ) . '</p></div>';
            } else {
                echo '<div class="updated"><p>' . esc_html__( 'Connection OK', 'davix-sub-bridge' ) . '</p>';
                if ( ! empty( $result['decoded']['debug'] ) ) {
                    echo '<pre>' . esc_html( wp_json_encode( $result['decoded']['debug'], JSON_PRETTY_PRINT ) ) . '</pre>';
                }
                echo '</div>';
            }
        }

        if ( isset( $_POST['dsb_resync'] ) && check_admin_referer( 'dsb_resync' ) ) {
            $this->events->resync_active_subscriptions();
            echo '<div class="updated"><p>' . esc_html__( 'Resync queued.', 'davix-sub-bridge' ) . '</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field( 'dsb_save_settings', 'dsb_settings_nonce' );
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__( 'Node Base URL', 'davix-sub-bridge' ) . '</th><td><input type="url" name="node_base_url" value="' . esc_attr( $settings['node_base_url'] ) . '" class="regular-text" /></td></tr>';
        echo '<tr><th>' . esc_html__( 'Bridge Token', 'davix-sub-bridge' ) . '</th><td><input type="password" name="bridge_token" value="' . esc_attr( $settings['bridge_token'] ) . '" class="regular-text" /><p class="description">' . esc_html__( 'Stored securely; masked in UI.', 'davix-sub-bridge' ) . ' ' . esc_html( $this->client->masked_token() ) . '</p></td></tr>';
        echo '<tr><th>' . esc_html__( 'Plan mapping mode', 'davix-sub-bridge' ) . '</th><td>';
        echo '<label><input type="radio" name="plan_mode" value="product"' . checked( $settings['plan_mode'], 'product', false ) . '/> ' . esc_html__( 'Map WooCommerce product to plan slug', 'davix-sub-bridge' ) . '</label><br />';
        echo '<label><input type="radio" name="plan_mode" value="plan_meta"' . checked( $settings['plan_mode'], 'plan_meta', false ) . '/> ' . esc_html__( 'Use subscription product plan meta', 'davix-sub-bridge' ) . '</label>';
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__( 'Delete data on uninstall', 'davix-sub-bridge' ) . '</th><td><label><input type="checkbox" name="delete_data" value="1"' . checked( $settings['delete_data'], 1, false ) . ' /> ' . esc_html__( 'Drop plugin tables on uninstall', 'davix-sub-bridge' ) . '</label></td></tr>';
        echo '</table>';
        submit_button();
        echo '</form>';

        echo '<form method="post" style="margin-top:20px;">';
        wp_nonce_field( 'dsb_test_connection' );
        submit_button( __( 'Test Connection', 'davix-sub-bridge' ), 'secondary', 'dsb_test_connection' );
        echo '</form>';

        echo '<form method="post" style="margin-top:20px;">';
        wp_nonce_field( 'dsb_resync' );
        submit_button( __( 'Run Full Resync', 'davix-sub-bridge' ), 'secondary', 'dsb_resync' );
        echo '</form>';
    }

    protected function handle_key_actions(): void {
        if ( empty( $_GET['dsb_action'] ) || empty( $_GET['subscription_id'] ) || ! isset( $_GET['_wpnonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'dsb_keys_action' ) ) {
            return;
        }
        $action          = sanitize_key( wp_unslash( $_GET['dsb_action'] ) );
        $subscription_id = sanitize_text_field( wp_unslash( $_GET['subscription_id'] ) );
        $payload         = $this->events->build_payload( $subscription_id, 'activated' );
        if ( ! $payload ) {
            return;
        }
        if ( 'deactivate' === $action ) {
            $payload['event'] = 'disabled';
        } elseif ( 'regenerate' === $action ) {
            $payload['event'] = 'activated';
            $payload['regenerate'] = true;
        } else {
            $payload['event'] = 'activated';
        }
        $this->client->send_event( $payload );
    }

    protected function render_keys_tab(): void {
        $table = new DSB_Keys_Table( $this->db, $this->client );
        $table->prepare_items();

        echo '<h2>' . esc_html__( 'Keys', 'davix-sub-bridge' ) . '</h2>';
        echo '<form method="post">';
        $table->display();
        echo '</form>';

        $manual_key = isset( $_POST['dsb_manual_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_manual_nonce'] ) ), 'dsb_manual_key' );
        if ( $manual_key ) {
            $email          = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
            $plan_slug      = isset( $_POST['plan_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_slug'] ) ) : '';
            $subscriptionId = isset( $_POST['subscription_id'] ) ? sanitize_text_field( wp_unslash( $_POST['subscription_id'] ) ) : 'manual-' . time();
            $order_id       = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
            $payload        = [
                'event'           => 'activated',
                'customer_email'  => $email,
                'plan_slug'       => $plan_slug,
                'subscription_id' => $subscriptionId,
                'order_id'        => $order_id,
            ];
            $result         = $this->client->send_event( $payload );
            if ( $result['decoded']['key'] ?? false ) {
                echo '<div class="updated"><p>' . esc_html__( 'Key created. Copy now, it will not be shown again:', 'davix-sub-bridge' ) . '</p>'; 
                echo '<textarea readonly style="width:100%;max-width:480px;" onclick="this.select();">' . esc_html( $result['decoded']['key'] ) . '</textarea></div>';
            }
        }

        echo '<h3>' . esc_html__( 'Create Manual Key', 'davix-sub-bridge' ) . '</h3>';
        echo '<form method="post">';
        wp_nonce_field( 'dsb_manual_key', 'dsb_manual_nonce' );
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__( 'Customer Email', 'davix-sub-bridge' ) . '</th><td><input type="email" required name="customer_email" /></td></tr>';
        echo '<tr><th>' . esc_html__( 'Plan Slug', 'davix-sub-bridge' ) . '</th><td><input type="text" required name="plan_slug" /></td></tr>';
        echo '<tr><th>' . esc_html__( 'Subscription ID', 'davix-sub-bridge' ) . '</th><td><input type="text" name="subscription_id" /></td></tr>';
        echo '<tr><th>' . esc_html__( 'Order ID (optional)', 'davix-sub-bridge' ) . '</th><td><input type="text" name="order_id" /></td></tr>';
        echo '</table>';
        submit_button( __( 'Create Manual Key', 'davix-sub-bridge' ) );
        echo '</form>';
    }

    protected function render_logs_tab(): void {
        $logs = $this->db->get_logs();

        if ( isset( $_GET['dsb_export'] ) && check_admin_referer( 'dsb_export_logs' ) ) {
            $this->export_csv( $logs );
        }

        echo '<h2>' . esc_html__( 'Bridge Logs', 'davix-sub-bridge' ) . '</h2>';
        echo '<table class="widefat">';
        echo '<thead><tr><th>' . esc_html__( 'Time', 'davix-sub-bridge' ) . '</th><th>' . esc_html__( 'Event', 'davix-sub-bridge' ) . '</th><th>' . esc_html__( 'Subscription', 'davix-sub-bridge' ) . '</th><th>' . esc_html__( 'Order', 'davix-sub-bridge' ) . '</th><th>' . esc_html__( 'Email', 'davix-sub-bridge' ) . '</th><th>' . esc_html__( 'Response', 'davix-sub-bridge' ) . '</th><th>' . esc_html__( 'HTTP', 'davix-sub-bridge' ) . '</th><th>' . esc_html__( 'Error', 'davix-sub-bridge' ) . '</th></tr></thead><tbody>';
        foreach ( $logs as $log ) {
            echo '<tr>';
            printf( '<td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>',
                esc_html( $log['created_at'] ),
                esc_html( $log['event'] ),
                esc_html( $log['subscription_id'] ),
                esc_html( $log['order_id'] ),
                esc_html( $log['customer_email'] ),
                esc_html( $log['response_action'] ),
                esc_html( $log['http_code'] ),
                esc_html( $log['error_excerpt'] )
            );
            echo '</tr>';
        }
        echo '</tbody></table>';

        $export_url = wp_nonce_url( add_query_arg( [ 'page' => 'davix-bridge', 'tab' => 'logs', 'dsb_export' => 1 ], admin_url( 'admin.php' ) ), 'dsb_export_logs' );
        echo '<p><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'davix-sub-bridge' ) . '</a></p>';
    }

    protected function export_csv( array $logs ): void {
        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="davix-bridge-logs.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'Time', 'Event', 'Subscription', 'Order', 'Email', 'Response', 'HTTP', 'Error' ] );
        foreach ( $logs as $log ) {
            fputcsv( $out, [ $log['created_at'], $log['event'], $log['subscription_id'], $log['order_id'], $log['customer_email'], $log['response_action'], $log['http_code'], $log['error_excerpt'] ] );
        }
        fclose( $out );
        exit;
    }
}
