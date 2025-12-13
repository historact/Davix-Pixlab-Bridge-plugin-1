<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Client {
    const OPTION_SETTINGS      = 'dsb_settings';
    const OPTION_PRODUCT_PLANS = 'dsb_product_plans';
    const OPTION_PLAN_PRODUCTS = 'dsb_plan_products';
    const OPTION_PLAN_SYNC     = 'dsb_plan_sync';

    protected $db;

    public function __construct( DSB_DB $db ) {
        $this->db = $db;
    }

    public function get_settings(): array {
        $defaults = [
            'node_base_url' => '',
            'bridge_token'  => '',
            'enable_logging'=> 1,
            'delete_data'   => 0,
            'allow_provision_without_refs' => 0,
        ];
        $options  = get_option( self::OPTION_SETTINGS, [] );
        return wp_parse_args( is_array( $options ) ? $options : [], $defaults );
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
            $clean[ $pid ] = sanitize_text_field( $plan_slug );
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

    public function get_plan_sync_status(): array {
        $status = get_option( self::OPTION_PLAN_SYNC, [] );
        if ( ! is_array( $status ) ) {
            return [];
        }
        return $status;
    }

    public function save_settings( array $data ): void {
        $clean = [
            'node_base_url' => esc_url_raw( $data['node_base_url'] ?? '' ),
            'bridge_token'  => sanitize_text_field( $data['bridge_token'] ?? '' ),
            'enable_logging'=> isset( $data['enable_logging'] ) ? 1 : 0,
            'delete_data'   => isset( $data['delete_data'] ) ? 1 : 0,
            'allow_provision_without_refs' => isset( $data['allow_provision_without_refs'] ) ? 1 : 0,
        ];

        $plan_slug_meta = isset( $data['dsb_plan_slug_meta'] ) && is_array( $data['dsb_plan_slug_meta'] ) ? $data['dsb_plan_slug_meta'] : [];
        $plan_products = isset( $data['plan_products'] ) && is_array( $data['plan_products'] ) ? array_values( $data['plan_products'] ) : [];
        $plan_products = array_filter( array_map( 'absint', $plan_products ) );

        $plans = [];
        if ( isset( $data['product_plans'] ) && is_array( $data['product_plans'] ) ) {
            foreach ( $data['product_plans'] as $product_id => $plan_slug ) {
                $pid = absint( $product_id );
                if ( $pid <= 0 ) {
                    continue;
                }
                $plans[ $pid ] = sanitize_text_field( $plan_slug );
            }
        }

        foreach ( $plan_products as $pid ) {
            $slug = isset( $plan_slug_meta[ $pid ] ) ? sanitize_text_field( $plan_slug_meta[ $pid ] ) : '';

            if ( ! $slug ) {
                $existing_slug = get_post_meta( $pid, '_dsb_plan_slug', true );
                $slug          = $existing_slug ? $existing_slug : '';
            }

            if ( ! $slug ) {
                $product = wc_get_product( $pid );
                if ( $product ) {
                    $slug = str_replace( '-', '_', sanitize_title( $product->get_slug() ) );
                }
            }

            if ( $slug ) {
                update_post_meta( $pid, '_dsb_plan_slug', $slug );
                $plans[ $pid ] = $slug;
            }
        }

        update_option( self::OPTION_SETTINGS, $clean );
        update_option( self::OPTION_PRODUCT_PLANS, $plans );
        update_option( self::OPTION_PLAN_PRODUCTS, $plan_products );
        update_option( DSB_DB::OPTION_DELETE_ON_UNINSTALL, $clean['delete_data'] );
    }

    public function masked_token(): string {
        $settings = $this->get_settings();
        $token    = $settings['bridge_token'];
        if ( ! $token ) {
            return __( 'Not set', 'davix-sub-bridge' );
        }
        $len = strlen( $token );
        if ( $len <= 6 ) {
            return str_repeat( '*', $len );
        }
        return substr( $token, 0, 3 ) . str_repeat( '*', $len - 6 ) . substr( $token, -3 );
    }

    protected function request( string $path, string $method = 'GET', array $body = [], array $query = [] ) {
        $settings = $this->get_settings();
        $method   = strtoupper( $method );

        if ( empty( $settings['node_base_url'] ) ) {
            return new \WP_Error( 'dsb_missing_base', __( 'Node base URL missing', 'davix-sub-bridge' ) );
        }

        if ( empty( $settings['bridge_token'] ) ) {
            return new \WP_Error( 'dsb_missing_token', __( 'Bridge token missing', 'davix-sub-bridge' ) );
        }

        $url = $this->build_url( $path, $query );

        $args = [
            'timeout' => 15,
            'headers' => [
                'x-davix-bridge-token' => $settings['bridge_token'],
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

    protected function post_internal( string $path, array $body = [] ): array {
        $response = $this->request( $path, 'POST', $body );
        $result   = $this->prepare_response( $response );
        $result['url']    = is_array( $response ) && isset( $response['__dsb_request_url'] ) ? $response['__dsb_request_url'] : $this->build_url( $path );
        $result['method'] = 'POST';
        return $result;
    }

    public function send_event( array $payload ): array {
        $response = $this->request( '/internal/subscription/event', 'POST', $payload );
        $body     = is_wp_error( $response ) ? null : wp_remote_retrieve_body( $response );
        $decoded  = $body ? json_decode( $body, true ) : null;
        $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );

        $log = [
            'event'           => $payload['event'] ?? '',
            'customer_email'  => $payload['customer_email'] ?? '',
            'plan_slug'       => $payload['plan_slug'] ?? '',
            'subscription_id' => $payload['subscription_id'] ?? '',
            'order_id'        => $payload['order_id'] ?? '',
            'response_action' => $decoded['action'] ?? null,
            'http_code'       => $code,
            'error_excerpt'   => is_wp_error( $response ) ? $response->get_error_message() : ( $decoded['status'] ?? '' ),
        ];
        $settings = $this->get_settings();
        if ( $settings['enable_logging'] ) {
            $this->db->log_event( $log );
        }

        if ( $decoded && 'ok' === ( $decoded['status'] ?? '' ) ) {
            $this->db->upsert_key(
                [
                    'subscription_id' => sanitize_text_field( $payload['subscription_id'] ?? '' ),
                    'customer_email'  => sanitize_email( $payload['customer_email'] ?? '' ),
                    'plan_slug'       => sanitize_text_field( $payload['plan_slug'] ?? '' ),
                    'status'          => isset( $payload['event'] ) && in_array( $payload['event'], [ 'cancelled', 'disabled' ], true ) ? 'disabled' : 'active',
                    'key_prefix'      => isset( $decoded['key'] ) && is_string( $decoded['key'] ) ? substr( $decoded['key'], 0, 10 ) : ( $decoded['key_prefix'] ?? null ),
                    'key_last4'       => isset( $decoded['key'] ) && is_string( $decoded['key'] ) ? substr( $decoded['key'], -4 ) : ( $decoded['key_last4'] ?? null ),
                    'node_plan_id'    => $decoded['plan_id'] ?? null,
                    'last_action'     => $decoded['action'] ?? null,
                    'last_http_code'  => $code,
                    'last_error'      => null,
                ]
            );
        } elseif ( $settings['enable_logging'] ) {
            $this->db->upsert_key(
                [
                    'subscription_id' => sanitize_text_field( $payload['subscription_id'] ?? '' ),
                    'customer_email'  => sanitize_email( $payload['customer_email'] ?? '' ),
                    'plan_slug'       => sanitize_text_field( $payload['plan_slug'] ?? '' ),
                    'status'          => 'error',
                    'last_action'     => $payload['event'] ?? '',
                    'last_http_code'  => $code,
                    'last_error'      => is_wp_error( $response ) ? $response->get_error_message() : ( $decoded['status'] ?? '' ),
                ]
            );
        }

        return [
            'response' => $response,
            'decoded'  => $decoded,
            'code'     => $code,
        ];
    }

    public function test_connection(): array {
        $response = $this->request( '/internal/subscription/debug', 'GET' );
        $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        $decoded  = ! is_wp_error( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;

        return [
            'response' => $response,
            'decoded'  => $decoded,
            'code'     => $code,
        ];
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
        $payload = array_merge( $identity, [ 'enabled' => $enabled ] );
        return $this->post_internal( '/internal/user/key/toggle', $payload );
    }

    public function fetch_user_summary( array $payload ): array {
        return $this->post_internal( '/internal/user/summary', $payload );
    }

    public function rotate_user_key( array $payload ): array {
        return $this->post_internal( '/internal/user/key/rotate', $payload );
    }

    public function fetch_plans() {
        return $this->request( '/internal/admin/plans', 'GET' );
    }

    public function sync_plan( array $payload ) {
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
}
