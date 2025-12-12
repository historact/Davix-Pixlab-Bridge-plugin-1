<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Client {
    const OPTION_SETTINGS      = 'dsb_settings';
    const OPTION_PRODUCT_PLANS = 'dsb_product_plans';

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
        ];
        $options  = get_option( self::OPTION_SETTINGS, [] );
        return wp_parse_args( is_array( $options ) ? $options : [], $defaults );
    }

    public function get_product_plans(): array {
        $plans = get_option( self::OPTION_PRODUCT_PLANS, [] );
        return isset( $plans ) && is_array( $plans ) ? array_map( 'sanitize_text_field', $plans ) : [];
    }

    public function save_settings( array $data ): void {
        $clean = [
            'node_base_url' => esc_url_raw( $data['node_base_url'] ?? '' ),
            'bridge_token'  => sanitize_text_field( $data['bridge_token'] ?? '' ),
            'enable_logging'=> isset( $data['enable_logging'] ) ? 1 : 0,
            'delete_data'   => isset( $data['delete_data'] ) ? 1 : 0,
        ];

        $existing_plans = $this->get_product_plans();
        $plans = isset( $data['product_plans'] ) && is_array( $data['product_plans'] ) ? array_filter(
            array_map( static function ( $value ) {
                return sanitize_text_field( $value );
            },
                $data['product_plans']
            ),
            static function ( $value ) {
                return '' !== $value;
            }
        ) : $existing_plans;

        update_option( self::OPTION_SETTINGS, $clean );
        update_option( self::OPTION_PRODUCT_PLANS, $plans );
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
        if ( empty( $settings['bridge_token'] ) ) {
            return new \WP_Error( 'dsb_missing_token', __( 'Bridge token missing', 'davix-sub-bridge' ) );
        }

        $url = trailingslashit( $settings['node_base_url'] ) . ltrim( $path, '/' );
        if ( $query ) {
            $url = add_query_arg( $query, $url );
        }

        $args = [
            'timeout' => 15,
            'headers' => [
                'x-davix-bridge-token' => $settings['bridge_token'],
            ],
        ];

        if ( 'POST' === strtoupper( $method ) ) {
            $args['body']             = wp_json_encode( $body );
            $args['headers']['Content-Type'] = 'application/json';
        }

        $response = 'POST' === strtoupper( $method ) ? wp_remote_post( $url, $args ) : wp_remote_get( $url, $args );
        return $response;
    }

    public function send_event( array $payload ): array {
        $response = $this->request( 'internal/subscription/event', 'POST', $payload );
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
        $response = $this->request( 'internal/subscription/debug', 'GET' );
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
            'internal/admin/keys',
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
        return $this->request( 'internal/admin/key/provision', 'POST', $payload );
    }

    public function disable_key( array $payload ) {
        return $this->request( 'internal/admin/key/disable', 'POST', $payload );
    }

    public function rotate_key( array $payload ) {
        return $this->request( 'internal/admin/key/rotate', 'POST', $payload );
    }

    public function fetch_plans() {
        return $this->request( 'internal/admin/plans', 'GET' );
    }
}
