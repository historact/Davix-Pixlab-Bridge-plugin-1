<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Util {
    /**
     * Convert a date/time value to ISO8601 in UTC (Z).
     *
     * @param string|int|\DateTimeInterface $value Input value to convert.
     */
    public static function to_iso_utc( $value ): ?string {
        if ( $value instanceof \DateTimeInterface ) {
            $timestamp = $value->getTimestamp();
        } elseif ( is_numeric( $value ) ) {
            $timestamp = (int) $value;
        } elseif ( is_string( $value ) ) {
            $trimmed = trim( $value );
            if ( '' === $trimmed ) {
                return null;
            }

            try {
                $dt        = new \DateTimeImmutable( $trimmed, wp_timezone() );
                $timestamp = $dt->getTimestamp();
            } catch ( \Throwable $e ) {
                return null;
            }
        } else {
            return null;
        }

        return gmdate( 'c', $timestamp );
    }

    /**
     * Generate a deterministic event ID for subscription lifecycle payloads.
     *
     * @param array $payload Event payload.
     */
    public static function event_id_from_payload( array $payload ): string {
        $event              = isset( $payload['event'] ) ? trim( (string) $payload['event'] ) : '';
        $subscription_status = isset( $payload['subscription_status'] ) ? trim( (string) $payload['subscription_status'] ) : '';
        $subscription_id    = isset( $payload['subscription_id'] ) ? trim( (string) $payload['subscription_id'] ) : '';
        $order_id           = isset( $payload['order_id'] ) ? trim( (string) $payload['order_id'] ) : '';
        $wp_user_id         = isset( $payload['wp_user_id'] ) ? trim( (string) $payload['wp_user_id'] ) : '';
        $customer_email     = isset( $payload['customer_email'] ) ? strtolower( trim( (string) $payload['customer_email'] ) ) : '';
        $plan_slug          = isset( $payload['plan_slug'] ) ? trim( (string) $payload['plan_slug'] ) : '';
        $valid_from         = isset( $payload['valid_from'] ) ? trim( (string) $payload['valid_from'] ) : '';
        $valid_until        = isset( $payload['valid_until'] ) ? trim( (string) $payload['valid_until'] ) : '';
        $event_patch        = isset( $payload['event_patch'] ) ? trim( (string) $payload['event_patch'] ) : '';

        $canonical = 'dsb|v1|' . implode( '|', [
            $event,
            $subscription_status,
            $subscription_id,
            $order_id,
            $wp_user_id,
            $customer_email,
            $plan_slug,
            $valid_from,
            $valid_until,
            $event_patch,
        ] );

        return hash( 'sha256', $canonical );
    }
}

/**
 * Normalize a plan slug while preserving hyphens.
 */
function dsb_normalize_plan_slug( $raw ): string {
    if ( is_array( $raw ) || is_object( $raw ) ) {
        $raw = (string) wp_json_encode( $raw );
    }

    $slug = strtolower( trim( (string) $raw ) );
    $slug = preg_replace( '/[\s_]+/', '-', $slug );
    $slug = preg_replace( '/[^a-z0-9\-]+/', '', $slug );
    $slug = preg_replace( '/-+/', '-', $slug );
    $slug = trim( $slug, '-' );

    return $slug;
}
