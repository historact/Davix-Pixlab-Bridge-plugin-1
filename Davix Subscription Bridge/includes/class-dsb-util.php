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
