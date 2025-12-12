<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Client {
    const OPTION_SETTINGS = 'dsb_settings';

    protected $db;

    public function __construct( DSB_DB $db ) {
        $this->db = $db;
    }

    public function get_settings(): array {
        $defaults = [
            'node_base_url' => 'https://pixlab.davix.dev',
            'bridge_token'  => '',
            'plan_mode'     => 'product',
            'delete_data'   => 0,
            'product_plans' => [],
        ];
        $options  = get_option( self::OPTION_SETTINGS, [] );
        return wp_parse_args( is_array( $options ) ? $options : [], $defaults );
    }

    public function save_settings( array $data ): void {
        $clean = [
            'node_base_url' => esc_url_raw( $data['node_base_url'] ?? '' ),
            'bridge_token'  => sanitize_text_field( $data['bridge_token'] ?? '' ),
            'plan_mode'     => in_array( $data['plan_mode'] ?? 'product', [ 'product', 'plan_meta' ], true ) ? $data['plan_mode'] : 'product',
            'delete_data'   => isset( $data['delete_data'] ) ? 1 : 0,
            'product_plans' => is_array( $data['product_plans'] ?? [] ) ? array_map( 'sanitize_text_field', $data['product_plans'] ) : [],
        ];

        update_option( self::OPTION_SETTINGS, $clean );
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

    public function send_event( array $payload ): array {
        $settings = $this->get_settings();
        if ( empty( $settings['bridge_token'] ) ) {
            return [ 'error' => __( 'Bridge token missing', 'davix-sub-bridge' ) ];
        }

        $url = trailingslashit( $settings['node_base_url'] ) . 'internal/subscription/event';
        $args = [
            'timeout' => 10,
            'headers' => [
                'x-davix-bridge-token' => $settings['bridge_token'],
                'Content-Type'         => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
        ];

        $response = $this->retry_request( $url, $args, 'POST' );
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
        $this->db->log_event( $log );

        if ( $decoded && 'ok' === ( $decoded['status'] ?? '' ) ) {
            $this->db->upsert_key(
                [
                    'subscription_id' => sanitize_text_field( $payload['subscription_id'] ?? '' ),
                    'customer_email'  => sanitize_email( $payload['customer_email'] ?? '' ),
                    'plan_slug'       => sanitize_text_field( $payload['plan_slug'] ?? '' ),
                    'status'          => $payload['event'] === 'disabled' ? 'disabled' : 'active',
                    'key_prefix'      => isset( $decoded['key'] ) && is_string( $decoded['key'] ) ? substr( $decoded['key'], 0, 6 ) : ( $decoded['key_prefix'] ?? null ),
                    'key_last4'       => isset( $decoded['key'] ) && is_string( $decoded['key'] ) ? substr( $decoded['key'], -4 ) : null,
                    'node_plan_id'    => $decoded['plan_id'] ?? null,
                    'last_action'     => $decoded['action'] ?? null,
                    'last_http_code'  => $code,
                    'last_error'      => null,
                ]
            );
        } else {
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

    protected function retry_request( string $url, array $args, string $method = 'POST' ) {
        $attempts = 0;
        $response = null;
        while ( $attempts < 2 ) {
            $attempts ++;
            $response = 'POST' === $method ? wp_remote_post( $url, $args ) : wp_remote_get( $url, $args );
            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 500 ) {
                break;
            }
        }
        return $response;
    }

    public function test_connection(): array {
        $settings = $this->get_settings();
        if ( empty( $settings['bridge_token'] ) ) {
            return [ 'error' => __( 'Bridge token missing', 'davix-sub-bridge' ) ];
        }

        $url = trailingslashit( $settings['node_base_url'] ) . 'internal/subscription/debug';
        $args = [
            'timeout' => 10,
            'headers' => [
                'x-davix-bridge-token' => $settings['bridge_token'],
            ],
        ];

        $response = $this->retry_request( $url, $args, 'GET' );
        $code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
        $decoded  = ! is_wp_error( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;

        return [
            'response' => $response,
            'decoded'  => $decoded,
            'code'     => $code,
        ];
    }
}
