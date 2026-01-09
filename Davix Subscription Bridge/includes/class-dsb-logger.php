<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit();

/**
 * Lightweight file-based logger for Davix Subscription Bridge.
 */

function dsb_get_log_settings(): array {
    $options = get_option( DSB_Client::OPTION_SETTINGS, [] );

    return [
        'enabled'         => ! empty( $options['debug_enabled'] ),
        'level'           => isset( $options['debug_level'] ) ? sanitize_key( $options['debug_level'] ) : 'info',
        'retention_days'  => isset( $options['debug_retention_days'] ) ? max( 1, (int) $options['debug_retention_days'] ) : 7,
    ];
}

function dsb_debug_is_enabled(): bool {
    $settings = dsb_get_log_settings();
    return ! empty( $settings['enabled'] );
}

function dsb_get_log_dir(): string {
    $stored = get_option( 'dsb_log_dir_path' );
    if ( is_string( $stored ) && '' !== $stored ) {
        return trailingslashit( $stored );
    }

    $doc_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( (string) $_SERVER['DOCUMENT_ROOT'] ) : '';
    $content_dir = trailingslashit( WP_CONTENT_DIR ) . 'davix-bridge-logs/';
    $above_docroot = $doc_root ? trailingslashit( dirname( $doc_root ) ) . 'davix-bridge-logs/' : '';

    if ( $doc_root && $above_docroot && 0 !== strpos( $above_docroot, $doc_root ) ) {
        return $above_docroot;
    }

    return $content_dir;
}

function dsb_ensure_log_dir(): bool {
    $dir = dsb_get_log_dir();
    if ( ! wp_mkdir_p( $dir ) ) {
        $dir = dsb_get_uploads_log_dir();
        if ( ! wp_mkdir_p( $dir ) ) {
            return false;
        }
    }

    update_option( 'dsb_log_dir_path', untrailingslashit( $dir ), false );

    // Prevent directory listing.
    $index_file = trailingslashit( $dir ) . 'index.php';
    if ( ! file_exists( $index_file ) ) {
        @file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
    }

    // Best-effort .htaccess hardening for Apache hosts.
    $htaccess = trailingslashit( $dir ) . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        @file_put_contents( $htaccess, "Deny from all\n" );
    }

    // Best-effort web.config hardening for IIS hosts.
    $web_config = trailingslashit( $dir ) . 'web.config';
    if ( ! file_exists( $web_config ) ) {
        @file_put_contents(
            $web_config,
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n"
        );
    }

    return is_dir( $dir );
}

function dsb_get_uploads_log_dir(): string {
    $uploads = wp_upload_dir();
    $base = trailingslashit( $uploads['basedir'] ) . 'davix-bridge-logs/';
    $token = get_option( 'dsb_log_upload_token' );
    if ( ! is_string( $token ) || '' === $token ) {
        $token = wp_generate_password( 12, false, false );
        update_option( 'dsb_log_upload_token', $token, false );
    }

    return trailingslashit( $base . $token );
}

function dsb_get_log_file_path(): string {
    $dir  = dsb_get_log_dir();
    $date = gmdate( 'Y-m-d' );
    return $dir . 'dsb-' . $date . '.log';
}

function dsb_get_latest_log_file(): ?string {
    $dir = dsb_get_log_dir();
    if ( ! is_dir( $dir ) ) {
        return null;
    }

    $files = glob( $dir . 'dsb-*.log' );
    if ( empty( $files ) ) {
        $fallback = $dir . 'dsb.log';
        return file_exists( $fallback ) ? $fallback : null;
    }

    rsort( $files );
    return $files[0];
}

function dsb_mask_string( string $value ): string {
    // Replace common token patterns within strings.
    $patterns = [
        '/(token=)([^&#\s]+)/i',
        '/(api[_-]?key=)([^&#\s]+)/i',
        '/(authorization=)([^&#\s]+)/i',
        '/(bearer\s+)([A-Za-z0-9\-\._]+)/i',
    ];
    foreach ( $patterns as $pattern ) {
        $value = preg_replace( $pattern, '$1***', $value );
    }

    // Mask long key-like strings.
    if ( preg_match( '/[A-Za-z0-9\-_]{24,}/', $value ) ) {
        return substr( $value, 0, 3 ) . '***';
    }

    return $value;
}

function dsb_mask_secrets( $context ) {
    $sensitive_keys = [ 'token', 'api_key', 'apikey', 'authorization', 'bearer', 'secret', 'password', 'bridge_token' ];

    if ( is_array( $context ) ) {
        $sanitized = [];
        foreach ( $context as $key => $value ) {
            $lower = strtolower( (string) $key );
            if ( in_array( $lower, $sensitive_keys, true ) ) {
                $sanitized[ $key ] = '***';
                continue;
            }

            if ( is_array( $value ) || is_object( $value ) ) {
                $sanitized[ $key ] = dsb_mask_secrets( (array) $value );
            } elseif ( is_string( $value ) ) {
                $sanitized[ $key ] = dsb_mask_string( $value );
            } else {
                $sanitized[ $key ] = $value;
            }
        }
        return $sanitized;
    }

    if ( is_string( $context ) ) {
        return dsb_mask_string( $context );
    }

    return $context;
}

function dsb_should_log( string $level ): bool {
    if ( ! dsb_debug_is_enabled() ) {
        return false;
    }

    $settings = dsb_get_log_settings();

    $map = [
        'debug' => 10,
        'info'  => 20,
        'warn'  => 30,
        'error' => 40,
    ];

    $threshold = $map[ $settings['level'] ] ?? 20;
    $incoming  = $map[ strtolower( $level ) ] ?? 40;

    return $incoming >= $threshold;
}

