<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Cron_Logger {
    const MAX_FILE_BYTES = 2 * 1024 * 1024; // 2MB

    public static function get_dir(): string {
        $uploads = wp_upload_dir();
        return trailingslashit( $uploads['basedir'] ) . 'davix-bridge-cron-logs/';
    }

    public static function ensure_dir(): bool {
        $dir = self::get_dir();
        if ( ! wp_mkdir_p( $dir ) ) {
            return false;
        }

        $index = trailingslashit( $dir ) . 'index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }

        $htaccess = trailingslashit( $dir ) . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Deny from all\n" );
        }

        $web_config = trailingslashit( $dir ) . 'web.config';
        if ( ! file_exists( $web_config ) ) {
            @file_put_contents(
                $web_config,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n"
            );
        }

        return is_dir( $dir );
    }

    public static function get_file( string $job ): string {
        return self::get_dir() . 'cron-' . sanitize_key( $job ) . '.log';
    }

    public static function clear( string $job ): void {
        $file = self::get_file( $job );
        if ( file_exists( $file ) ) {
            @unlink( $file );
        }
    }

    public static function log( string $job, string $message, array $context = [] ): void {
        $settings = get_option( DSB_Client::OPTION_SETTINGS, [] );
        if ( empty( $settings[ 'enable_cron_debug_' . $job ] ) ) {
            return;
        }

        if ( ! self::ensure_dir() ) {
            return;
        }

        if ( function_exists( 'dsb_mask_secrets' ) ) {
            $context = dsb_mask_secrets( $context );
        } else {
            $context = self::mask_secrets_fallback( $context );
        }
        $line    = sprintf(
            '[%s] %s %s',
            gmdate( 'c' ),
            wp_strip_all_tags( $message ),
            wp_json_encode( $context, JSON_UNESCAPED_SLASHES )
        );

        $file = self::get_file( $job );

        try {
            file_put_contents( $file, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
            self::truncate_if_needed( $file );
        } catch ( \Throwable $e ) {
            return;
        }
    }

    public static function tail( string $job, int $lines = 200 ): string {
        $file = self::get_file( $job );
        if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
            return '';
        }

        $lines  = max( 1, $lines );
        $buffer = [];

        $fp = fopen( $file, 'r' );
        if ( ! $fp ) {
            return '';
        }

        fseek( $fp, 0, SEEK_END );
        $position = ftell( $fp );
        $chunk    = '';

        while ( $position >= 0 && count( $buffer ) < $lines ) {
            $seek = max( 0, $position - 2048 );
            $read = $position - $seek;
            fseek( $fp, $seek );
            $chunk = fread( $fp, $read ) . $chunk;
            $position = $seek - 1;
            $parts = explode( "\n", $chunk );
            $chunk = array_shift( $parts );
            $buffer = array_merge( $parts, $buffer );
        }

        fclose( $fp );

        $buffer = array_slice( array_filter( $buffer, 'strlen' ), -1 * $lines );

        return implode( "\n", $buffer );
    }

    protected static function truncate_if_needed( string $file ): void {
        if ( ! file_exists( $file ) ) {
            return;
        }

        $size = filesize( $file );
        if ( $size === false || $size <= self::MAX_FILE_BYTES ) {
            return;
        }

        $content = self::tail_from_file( $file, 400 );
        if ( '' === $content ) {
            return;
        }

        file_put_contents( $file, $content . PHP_EOL );
    }

    protected static function tail_from_file( string $file, int $lines ): string {
        $lines  = max( 1, $lines );
        $buffer = [];

        $fp = fopen( $file, 'r' );
        if ( ! $fp ) {
            return '';
        }

        fseek( $fp, 0, SEEK_END );
        $position = ftell( $fp );
        $chunk    = '';

        while ( $position >= 0 && count( $buffer ) < $lines ) {
            $seek = max( 0, $position - 4096 );
            $read = $position - $seek;
            fseek( $fp, $seek );
            $chunk = fread( $fp, $read ) . $chunk;
            $position = $seek - 1;
            $parts = explode( "\n", $chunk );
            $chunk = array_shift( $parts );
            $buffer = array_merge( $parts, $buffer );
        }

        fclose( $fp );

        $buffer = array_slice( array_filter( $buffer, 'strlen' ), -1 * $lines );

        return implode( "\n", $buffer );
    }

    private static function mask_secrets_fallback( $context ) {
        if ( ! is_array( $context ) ) {
            return $context;
        }

        $masked = [];
        foreach ( $context as $key => $value ) {
            if ( is_array( $value ) ) {
                $masked[ $key ] = self::mask_secrets_fallback( $value );
                continue;
            }

            if ( self::should_mask_key( (string) $key ) ) {
                $masked[ $key ] = self::mask_value( $value );
            } else {
                $masked[ $key ] = $value;
            }
        }

        return $masked;
    }

    private static function should_mask_key( string $key ): bool {
        $needles = [ 'token', 'key', 'secret', 'auth', 'authorization', 'bridge' ];
        foreach ( $needles as $needle ) {
            if ( false !== stripos( $key, $needle ) ) {
                return true;
            }
        }

        return false;
    }

    private static function mask_value( $value ): string {
        $string = is_scalar( $value ) ? (string) $value : '';
        $length = strlen( $string );

        if ( $length > 10 ) {
            return substr( $string, 0, 4 ) . '***' . substr( $string, -4 );
        }

        return '***';
    }
}
