<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Events {
    const ORDER_META_SUBSCRIPTION_ID = '_dsb_subscription_id';
    const ORDER_META_RETRY_COUNT     = '_dsb_subid_retry_count';
    const ORDER_META_RETRY_LOCK      = '_dsb_subid_retry_lock';
    const ORDER_META_LAST_SENT_EVENT = '_dsb_last_sent_event';
    const ORDER_META_VALID_UNTIL_BACKFILLED = '_dsb_valid_until_backfilled';
    const ORDER_META_EVENT_SENT_ACTIVATED = '_dsb_event_sent_activated';
    const ORDER_META_EVENT_SENT_ACTIVATED_WITH_VALID_UNTIL = '_dsb_event_sent_activated_with_valid_until';
    const ORDER_META_VALID_UNTIL = '_dsb_valid_until';
    const MAX_RETRY_ATTEMPTS         = 10;
    protected $client;
    protected $db;
    private $last_valid_until_source = '';

    public function __construct( DSB_Client $client, DSB_DB $db ) {
        $this->client = $client;
        $this->db     = $db;
    }

    public function init(): void {
        if ( ! defined( 'DSB_ENABLE_WPS_LEGACY' ) || true !== DSB_ENABLE_WPS_LEGACY ) {
            dsb_log( 'debug', 'WPS legacy hooks disabled by default; skipping WPS event registration' );
            return;
        }

        add_action( 'wps_sfw_after_renewal_payment', [ $this, 'handle_wps_renewal' ], 10, 2 );
        add_action( 'wps_sfw_expire_subscription_scheduler', [ $this, 'handle_wps_expire' ], 10, 1 );
        add_action( 'wps_sfw_subscription_cancel', [ $this, 'handle_wps_cancel' ], 10, 1 );

        add_action( 'wps_sfw_after_created_subscription', [ $this, 'handle_wps_subscription_created' ], 10, 2 );
        add_action( 'wps_sfw_subscription_order', [ $this, 'handle_wps_subscription_order_created' ], 10, 2 );
        add_action( 'wps_sfw_subscription_process_checkout', [ $this, 'handle_wps_subscription_process_checkout' ], 10, 3 );

        add_action( 'woocommerce_checkout_order_processed', [ $this, 'handle_checkout' ], 10, 3 );
        add_action( 'woocommerce_order_status_changed', [ $this, 'handle_order_status_change' ], 10, 4 );

        add_action( 'dsb_retry_provision_order', [ $this, 'retry_provision_order' ], 10, 2 );
        add_action( 'dsb_backfill_valid_until_for_subscription', [ $this, 'backfill_valid_until_for_subscription' ], 10, 2 );

        add_filter( 'wps_sfw_susbcription_end_date', [ $this, 'dsb_capture_wps_expiry' ], 9999, 2 );
    }

    public function dsb_capture_wps_expiry( $expiry, $subscription_id ) {
        $subscription_id = (int) $subscription_id;
        if ( $subscription_id <= 0 ) {
            return $expiry;
        }

        $dt = $this->parse_expiry_value( $expiry );
        if ( ! $dt ) {
            return $expiry;
        }

        $normalized = $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        $iso        = $this->format_datetime_for_node( $dt );

        update_post_meta( $subscription_id, '_dsb_wps_valid_until', $normalized );
        update_post_meta( $subscription_id, '_dsb_wps_valid_until_source', 'wps_sfw_susbcription_end_date_filter' );
        update_post_meta( $subscription_id, '_dsb_wps_valid_until_captured_at', current_time( 'mysql', true ) );
        update_post_meta( $subscription_id, '_dsb_valid_until', $iso );

        $parent_id = 0;
        $subscription_order = wc_get_order( $subscription_id );
        if ( $subscription_order && method_exists( $subscription_order, 'get_parent_id' ) ) {
            $parent_id = (int) $subscription_order->get_parent_id();
            if ( $parent_id > 0 ) {
                update_post_meta( $parent_id, '_dsb_wps_valid_until', $normalized );
                update_post_meta( $parent_id, self::ORDER_META_VALID_UNTIL, $iso );
            }
        }

        if ( $parent_id > 0 ) {
            $order = wc_get_order( $parent_id );
            $this->persist_valid_until( $dt, (string) $subscription_id, $order instanceof \WC_Order ? $order : null, '', '', 'wps_expiry_filter' );
        } elseif ( $subscription_order instanceof \WC_Order ) {
            $this->persist_valid_until( $dt, (string) $subscription_id, $subscription_order, '', '', 'wps_expiry_filter' );
        }

        dsb_log(
            'debug',
            'Captured WPS expiry via filter',
            [
                'subscription_id' => $subscription_id,
                'parent_id'       => $parent_id,
                'raw_expiry'      => is_string( $expiry ) ? substr( $expiry, 0, 64 ) : $expiry,
                'normalized'      => $normalized,
                'iso8601'         => $iso,
            ]
        );

        $this->maybe_backfill_valid_until_now( (string) $subscription_id );

        return $expiry;
    }

    public function handle_wps_renewal( $subscription_id, $order_id = null ): void {
        $order = $order_id ? wc_get_order( $order_id ) : null;
        $payload = $this->build_payload( (string) $subscription_id, 'renewed', $order );
        $this->maybe_send( $payload, $order instanceof \WC_Order ? $order : null, 'renewed' );
    }

    public function handle_wps_expire( $subscription_id ): void {
        $payload = $this->build_payload( (string) $subscription_id, 'expired' );
        $this->maybe_send( $payload, null, 'expired' );
    }

    public function handle_wps_cancel( $subscription_id ): void {
        $payload = $this->build_payload( (string) $subscription_id, 'cancelled' );
        $this->maybe_send( $payload, null, 'cancelled' );
    }

    public function handle_wps_subscription_created( $subscription_id, $order_id ): void {
        dsb_log( 'info', 'WPS subscription created hook fired', [ 'subscription_id' => $subscription_id, 'order_id' => $order_id ] );

        if ( ! $subscription_id || ! $order_id ) {
            return;
        }

        $order = wc_get_order( (int) $order_id );
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $this->set_subscription_id_on_order( $order, (string) $subscription_id );
        $event = $this->map_status_to_event( $order->get_status() ) ?: 'activated';

        if ( $this->order_contains_mapped_product( $order ) ) {
            $payload = $this->build_payload( (string) $subscription_id, $event, $order );
            $result  = $this->maybe_send( $payload, $order, $event );

            if ( $result && ( $result['decoded']['status'] ?? '' ) === 'ok' ) {
                $this->mark_event_sent( $order, $event, $payload );
                $this->clear_retry_state( $order );
            }
        }
    }

    public function handle_wps_subscription_order_created( $subscription, $order_id ): void {
        dsb_log( 'info', 'WPS subscription order hook fired', [ 'subscription' => $subscription, 'order_id' => $order_id ] );

        $subscription_id = $this->extract_subscription_id_from_unknown( $subscription );
        if ( ! $subscription_id && is_numeric( $order_id ) ) {
            $order          = wc_get_order( (int) $order_id );
            $subscription_id = $order instanceof \WC_Order ? $this->find_subscription_id_for_order( $order ) : '';
        }

        if ( ! $subscription_id || ! $order_id ) {
            if ( is_numeric( $order_id ) ) {
                $order = wc_get_order( (int) $order_id );
                if ( $order instanceof \WC_Order ) {
                    $this->schedule_retry_if_needed( $order, 'activated', 'subscription_missing' );
                }
            }
            return;
        }

        $order = wc_get_order( (int) $order_id );
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $this->set_subscription_id_on_order( $order, (string) $subscription_id );

        if ( $this->order_contains_mapped_product( $order ) ) {
            $payload = $this->build_payload( (string) $subscription_id, 'activated', $order );
            $result  = $this->maybe_send( $payload, $order, 'activated' );

            if ( $result && ( $result['decoded']['status'] ?? '' ) === 'ok' ) {
                $this->mark_event_sent( $order, 'activated', $payload );
                $this->clear_retry_state( $order );
            }
        }
    }

    public function handle_wps_subscription_process_checkout( $order_id, $posted_data, $subscription ): void {
        dsb_log( 'info', 'WPS subscription checkout hook fired', [ 'subscription' => $subscription, 'order_id' => $order_id ] );

        $subscription_id = $this->extract_subscription_id_from_unknown( $subscription );
        $order           = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : null;

        if ( $order instanceof \WC_Order ) {
            if ( $subscription_id ) {
                $this->set_subscription_id_on_order( $order, (string) $subscription_id );
            }
        }

        if ( $order instanceof \WC_Order && $this->order_contains_mapped_product( $order ) ) {
            $payload = $this->build_payload( $subscription_id ? (string) $subscription_id : '', 'activated', $order );
            $result  = $this->maybe_send( $payload, $order, 'activated' );

            if ( $result && ( $result['decoded']['status'] ?? '' ) === 'ok' && $subscription_id ) {
                $this->mark_event_sent( $order, 'activated', $payload );
                $this->clear_retry_state( $order );
            }
        }

        if ( $order instanceof \WC_Order && ! $subscription_id ) {
            $this->schedule_retry_if_needed( $order, 'activated', 'subscription_missing' );
        }
    }

    public function handle_checkout( $order_id, $posted_data, $order ): void {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        $subscription_id = $this->find_subscription_id_for_order( $order );
        $event   = 'activated';
        $payload = $this->build_payload( $subscription_id ? (string) $subscription_id : '', $event, $order );
        if ( $payload && $this->order_contains_mapped_product( $order ) ) {
            $this->maybe_send( $payload, $order, $event );
        }

        if ( ! $subscription_id ) {
            $this->schedule_retry_if_needed( $order, 'activated', 'subscription_missing' );
        }
    }

    public function handle_order_status_change( $order_id, $old_status, $new_status, $order ): void {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        $subscription_id = $this->find_subscription_id_for_order( $order );
        if ( ! $subscription_id ) {
            $this->db->log_event(
                [
                    'event'         => 'subscription_missing',
                    'order_id'      => $order->get_id(),
                    'error_excerpt' => __( 'Subscription ID missing; sending with external reference.', 'pixlab-license-bridge' ),
                ]
            );
        }
        $event = $this->map_status_to_event( $new_status );
        if ( ! $event ) {
            return;
        }
        $payload = $this->build_payload( $subscription_id ? (string) $subscription_id : '', $event, $order );
        $this->maybe_send( $payload, $order, $event );

        if ( ! $subscription_id ) {
            $this->schedule_retry_if_needed( $order, $event, 'subscription_missing' );
        }
    }

    protected function maybe_send( ?array $payload, ?\WC_Order $order = null, string $event_name = '' ): ?array {
        if ( ! $payload ) {
            return null;
        }

        $plans = $this->client->get_product_plans();
        if ( empty( $payload['plan_slug'] ) || ! $this->plan_exists( $payload['plan_slug'], $plans ) ) {
            $product_id = $payload['product_id'] ?? '';
            $order_id   = $payload['order_id'] ?? '';
            $this->db->log_event(
                [
                    'event'           => 'plan_missing',
                    'customer_email'  => $payload['customer_email'] ?? '',
                    'order_id'        => $order_id,
                    'subscription_id' => $payload['subscription_id'] ?? '',
                    'plan_slug'       => $payload['plan_slug'] ?? '',
                    'error_excerpt'   => sprintf(
                        /* translators: 1: product ID, 2: order ID */
                        __( 'Plan mapping missing; product %1$s (order %2$s).', 'pixlab-license-bridge' ),
                        $product_id ?: '?',
                        $order_id ?: '?'
                    ),
                ]
            );
            add_action( 'admin_notices', static function () {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'PixLab License: plan mapping missing for last subscription event.', 'pixlab-license-bridge' ) . '</p></div>';
            } );
            return null;
        }

        $attempt = $order instanceof \WC_Order ? $this->current_attempt_number( $order ) : 1;
        $this->persist_user_truth_from_payload( $payload );
        $result  = $this->client->send_event( $payload );
        $success = $result && ( $result['decoded']['status'] ?? '' ) === 'ok';

        if ( $order instanceof \WC_Order ) {
            $event_label = $payload['event'] ?? ( $event_name ?: '' );
            $context     = [
                'order_id'                => $order->get_id(),
                'event'                   => $event_label,
                'attempt'                 => $attempt,
                'subscription_id'         => $payload['subscription_id'] ?? '',
                'plan_slug'               => $payload['plan_slug'] ?? '',
                'http_code'               => $result['code'] ?? null,
                'response_status'         => $result['decoded']['status'] ?? null,
            ];

            if ( $success ) {
                dsb_log( 'info', 'Provisioning request succeeded', $context );
                $this->clear_retry_state( $order );
            } else {
                $error_excerpt = '';
                if ( is_array( $result ) && isset( $result['response'] ) && is_wp_error( $result['response'] ) ) {
                    $error_excerpt = $result['response']->get_error_message();
                } elseif ( is_array( $result ) && isset( $result['decoded']['status'] ) ) {
                    $error_excerpt = (string) $result['decoded']['status'];
                } elseif ( is_array( $result ) && isset( $result['code'] ) ) {
                    $error_excerpt = (string) $result['code'];
                }

                dsb_log( 'warning', 'Provisioning request failed', $context + [ 'error_excerpt' => $error_excerpt ] );
                $this->schedule_retry_if_needed( $order, $event_label ?: 'activated', 'dispatch_failed' );
            }
        }

        return $result;
    }

    protected function persist_user_truth_from_payload( array $payload ): void {
        $wp_user_id = isset( $payload['wp_user_id'] ) ? absint( $payload['wp_user_id'] ) : 0;
        if ( $wp_user_id <= 0 ) {
            return;
        }

        $status = '';
        if ( ! empty( $payload['subscription_status'] ) ) {
            $status = (string) $payload['subscription_status'];
        } elseif ( ! empty( $payload['event'] ) ) {
            $event = (string) $payload['event'];
            $map   = [
                'cancelled'      => 'cancelled',
                'expired'        => 'expired',
                'payment_failed' => 'payment_failed',
                'paused'         => 'paused',
                'renewed'        => 'active',
                'reactivated'    => 'active',
                'activated'      => 'active',
                'active'         => 'active',
                'disabled'       => 'disabled',
            ];
            $status = $map[ $event ] ?? $event;
        }

        $this->db->upsert_user(
            [
                'wp_user_id'      => $wp_user_id,
                'customer_email'  => $payload['customer_email'] ?? '',
                'subscription_id' => $payload['subscription_id'] ?? null,
                'order_id'        => $payload['order_id'] ?? null,
                'product_id'      => $payload['product_id'] ?? null,
                'plan_slug'       => $payload['plan_slug'] ?? null,
                'status'          => $status,
                'valid_from'      => $payload['valid_from'] ?? null,
                'valid_until'     => $payload['valid_until'] ?? null,
                'source'          => 'hooks',
                'last_sync_at'    => current_time( 'mysql', true ),
            ]
        );
    }

    protected function plan_exists( string $plan_slug, array $plans ): bool {
        return in_array( $plan_slug, array_values( $plans ), true );
    }

    protected function map_status_to_event( string $status ): ?string {
        $status = strtolower( $status );
        switch ( $status ) {
            case 'completed':
            case 'processing':
            case 'active':
                return 'activated';
            case 'cancelled':
            case 'refunded':
            case 'trash':
                return 'cancelled';
            case 'failed':
                return 'payment_failed';
            case 'expired':
                return 'expired';
            default:
                return null;
        }
    }

    protected function find_subscription_id_for_order( \WC_Order $order ): string {
        $meta_keys = [ self::ORDER_META_SUBSCRIPTION_ID, 'wps_sfw_subscription_id', 'subscription_id', '_subscription_id' ];
        $found     = '';

        foreach ( $meta_keys as $meta_key ) {
            $value = $order->get_meta( $meta_key );
            if ( $value ) {
                $found = (string) $value;
                break;
            }
        }

        if ( $found && ! $order->get_meta( self::ORDER_META_SUBSCRIPTION_ID ) ) {
            $this->set_subscription_id_on_order( $order, $found );
        }

        return $found;
    }

    protected function set_subscription_id_on_order( \WC_Order $order, string $subscription_id ): void {
        if ( '' === $subscription_id ) {
            return;
        }

        $existing = $order->get_meta( self::ORDER_META_SUBSCRIPTION_ID );
        if ( (string) $existing === (string) $subscription_id ) {
            return;
        }

        $order->update_meta_data( self::ORDER_META_SUBSCRIPTION_ID, (string) $subscription_id );

        if ( ! $order->get_meta( 'wps_sfw_subscription_id' ) ) {
            $order->update_meta_data( 'wps_sfw_subscription_id', (string) $subscription_id );
        }

        $order->save();

        update_post_meta( $order->get_id(), self::ORDER_META_SUBSCRIPTION_ID, (string) $subscription_id );
        update_post_meta( (int) $subscription_id, '_dsb_parent_order_id', (string) $order->get_id() );

        dsb_log(
            'debug',
            'Stored subscription↔order mapping',
            [
                'order_id'        => $order->get_id(),
                'subscription_id' => $subscription_id,
            ]
        );

        dsb_log( 'info', 'Subscription ID stored on order', [ 'order_id' => $order->get_id(), 'subscription_id' => $subscription_id ] );
    }

    protected function extract_subscription_id_from_unknown( $maybe_subscription ) {
        if ( is_numeric( $maybe_subscription ) ) {
            return (string) $maybe_subscription;
        }

        if ( is_array( $maybe_subscription ) ) {
            if ( isset( $maybe_subscription['subscription_id'] ) && $maybe_subscription['subscription_id'] ) {
                return (string) $maybe_subscription['subscription_id'];
            }
            if ( isset( $maybe_subscription['id'] ) && $maybe_subscription['id'] ) {
                return (string) $maybe_subscription['id'];
            }
        }

        if ( is_object( $maybe_subscription ) ) {
            if ( method_exists( $maybe_subscription, 'get_id' ) ) {
                $id = $maybe_subscription->get_id();
                if ( $id ) {
                    return (string) $id;
                }
            }

            if ( isset( $maybe_subscription->ID ) && $maybe_subscription->ID ) {
                return (string) $maybe_subscription->ID;
            }
        }

        return '';
    }

    protected function current_attempt_number( \WC_Order $order ): int {
        $attempt = (int) $order->get_meta( self::ORDER_META_RETRY_COUNT );
        return max( 1, $attempt + 1 );
    }

    protected function schedule_retry_if_needed( \WC_Order $order, string $event_name, string $reason = '' ): void {
        if ( $this->order_in_terminal_state( $order ) ) {
            return;
        }

        $attempt = $this->current_attempt_number( $order );
        if ( $attempt > self::MAX_RETRY_ATTEMPTS ) {
            dsb_log( 'warning', 'Max retry attempts reached for provisioning', [ 'order_id' => $order->get_id(), 'event' => $event_name, 'reason' => $reason ] );
            return;
        }

        $existing = wp_next_scheduled( 'dsb_retry_provision_order', [ $order->get_id(), $event_name ] );
        if ( $existing ) {
            return;
        }

        $delay     = $this->get_retry_delay_seconds( $attempt );
        $timestamp = time() + $delay;
        wp_schedule_single_event( $timestamp, 'dsb_retry_provision_order', [ $order->get_id(), $event_name ] );

        $order->update_meta_data( self::ORDER_META_RETRY_LOCK, $timestamp );
        $order->update_meta_data( self::ORDER_META_RETRY_COUNT, $attempt );
        $order->save();

        dsb_log( 'info', 'Retry scheduled for provisioning', [ 'order_id' => $order->get_id(), 'attempt' => $attempt, 'run_at' => $timestamp, 'event' => $event_name, 'reason' => $reason ] );
    }

    protected function get_retry_delay_seconds( int $attempt ): int {
        $attempt = max( 1, $attempt );
        $base    = 60;
        $delay   = $base * ( 2 ** ( $attempt - 1 ) );
        return (int) min( 3600, $delay );
    }

    protected function schedule_valid_until_backfill( string $subscription_id, ?\WC_Order $order ): void {
        if ( '' === $subscription_id ) {
            return;
        }

        $order_id = $order instanceof \WC_Order ? $order->get_id() : 0;
        if ( wp_next_scheduled( 'dsb_backfill_valid_until_for_subscription', [ $subscription_id, $order_id ] ) ) {
            return;
        }

        $timestamp = time() + 60;
        wp_schedule_single_event( $timestamp, 'dsb_backfill_valid_until_for_subscription', [ $subscription_id, $order_id ] );

        dsb_log(
            'debug',
            'Scheduled valid_until backfill',
            [
                'subscription_id' => $subscription_id,
                'order_id'        => $order_id,
                'run_at'          => $timestamp,
            ]
        );
    }

    public function retry_provision_order( $order_id, $event_name = 'activated' ): void {
        $order = wc_get_order( (int) $order_id );
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $subscription_id = $this->find_subscription_id_for_order( $order );
        $payload = $this->build_payload( $subscription_id ? (string) $subscription_id : '', $event_name, $order );
        if ( ! $payload ) {
            if ( ! $subscription_id ) {
                $this->schedule_retry_if_needed( $order, $event_name, 'subscription_missing' );
            }
            return;
        }

        if ( $subscription_id ) {
            $this->set_subscription_id_on_order( $order, (string) $subscription_id );
        }

        if ( $this->order_contains_mapped_product( $order ) ) {
            $last_sent        = $order->get_meta( self::ORDER_META_LAST_SENT_EVENT );
            $sent_activated   = (bool) $order->get_meta( self::ORDER_META_EVENT_SENT_ACTIVATED );
            $sent_with_valid  = (bool) $order->get_meta( self::ORDER_META_EVENT_SENT_ACTIVATED_WITH_VALID_UNTIL );
            $already_sent     = $subscription_id && $last_sent && $last_sent === $event_name;
            $valid_until      = $payload['valid_until'] ?? '';
            $backfill_meta    = (bool) $order->get_meta( self::ORDER_META_VALID_UNTIL_BACKFILLED );
            $key_row          = $subscription_id ? $this->db->get_key_by_subscription_id( (string) $subscription_id ) : null;
            $key_valid_until  = $key_row['valid_until'] ?? null;
            $backfill_request = ! empty( $payload['_dsb_validity_backfill'] );
            $needs_identity_update = ! $key_row || (
                ( empty( $key_row['wp_user_id'] ) && ! empty( $payload['wp_user_id'] ) ) ||
                ( empty( $key_row['customer_name'] ) && ! empty( $payload['customer_name'] ) ) ||
                ( empty( $key_row['subscription_status'] ) && ! empty( $payload['subscription_status'] ) ) ||
                ( empty( $key_row['customer_email'] ) && ! empty( $payload['customer_email'] ) )
            );

            $is_patch_event = ( 'activated' === $event_name ) && $sent_activated && ! $sent_with_valid && $valid_until;
            if ( $is_patch_event ) {
                $payload['event_patch'] = 'valid_until';
                dsb_log(
                    'info',
                    'Sending valid_until patch event',
                    [
                        'order_id'        => $order->get_id(),
                        'subscription_id' => $subscription_id,
                        'valid_until'     => $valid_until,
                    ]
                );
            }

            $payload['event_id'] = DSB_Util::event_id_from_payload( $payload );

            if ( $already_sent && ! ( $backfill_request && $valid_until ) && ! $needs_identity_update && ! $is_patch_event ) {
                if ( $valid_until && $key_row && empty( $key_valid_until ) && ! $backfill_meta ) {
                    dsb_log(
                        'info',
                        'Duplicate event allowed to backfill valid_until',
                        [
                            'order_id'        => $order->get_id(),
                            'subscription_id' => $subscription_id,
                            'event'           => $event_name,
                            'valid_until'     => $valid_until,
                        ]
                    );
                    $order->update_meta_data( self::ORDER_META_VALID_UNTIL_BACKFILLED, 1 );
                    $order->save();
                } else {
                    dsb_log(
                        'info',
                        'Provisioning event already sent; skipping duplicate',
                        [
                            'order_id'               => $order->get_id(),
                            'event'                  => $event_name,
                            'valid_until_in_payload' => (bool) $valid_until,
                            'backfill_meta_set'      => $backfill_meta,
                            'key_valid_until_empty'  => $key_row ? empty( $key_valid_until ) : null,
                            'needs_identity_update'  => $needs_identity_update,
                        ]
                    );
                    $this->clear_retry_state( $order );
                    return;
                }
            }

            $result = $this->maybe_send( $payload, $order, $event_name );

            if ( $result && ( $result['decoded']['status'] ?? '' ) === 'ok' ) {
                if ( $subscription_id ) {
                    $this->mark_event_sent( $order, $event_name, $payload );
                    $this->clear_retry_state( $order );
                } else {
                    $this->schedule_retry_if_needed( $order, $event_name, 'subscription_missing' );
                }
            } elseif ( ! $subscription_id ) {
                $this->schedule_retry_if_needed( $order, $event_name, 'subscription_missing' );
            }
        }
    }

    public function backfill_valid_until_for_subscription( $subscription_id, $order_id = 0 ): void {
        $subscription_id = (string) $subscription_id;
        $order           = $order_id ? wc_get_order( (int) $order_id ) : null;

        dsb_log(
            'debug',
            'Backfill cron running',
            [
                'subscription_id' => $subscription_id,
                'order_id'        => $order instanceof \WC_Order ? $order->get_id() : $order_id,
            ]
        );

        if ( $order instanceof \WC_Order && $subscription_id ) {
            $this->set_subscription_id_on_order( $order, $subscription_id );
        }

        $key_row = $subscription_id ? $this->db->get_key_by_subscription_id( $subscription_id ) : null;
        if ( $key_row && ! empty( $key_row['valid_until'] ) ) {
            dsb_log(
                'debug',
                'Backfill cron skipped; valid_until already set',
                [
                    'subscription_id' => $subscription_id,
                    'order_id'        => $order instanceof \WC_Order ? $order->get_id() : $order_id,
                ]
            );
            return;
        }

        $payload = $this->build_payload( $subscription_id, 'activated', $order instanceof \WC_Order ? $order : null );

        if ( ! $payload || empty( $payload['valid_until'] ) ) {
            dsb_log(
                'debug',
                'Backfill cron attempted without valid_until',
                [
                    'subscription_id' => $subscription_id,
                    'order_id'        => $order instanceof \WC_Order ? $order->get_id() : $order_id,
                ]
            );
            return;
        }

        $result = $this->maybe_send( $payload, $order instanceof \WC_Order ? $order : null, 'activated' );

        dsb_log(
            'info',
            'Backfill cron attempted',
            [
                'subscription_id' => $subscription_id,
                'order_id'        => $order instanceof \WC_Order ? $order->get_id() : $order_id,
                'valid_until'     => $payload['valid_until'],
                'result_status'   => $result['decoded']['status'] ?? null,
            ]
        );
    }

    private function maybe_backfill_valid_until_now( string $subscription_id ): void {
        $valid_until_meta = get_post_meta( $subscription_id, '_dsb_wps_valid_until', true );
        if ( ! $valid_until_meta ) {
            return;
        }

        $order_id = (int) get_post_meta( $subscription_id, '_dsb_parent_order_id', true );
        if ( $order_id <= 0 ) {
            $found_orders = wc_get_orders(
                [
                    'limit'      => 1,
                    'return'     => 'ids',
                    'meta_query' => [
                        [
                            'key'   => self::ORDER_META_SUBSCRIPTION_ID,
                            'value' => $subscription_id,
                        ],
                    ],
                ]
            );

            if ( ! empty( $found_orders ) && is_array( $found_orders ) ) {
                $order_id = (int) $found_orders[0];
            }
        }

        if ( $order_id <= 0 ) {
            dsb_log(
                'warning',
                'Validity backfill proceeding without order context',
                [ 'subscription_id' => $subscription_id ]
            );
        }

        $order = $order_id > 0 ? wc_get_order( $order_id ) : null;
        if ( $order instanceof \WC_Order ) {
            $this->set_subscription_id_on_order( $order, $subscription_id );
        }

        $sent_with_valid = $order instanceof \WC_Order ? (bool) $order->get_meta( self::ORDER_META_EVENT_SENT_ACTIVATED_WITH_VALID_UNTIL ) : false;

        $payload = $this->build_payload( $subscription_id, 'activated', $order instanceof \WC_Order ? $order : null );

        if ( ! $payload ) {
            $dt = $this->parse_expiry_value( $valid_until_meta );
            if ( $dt instanceof \DateTimeInterface ) {
                $payload = [
                    'event'           => 'activated',
                    'subscription_id' => $subscription_id,
                    'valid_until'     => $this->format_datetime_for_node( $dt ),
                    'order_id'        => $order instanceof \WC_Order ? $order->get_id() : $order_id,
                    'customer_email'  => $key_row['customer_email'] ?? '',
                    'plan_slug'       => $key_row['plan_slug'] ?? '',
                ];
            }
        }

        if ( empty( $payload ) || empty( $payload['valid_until'] ) ) {
            return;
        }

        $payload['_dsb_validity_backfill'] = 1;
        if ( ! $sent_with_valid ) {
            $payload['event_patch'] = 'valid_until';
        }

        $payload['event_id'] = DSB_Util::event_id_from_payload( $payload );

        dsb_log(
            'debug',
            'Validity backfill triggered from expiry capture',
            [
                'subscription_id' => $subscription_id,
                'order_id'        => $order instanceof \WC_Order ? $order->get_id() : $order_id,
                'valid_until'     => $payload['valid_until'],
            ]
        );

        $result = $this->maybe_send( $payload, $order instanceof \WC_Order ? $order : null, 'activated' );

        if ( $result && ( $result['decoded']['status'] ?? '' ) === 'ok' && $order instanceof \WC_Order ) {
            $this->mark_event_sent( $order, 'activated', $payload );
        }
    }

    protected function clear_retry_state( \WC_Order $order ): void {
        $order->delete_meta_data( self::ORDER_META_RETRY_LOCK );
        $order->delete_meta_data( self::ORDER_META_RETRY_COUNT );
        $order->save();
    }

    protected function order_in_terminal_state( \WC_Order $order ): bool {
        $terminal = [ 'cancelled', 'failed', 'refunded', 'trash' ];
        return in_array( $order->get_status(), $terminal, true );
    }

    protected function mark_event_sent( \WC_Order $order, string $event, array $payload = [] ): void {
        $order->update_meta_data( self::ORDER_META_LAST_SENT_EVENT, $event );

        if ( 'activated' === $event ) {
            $order->update_meta_data( self::ORDER_META_EVENT_SENT_ACTIVATED, 1 );

            if ( ! empty( $payload['valid_until'] ) ) {
                $order->update_meta_data( self::ORDER_META_EVENT_SENT_ACTIVATED_WITH_VALID_UNTIL, 1 );
                $order->update_meta_data( self::ORDER_META_VALID_UNTIL, $payload['valid_until'] );
            }
        }

        $order->save();
    }

    protected function resolve_subscription_status( string $subscription_id, ?\WC_Order $order = null ): string {
        $candidates = [];

        if ( $order instanceof \WC_Order ) {
            $candidates[] = $order->get_status();
        }

        if ( $subscription_id ) {
            $subscription_order = wc_get_order( (int) $subscription_id );
            if ( $subscription_order instanceof \WC_Order ) {
                $candidates[] = $subscription_order->get_status();
            }

            $meta_keys = [ 'wps_sfw_subscription_status', '_wps_sfw_subscription_status', 'subscription_status' ];
            foreach ( $meta_keys as $meta_key ) {
                $meta_value = get_post_meta( $subscription_id, $meta_key, true );
                if ( $meta_value ) {
                    $candidates[] = $meta_value;
                    break;
                }
            }

            $post_status = get_post_status( $subscription_id );
            if ( $post_status ) {
                $candidates[] = $post_status;
            }
        }

        foreach ( $candidates as $status ) {
            if ( is_string( $status ) && '' !== $status ) {
                return strtolower( $status );
            }
        }

        return '';
    }

    protected function order_contains_mapped_product( \WC_Order $order ): bool {
        $plans = $this->client->get_product_plans();
        foreach ( $order->get_items() as $item ) {
            $pid = $item->get_variation_id() ?: $item->get_product_id();
            if ( isset( $plans[ $pid ] ) ) {
                return true;
            }
        }
        $this->db->log_event(
            [
                'event'         => 'plan_missing',
                'order_id'      => $order->get_id(),
                'customer_email'=> $order->get_billing_email(),
                'error_excerpt' => __( 'No mapped plan for order products; skipping.', 'pixlab-license-bridge' ),
            ]
        );
        return false;
    }

    public function build_payload( string $subscription_id, string $event, ?\WC_Order $order = null ): ?array {
        $plan_slug            = '';
        $customer_email       = '';
        $order_id             = '';
        $product_id           = 0;
        $wp_user_id           = 0;
        $customer_name        = '';
        $subscription_status  = '';

        $subscription_order = null;

        if ( $order ) {
            $customer_email = $order->get_billing_email();
            $order_id       = $order->get_id();
            $wp_user_id     = (int) $order->get_user_id();
            $customer_name  = trim( $order->get_formatted_billing_full_name() ?: $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            foreach ( $order->get_items() as $item ) {
                $product_id = $item->get_variation_id() ?: $item->get_product_id();
                break;
            }
        } elseif ( $subscription_id ) {
            $subscription_order = wc_get_order( (int) $subscription_id );
            if ( $subscription_order instanceof \WC_Order ) {
                $order_id       = $subscription_order->get_id();
                $customer_email = $subscription_order->get_billing_email();
                $wp_user_id     = (int) $subscription_order->get_user_id();
                $customer_name  = trim( $subscription_order->get_formatted_billing_full_name() ?: $subscription_order->get_billing_first_name() . ' ' . $subscription_order->get_billing_last_name() );
                foreach ( $subscription_order->get_items() as $item ) {
                    $product_id = $item->get_variation_id() ?: $item->get_product_id();
                    break;
                }
            }
        }

        if ( ! $wp_user_id && $subscription_id ) {
            $user_id_meta = (int) get_post_meta( $subscription_id, 'user_id', true );
            if ( $user_id_meta > 0 ) {
                $wp_user_id = $user_id_meta;
            }
        }

        if ( $wp_user_id ) {
            $user = get_user_by( 'id', $wp_user_id );
            if ( $user ) {
                if ( ! $customer_email ) {
                    $customer_email = $user->user_email;
                }
                if ( ! $customer_name ) {
                    $display_name  = $user->display_name ?: trim( $user->first_name . ' ' . $user->last_name );
                    $customer_name = trim( $display_name );
                }
            }
        }

        if ( ! $customer_name && $order ) {
            $customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        }

        if ( ! $customer_email && $subscription_id && ! $subscription_order ) {
            $subscription_order = wc_get_order( (int) $subscription_id );
            if ( $subscription_order instanceof \WC_Order ) {
                $customer_email = $subscription_order->get_billing_email();
                if ( ! $order_id ) {
                    $order_id = $subscription_order->get_id();
                }
            }
        }

        $plans = $this->client->get_product_plans();
        if ( $product_id && isset( $plans[ $product_id ] ) ) {
            $plan_slug = $plans[ $product_id ];
        }

        if ( ! $plan_slug ) {
            $plan_slug = $order ? (string) $order->get_meta( '_dsb_plan_slug', true ) : '';
        }

        if ( ! $plan_slug && $subscription_id ) {
            $plan_slug = get_post_meta( $subscription_id, '_dsb_plan_slug', true );
            if ( ! $plan_slug ) {
                $plan_slug = get_post_meta( $subscription_id, 'wps_sfw_plan_slug', true );
            }
        }

        $raw_plan_slug   = $plan_slug;
        $plan_slug       = dsb_normalize_plan_slug( $plan_slug );
        $normalized_plan = $plan_slug;

        dsb_log(
            'debug',
            'Plan slug resolved',
            [
                'product_id'      => $product_id ?: null,
                'raw_plan_slug'   => $raw_plan_slug,
                'plan_slug'       => $normalized_plan,
                'plan_map_source' => isset( $plans[ $product_id ] ) ? 'product_plan_option' : 'meta',
            ]
        );

        if ( ( ! $plan_slug || ! $product_id ) ) {
            $resolved_wp_user_id = $wp_user_id;

            if ( ! $resolved_wp_user_id && $subscription_id ) {
                $user_id_meta = (int) get_post_meta( $subscription_id, 'user_id', true );
                if ( $user_id_meta > 0 ) {
                    $resolved_wp_user_id = $user_id_meta;
                }
            }

            if ( ! $resolved_wp_user_id ) {
                $current_user_id = (int) get_current_user_id();
                if ( $current_user_id > 0 ) {
                    $resolved_wp_user_id = $current_user_id;
                }
            }

            if ( $resolved_wp_user_id ) {
                $truth = $this->db->get_user_truth_by_wp_user_id( $resolved_wp_user_id );
                if ( $truth ) {
                    if ( ( ! $product_id || 0 === (int) $product_id ) && ! empty( $truth['product_id'] ) ) {
                        $product_id = (int) $truth['product_id'];
                    }

                    if ( ! $plan_slug && ! empty( $truth['plan_slug'] ) ) {
                        $plan_slug = dsb_normalize_plan_slug( $truth['plan_slug'] );
                    }

                    if ( ! $subscription_id && ! empty( $truth['subscription_id'] ) ) {
                        $subscription_id = sanitize_text_field( (string) $truth['subscription_id'] );
                    }

                    if ( ! $order_id && ! empty( $truth['order_id'] ) ) {
                        $order_id = sanitize_text_field( (string) $truth['order_id'] );
                    }

                    if ( ! $customer_email && ! empty( $truth['customer_email'] ) ) {
                        $customer_email = sanitize_email( $truth['customer_email'] );
                    }

                    if ( ! $wp_user_id ) {
                        $wp_user_id = $resolved_wp_user_id;
                    }

                    dsb_log(
                        'debug',
                        'Plan/identity backfilled from truth table',
                        [
                            'wp_user_id' => $resolved_wp_user_id,
                            'product_id' => $product_id ?: null,
                            'plan_slug'  => $plan_slug ?: null,
                        ]
                    );
                }
            }
        }

        if ( ! $plan_slug && $product_id ) {
            $this->db->log_event(
                [
                    'event'           => 'plan_missing',
                    'order_id'        => $order_id,
                    'subscription_id' => $subscription_id,
                    'customer_email'  => $customer_email,
                    'error_excerpt'   => sprintf(
                        /* translators: 1: product ID */
                        __( 'No plan mapping found for product %s.', 'pixlab-license-bridge' ),
                        $product_id
                    ),
                ]
            );
            return null;
        }

        if ( ! $customer_email && $wp_user_id ) {
            $user = get_user_by( 'id', $wp_user_id );
            if ( $user && $user->user_email ) {
                $customer_email = $user->user_email;
            }
        }

        if ( ! $customer_email && $order ) {
            $customer_email = $order->get_billing_email();
        }

        if ( ! $wp_user_id && $customer_email ) {
            $user = get_user_by( 'email', $customer_email );
            if ( $user ) {
                $wp_user_id = (int) $user->ID;
            }
        }

        if ( ! $customer_email && $subscription_id ) {
            $maybe_email = get_post_meta( $subscription_id, '_billing_email', true );
            if ( $maybe_email ) {
                $customer_email = $maybe_email;
            }
        }

        if ( ! $customer_email ) {
            dsb_log(
                'warning',
                'Customer email still missing; proceeding with wp_user_id only',
                [
                    'order_id'        => $order_id,
                    'subscription_id' => $subscription_id,
                    'wp_user_id'      => $wp_user_id ?: null,
                    'customer_email'  => $customer_email ?: null,
                ]
            );
        }

        $subscription_status = $this->resolve_subscription_status( $subscription_id, $order );

        dsb_log(
            'debug',
            'Payload identity resolved',
            [
                'subscription_id'     => $subscription_id,
                'wp_user_id'          => $wp_user_id ?: null,
                'customer_email_set'  => (bool) $customer_email,
                'customer_name_set'   => (bool) $customer_name,
                'subscription_status' => $subscription_status ?: null,
            ]
        );

        if ( ! $subscription_id ) {
            $event = 'activated';

            if ( ! $order_id ) {
                dsb_log(
                    'warning',
                    'Order ID missing for activation without subscription; payload skipped',
                    [
                        'subscription_id' => $subscription_id,
                        'event'           => $event,
                    ]
                );

                return null;
            }
        }

        $payload = [
            'event'               => $event,
            'customer_email'      => $customer_email,
            'customer_name'       => $customer_name,
            'wp_user_id'          => $wp_user_id,
            'subscription_status' => $subscription_status,
            'plan_slug'           => $plan_slug,
            'order_id'            => $order_id,
            'product_id'          => $product_id,
        ] + $this->maybe_add_validity_window( $event, $product_id, $order, $subscription_id, $customer_email, $plan_slug );

        if ( $subscription_id ) {
            $payload['subscription_id'] = $subscription_id;
        }

        if ( 'activated' === $event && $subscription_id && empty( $payload['valid_until'] ) ) {
            $this->schedule_valid_until_backfill( $subscription_id, $order );
        }

        $payload['event_id'] = DSB_Util::event_id_from_payload( $payload );

        return $payload;
    }

    protected function maybe_add_validity_window( string $event, int $product_id, ?\WC_Order $order, string $subscription_id, string $customer_email, string $plan_slug ): array {
        $eligible_events = [ 'activated', 'renewed', 'active', 'reactivated' ];
        if ( ! in_array( $event, $eligible_events, true ) ) {
            return [];
        }

        $payload    = [];
        $activation = $this->determine_activation_time( $order );
        if ( $activation ) {
            $payload['valid_from'] = $this->format_datetime_for_node( $activation );
        }

        $valid_until_dt = $this->resolve_valid_until( $event, $product_id, $order, $subscription_id, $customer_email, $plan_slug, $activation );

        if ( $valid_until_dt ) {
            $payload['valid_until'] = $this->format_datetime_for_node( $valid_until_dt );
            $this->persist_valid_until( $valid_until_dt, $subscription_id, $order, $customer_email, $plan_slug, $this->last_valid_until_source ?: 'unknown' );
        }

        dsb_log(
            'info',
            'validity window resolved',
            [
                'subscription_id'    => $subscription_id,
                'order_id'           => $order ? $order->get_id() : null,
                'event'              => $event,
                'valid_from_set'     => isset( $payload['valid_from'] ),
                'valid_until_set'    => isset( $payload['valid_until'] ),
                'valid_until_source' => $this->last_valid_until_source ?: 'unknown',
                'valid_until_value'  => $payload['valid_until'] ?? null,
            ]
        );

        return $payload;
    }

    protected function resolve_valid_until( string $event, int $product_id, ?\WC_Order $order, string $subscription_id, string $customer_email, string $plan_slug, ?\DateTimeImmutable $activation ): ?\DateTimeImmutable {
        $this->last_valid_until_source = '';

        $order_meta_valid = $order instanceof \WC_Order ? $this->parse_expiry_value( $order->get_meta( self::ORDER_META_VALID_UNTIL ) ) : null;
        if ( $order_meta_valid ) {
            $this->last_valid_until_source = 'order_meta';
            return $order_meta_valid;
        }

        $subscription_meta_valid = $subscription_id ? $this->parse_expiry_value( get_post_meta( (int) $subscription_id, '_dsb_valid_until', true ) ) : null;
        if ( $subscription_meta_valid ) {
            $this->last_valid_until_source = 'subscription_meta';
            return $subscription_meta_valid;
        }

        $wps_expiry = $this->get_wps_expiry_datetime_for_subscription( $subscription_id );
        if ( $wps_expiry ) {
            $this->last_valid_until_source = 'wps_expiry_filter';
            $this->cache_valid_until( $subscription_id, $wps_expiry, $this->last_valid_until_source );
            return $wps_expiry;
        }

        $interval = $this->get_product_expiry_interval( $product_id );
        if ( ! $interval ) {
            $this->last_valid_until_source = 'none';
            return null;
        }

        $base = $activation;
        if ( 'renewed' === $event ) {
            $existing = $this->fetch_current_validity( $subscription_id, $customer_email );
            if ( isset( $existing['valid_until'] ) ) {
                $base = $existing['valid_until'];
            } elseif ( isset( $existing['valid_from'] ) ) {
                $base = $existing['valid_from'];
            }
        }

        if ( ! $base ) {
            $base = $this->determine_activation_time( $order );
        }

        if ( ! $base ) {
            try {
                $base = ( new \DateTimeImmutable( '@' . current_time( 'timestamp', true ) ) )->setTimezone( wp_timezone() );
            } catch ( \Throwable $e ) {
                $base = null;
            }
        }

        if ( ! $base ) {
            $this->last_valid_until_source = 'none';
            return null;
        }

        try {
            $valid_until = $base->add( $interval );
        } catch ( \Throwable $e ) {
            $this->last_valid_until_source = 'none';
            return null;
        }

        $this->last_valid_until_source = 'product_interval_meta';
        $this->cache_valid_until( $subscription_id, $valid_until, $this->last_valid_until_source );

        return $valid_until;
    }

    private function get_wps_expiry_datetime_for_subscription( $subscription_id ): ?\DateTimeImmutable {
        $subscription_id = (int) $subscription_id;
        if ( $subscription_id <= 0 ) {
            return null;
        }

        $this->last_valid_until_source = '';
        $cached                        = get_post_meta( $subscription_id, '_dsb_wps_valid_until', true );
        $filter_empty                  = null;

        if ( $cached ) {
            $dt = $this->parse_expiry_value( $cached );
            if ( $dt instanceof \DateTimeInterface ) {
                $this->last_valid_until_source = 'wps_expiry_filter';
                dsb_log(
                    'info',
                    'valid_until_source = wps_expiry_filter (cache)',
                    [
                        'subscription_id' => $subscription_id,
                        'meta_present'    => true,
                        'filter_empty'    => null,
                    ]
                );
                return $dt;
            }
        }

        $expiry       = apply_filters( 'wps_sfw_susbcription_end_date', '', $subscription_id );
        $filter_empty = ( '' === $expiry || null === $expiry );
        $dt           = $this->parse_expiry_value( $expiry );

        if ( $dt instanceof \DateTimeInterface ) {
            $this->last_valid_until_source = 'wps_expiry_filter';
            dsb_log(
                'info',
                'valid_until_source = wps_expiry_filter (live)',
                [
                    'subscription_id' => $subscription_id,
                    'meta_present'    => (bool) $cached,
                    'filter_empty'    => $filter_empty,
                ]
            );
            return $dt;
        }

        $all_meta = get_post_meta( $subscription_id );
        if ( is_array( $all_meta ) ) {
            $patterns = [ 'expiry', 'expire', 'end', 'until', 'valid', 'next_payment', 'trial_end' ];

            foreach ( $all_meta as $meta_key => $values ) {
                foreach ( $patterns as $pattern ) {
                    if ( false !== stripos( (string) $meta_key, $pattern ) ) {
                        $value = is_array( $values ) ? reset( $values ) : $values;
                        $dt    = $this->parse_expiry_value( $value );
                        if ( $dt instanceof \DateTimeInterface ) {
                            $this->last_valid_until_source = 'wps_expiry_filter';
                            dsb_log(
                                'info',
                                'valid_until_source = wps_expiry_filter (meta_scan)',
                                [
                                    'subscription_id' => $subscription_id,
                                    'meta_key'        => $meta_key,
                                    'meta_present'    => (bool) $cached,
                                    'filter_empty'    => $filter_empty,
                                ]
                            );
                            return $dt;
                        }
                        break;
                    }
                }
            }
        }

        $this->last_valid_until_source = '';
        dsb_log(
            'debug',
            'valid_until_source = none',
            [
                'subscription_id'         => $subscription_id,
                'meta_present'            => (bool) $cached,
                'filter_empty'            => $filter_empty,
                'cached_meta_value_empty' => empty( $cached ),
            ]
        );

        return null;
    }

    protected function cache_valid_until( string $subscription_id, \DateTimeInterface $valid_until, string $source ): void {
        if ( ! $subscription_id ) {
            return;
        }

        try {
            $utc = $valid_until->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
            update_post_meta( (int) $subscription_id, '_dsb_wps_valid_until', $utc );
            update_post_meta( (int) $subscription_id, '_dsb_wps_valid_until_source', $source );
            update_post_meta( (int) $subscription_id, '_dsb_wps_valid_until_captured_at', current_time( 'mysql', true ) );
        } catch ( \Throwable $e ) {
            // Silence caching errors.
        }
    }

    private function persist_valid_until( ?\DateTimeInterface $valid_until, string $subscription_id, ?\WC_Order $order, string $customer_email, string $plan_slug, string $source = '' ): void {
        if ( ! $valid_until ) {
            return;
        }

        $iso = $this->format_datetime_for_node( $valid_until );

        if ( $subscription_id ) {
            update_post_meta( (int) $subscription_id, '_dsb_valid_until', $iso );
        }

        if ( $order instanceof \WC_Order ) {
            $order->update_meta_data( self::ORDER_META_VALID_UNTIL, $iso );
            $order->save();
        }

        try {
            $mysql = $valid_until->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        } catch ( \Throwable $e ) {
            $mysql = null;
        }

        $email     = $customer_email ?: ( $order instanceof \WC_Order ? $order->get_billing_email() : '' );
        $wp_user   = $order instanceof \WC_Order ? $order->get_user_id() : null;
        $plan_slug = $plan_slug ? dsb_normalize_plan_slug( $plan_slug ) : '';

        if ( $mysql ) {
            $this->db->upsert_key(
                [
                    'subscription_id' => $subscription_id,
                    'customer_email'  => $email,
                    'wp_user_id'      => $wp_user ?: null,
                    'plan_slug'       => $plan_slug,
                    'valid_until'     => $mysql,
                ]
            );

            $key_row = $subscription_id ? $this->db->get_key_by_subscription_id( $subscription_id ) : null;
            if ( $key_row ) {
                dsb_log(
                    'info',
                    'Backfilled key valid_until from ' . ( $source ?: 'validity_resolution' ),
                    [
                        'subscription_id' => $subscription_id,
                        'key_id'          => $key_row['id'] ?? null,
                        'valid_until'     => $iso,
                    ]
                );
            }
        }
    }

    private function parse_expiry_value( $value ): ?\DateTimeImmutable {
        if ( empty( $value ) && '0' !== $value ) {
            return null;
        }

        try {
            if ( is_numeric( $value ) ) {
                $dt = new \DateTimeImmutable( '@' . (int) $value );
                return $dt->setTimezone( wp_timezone() );
            }

            if ( is_array( $value ) ) {
                $value = reset( $value );
            }

            if ( is_string( $value ) ) {
                $dt = new \DateTimeImmutable( $value );
                return $dt;
            }
        } catch ( \Throwable $e ) {
            return null;
        }

        return null;
    }

    private function normalize_mysql_datetime( $value ): ?string {
        $dt = $this->parse_expiry_value( $value );
        if ( ! $dt ) {
            return null;
        }

        try {
            return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    protected function get_product_expiry_interval( int $product_id ): ?\DateInterval {
        if ( $product_id <= 0 ) {
            return null;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product instanceof \WC_Product ) {
            return null;
        }

        $value_keys = [
            'wp_sw_subscription_expiry_number',
            'wp_sw_subscription_number',
            'wps_sfw_subscription_expiry_interval',
            'wps_sfw_subscription_expiry_value',
            'wps_sfw_subs_expiry_interval',
            '_subscription_expiry_interval',
            'wps_sfw_expiry_interval',
        ];
        $unit_keys  = [
            'wp_sw_subscription_expiry_interval',
            'wp_sw_subscription_interval',
            'wps_sfw_subscription_expiry_interval_type',
            'wps_sfw_subscription_expiry_unit',
            'wps_sfw_subs_expiry_interval_type',
            '_subscription_expiry_period',
            'wps_sfw_subscription_expiry_type',
            'wps_sfw_expiry_unit',
        ];

        $interval_value = null;
        foreach ( $value_keys as $key ) {
            $value = $product->get_meta( $key, true );
            if ( '' !== $value && null !== $value && is_numeric( $value ) ) {
                $interval_value = (int) $value;
                break;
            }
        }

        if ( ! $interval_value || $interval_value <= 0 ) {
            return null;
        }

        $unit = '';
        foreach ( $unit_keys as $key ) {
            $candidate = $product->get_meta( $key, true );
            if ( $candidate ) {
                $unit = $this->normalize_interval_unit( (string) $candidate );
                if ( $unit ) {
                    break;
                }
            }
        }

        if ( ! $unit ) {
            $unit = $this->normalize_interval_unit( $this->detect_billing_period( $product ) );
        }

        if ( ! $unit ) {
            return null;
        }

        try {
            switch ( $unit ) {
                case 'day':
                    return new \DateInterval( 'P' . $interval_value . 'D' );
                case 'week':
                    return new \DateInterval( 'P' . $interval_value . 'W' );
                case 'month':
                    return new \DateInterval( 'P' . $interval_value . 'M' );
                case 'year':
                    return new \DateInterval( 'P' . $interval_value . 'Y' );
                default:
                    return null;
            }
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    protected function normalize_interval_unit( string $unit ): string {
        $unit = strtolower( trim( $unit ) );
        switch ( $unit ) {
            case 'day':
            case 'days':
                return 'day';
            case 'week':
            case 'weeks':
                return 'week';
            case 'month':
            case 'months':
            case 'monthly':
                return 'month';
            case 'year':
            case 'years':
            case 'yearly':
            case 'annually':
                return 'year';
            default:
                return '';
        }
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
                return 'month';
            }
            if ( in_array( $period, [ 'year', 'yearly', 'annual', 'annually' ], true ) ) {
                return 'year';
            }
        }

        return '';
    }

    protected function determine_activation_time( ?\WC_Order $order ): ?\DateTimeImmutable {
        if ( $order ) {
            $dt_candidates = [ $order->get_date_completed(), $order->get_date_paid(), $order->get_date_created() ];
            foreach ( $dt_candidates as $dt ) {
                if ( $dt instanceof \WC_DateTime ) {
                    return ( new \DateTimeImmutable( '@' . $dt->getTimestamp() ) )->setTimezone( wp_timezone() );
                }
            }
        }

        try {
            return new \DateTimeImmutable( 'now', wp_timezone() );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    protected function format_datetime_for_node( \DateTimeInterface $dt ): string {
        return DSB_Util::to_iso_utc( $dt );
    }

    protected function parse_datetime_string( $value ): ?\DateTimeImmutable {
        if ( empty( $value ) ) {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable( is_string( $value ) ? $value : '' );
            return $dt;
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    protected function fetch_current_validity( string $subscription_id, string $customer_email ): array {
        if ( '' === $subscription_id && '' === $customer_email ) {
            return [];
        }

        $identity = [];
        if ( $subscription_id ) {
            $identity['subscription_id'] = $subscription_id;
        }
        if ( $customer_email ) {
            $identity['customer_email'] = $customer_email;
        }

        $result = $this->client->user_summary( $identity );
        if ( is_wp_error( $result['response'] ?? null ) || 200 !== ( $result['code'] ?? 0 ) || ( $result['decoded']['status'] ?? '' ) !== 'ok' ) {
            return [];
        }

        $key = $result['decoded']['key'] ?? $result['decoded'];
        $valid_from  = $this->parse_datetime_string( $key['valid_from'] ?? null );
        $valid_until = $this->parse_datetime_string( $key['valid_until'] ?? null );

        $payload = [];
        if ( $valid_from ) {
            $payload['valid_from'] = $valid_from;
        }
        if ( $valid_until ) {
            $payload['valid_until'] = $valid_until;
        }

        return $payload;
    }
}

/*
 * Test checklist:
 * 1) Place subscription, confirm initial activated send.
 * 2) Confirm filter capture log appears at least once when viewing subscriptions list OR when WPS calculates expiry.
 * 3) Confirm _dsb_wps_valid_until meta is set on subscription.
 * 4) Confirm second “activated” send is allowed only if key.valid_until is NULL and payload has valid_until.
 * 5) Confirm davix_bridge_keys.valid_until becomes non-NULL.
 */