function dsb_prune_logs( int $retention_days ): void {
    $dir = dsb_get_log_dir();
    if ( ! is_dir( $dir ) ) {
        return;
    }

    $cutoff = strtotime( '-' . max( 1, $retention_days ) . ' days' );
    foreach ( glob( $dir . 'dsb-*.log' ) as $file ) {
        if ( @filemtime( $file ) < $cutoff ) {
            @unlink( $file );
        }
    }
}

function dsb_log( $level, $message, array $context = [] ): void {
    $level = strtolower( (string) $level );
    if ( ! dsb_debug_is_enabled() ) {
        return;
    }
    if ( ! dsb_should_log( $level ) ) {
        return;
    }

    if ( ! dsb_ensure_log_dir() ) {
        return;
    }

    $extra = [
        'user_id'        => get_current_user_id() ?: null,
        'page'           => isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : null,
        'tab'            => isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : null,
        'plugin_version' => defined( 'DSB_VERSION' ) ? DSB_VERSION : null,
        'request_uri'    => isset( $_SERVER['REQUEST_URI'] ) ? dsb_mask_string( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : null,
    ];

    $context = dsb_mask_secrets( array_merge( $extra, $context ) );

    $timestamp = gmdate( 'c' );
    $line      = sprintf(
        '[%s] [%s] %s %s',
        $timestamp,
        $level,
        wp_strip_all_tags( (string) $message ),
        wp_json_encode( $context, JSON_UNESCAPED_SLASHES )
    );

    $file = dsb_get_log_file_path();
    try {
        file_put_contents( $file, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
        $settings = dsb_get_log_settings();
        dsb_prune_logs( $settings['retention_days'] );
    } catch ( \Throwable $e ) {
        // Never break admin pages due to logging issues.
        return;
    }
}

function dsb_get_log_tail( int $lines = 200 ): string {
    $file = dsb_get_latest_log_file();
    if ( ! $file || ! file_exists( $file ) ) {
        return '';
    }

    $lines = max( 1, $lines );
    $content = '';

    try {
        $fh = new \SplFileObject( $file, 'r' );
        $fh->seek( PHP_INT_MAX );
        $last_line = $fh->key();
        $start     = max( 0, $last_line - $lines + 1 );
        $fh->seek( $start );
        while ( ! $fh->eof() ) {
            $content .= (string) $fh->current();
            $fh->next();
        }
    } catch ( \Throwable $e ) {
        $content = (string) wp_trim_words( file_get_contents( $file ), $lines, '' );
    }

    return $content;
}

function dsb_clear_logs(): void {
    dsb_delete_all_logs();
}

function dsb_delete_all_logs(): void {
    $dir = dsb_get_log_dir();
    if ( ! is_dir( $dir ) ) {
        return;
    }

    foreach ( glob( $dir . 'dsb-*.log' ) as $file ) {
        @unlink( $file );
    }

    $legacy = $dir . 'dsb.log';
    if ( file_exists( $legacy ) ) {
        @unlink( $legacy );
    }

    $index    = trailingslashit( $dir ) . 'index.php';
    $htaccess = trailingslashit( $dir ) . '.htaccess';

    if ( file_exists( $index ) && is_file( $index ) && count( glob( $dir . '*', GLOB_NOSORT ) ) === 1 ) {
        @unlink( $index );
    }
    if ( file_exists( $htaccess ) && is_file( $htaccess ) ) {
        @unlink( $htaccess );
    }

    $remaining = glob( $dir . '*', GLOB_NOSORT );
    if ( empty( $remaining ) ) {
        @rmdir( $dir );
    }
}

function dsb_handle_js_log(): void {
    if ( ! dsb_debug_is_enabled() ) {
        wp_send_json_error( [ 'message' => 'debug_disabled' ], 403 );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
    }

    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'dsb_js_log' ) ) {
        wp_send_json_error( [ 'message' => 'invalid_nonce' ], 403 );
    }

    $message = isset( $_POST['message'] ) ? wp_strip_all_tags( wp_unslash( (string) $_POST['message'] ) ) : '';
    if ( strlen( $message ) > 2000 ) {
        wp_send_json_success( [ 'ignored' => true, 'reason' => 'too_long' ] );
    }

    $user_id = get_current_user_id() ?: 'guest';
    $bucket  = gmdate( 'YmdHi' );
    $key     = 'dsb_js_log_count_' . $user_id . '_' . $bucket;
    $count   = (int) get_transient( $key );
    if ( $count >= 30 ) {
        wp_send_json_success( [ 'ignored' => true, 'reason' => 'rate_limited' ] );
    }
    set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );

    $level    = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : 'info';
    $allowed  = [ 'debug', 'info', 'warn', 'error' ];
    $level    = in_array( $level, $allowed, true ) ? $level : 'info';
    $context  = [];

    if ( ! empty( $_POST['context'] ) ) {
        $raw = wp_unslash( $_POST['context'] );
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $context = $decoded;
            }
        } elseif ( is_array( $raw ) ) {
            $context = $raw;
        }
    }

    $sanitize_recursive = static function ( $value ) use ( &$sanitize_recursive ) {
        if ( is_array( $value ) ) {
            $clean = [];
            foreach ( $value as $key => $item ) {
                $clean[ sanitize_key( $key ) ] = $sanitize_recursive( $item );
            }
            return $clean;
        }

        if ( is_scalar( $value ) ) {
            $string = wp_strip_all_tags( (string) $value );
            return substr( $string, 0, 500 );
        }

        return null;
    };

    $clean_context = dsb_mask_secrets( $sanitize_recursive( $context ) );

    dsb_log( $level, 'JS: ' . $message, [ 'js_context' => $clean_context ] );

    wp_send_json_success( [ 'logged' => true ] );
}
