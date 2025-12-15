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
