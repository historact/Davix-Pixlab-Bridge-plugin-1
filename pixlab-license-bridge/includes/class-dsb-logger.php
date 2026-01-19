<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit();

/**
 * Lightweight file-based logger for PixLab License Bridge.
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

function dsb_is_production_env(): bool {
    if ( defined( 'WP_ENV' ) && 'production' === WP_ENV ) {
        return true;
    }

    $env = getenv( 'WP_ENV' );
    return is_string( $env ) && 'production' === $env;
}

function dsb_is_log_path_public( string $path ): bool {
    if ( '' === $path ) {
        return true;
    }

    $resolved   = realpath( $path );
    $normalized = wp_normalize_path( $resolved ? $resolved : $path );
    $normalized = trailingslashit( $normalized );

    $roots = [
        ABSPATH,
        WP_CONTENT_DIR,
    ];

    if ( isset( $_SERVER['DOCUMENT_ROOT'] ) && $_SERVER['DOCUMENT_ROOT'] ) {
        $roots[] = (string) $_SERVER['DOCUMENT_ROOT'];
    }

    $uploads = wp_upload_dir();
    if ( ! empty( $uploads['basedir'] ) ) {
        $roots[] = $uploads['basedir'];
    }

    foreach ( $roots as $root ) {
        if ( ! $root ) {
            continue;
        }
        $root_resolved   = realpath( $root );
        $root_normalized = wp_normalize_path( $root_resolved ? $root_resolved : $root );
        $root_normalized = trailingslashit( $root_normalized );
        if ( 0 === strpos( $normalized, $root_normalized ) ) {
            return true;
        }
    }

    return false;
}

function dsb_get_log_dir(): string {
    $stored = get_option( 'dsb_log_dir_path' );
    if ( is_string( $stored ) && '' !== $stored ) {
        return trailingslashit( $stored );
    }

    $doc_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( (string) $_SERVER['DOCUMENT_ROOT'] ) : '';
    $content_dir = trailingslashit( dirname( ABSPATH ) ) . 'pixlab-license-bridge-logs/';
    $above_docroot = $doc_root ? trailingslashit( dirname( $doc_root ) ) . 'pixlab-license-bridge-logs/' : '';

    if ( $doc_root && $above_docroot && 0 !== strpos( $above_docroot, $doc_root ) ) {
        $parent = dirname( untrailingslashit( $above_docroot ) );
        if ( is_dir( $above_docroot ) || ( is_dir( $parent ) && is_writable( $parent ) ) ) {
            return $above_docroot;
        }
    }

    return $content_dir;
}

function dsb_ensure_log_dir(): bool {
    $dir = dsb_get_log_dir();
    if ( dsb_is_production_env() && dsb_is_log_path_public( $dir ) ) {
        return false;
    }
    if ( ! wp_mkdir_p( $dir ) ) {
        $dir = dsb_get_uploads_log_dir();
        if ( dsb_is_production_env() && dsb_is_log_path_public( $dir ) ) {
            return false;
        }
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
    $base = trailingslashit( $uploads['basedir'] ) . 'pixlab-license-bridge-logs/';
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

function dsb_get_alert_log_dir(): string {
    $base = dsb_get_log_dir();
    return trailingslashit( $base ) . 'alerts/';
}

function dsb_ensure_alert_log_dir(): bool {
    $dir = dsb_get_alert_log_dir();
    if ( dsb_is_production_env() && dsb_is_log_path_public( $dir ) ) {
        return false;
    }

    if ( ! dsb_ensure_log_dir() ) {
        return false;
    }

    if ( ! wp_mkdir_p( $dir ) ) {
        return false;
    }

    $index_file = trailingslashit( $dir ) . 'index.php';
    if ( ! file_exists( $index_file ) ) {
        @file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
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

function dsb_get_alert_log_file(): string {
    return dsb_get_alert_log_dir() . 'alerts.log';
}

function dsb_alert_log_write( array $record ): void {
    if ( ! dsb_ensure_alert_log_dir() ) {
        return;
    }

    $payload = [
        'ts'       => isset( $record['ts'] ) ? (string) $record['ts'] : gmdate( 'c' ),
        'channel'  => isset( $record['channel'] ) ? sanitize_key( (string) $record['channel'] ) : '',
        'severity' => isset( $record['severity'] ) ? sanitize_key( (string) $record['severity'] ) : 'info',
        'code'     => isset( $record['code'] ) ? sanitize_text_field( (string) $record['code'] ) : '',
        'message'  => isset( $record['message'] ) ? wp_strip_all_tags( (string) $record['message'] ) : '',
        'status'   => isset( $record['status'] ) ? sanitize_key( (string) $record['status'] ) : '',
        'error'    => isset( $record['error'] ) ? wp_strip_all_tags( (string) $record['error'] ) : '',
    ];

    $context = isset( $record['context'] ) && is_array( $record['context'] ) ? $record['context'] : [];
    if ( $context ) {
        $payload['context'] = $context;
    }

    if ( function_exists( 'dsb_mask_secrets' ) ) {
        $payload = dsb_mask_secrets( $payload );
        if ( isset( $payload['context'] ) && is_array( $payload['context'] ) ) {
            $payload['context'] = dsb_mask_secrets( $payload['context'] );
        }
    }

    if ( function_exists( 'dsb_mask_string' ) ) {
        if ( isset( $payload['message'] ) && is_string( $payload['message'] ) ) {
            $payload['message'] = dsb_mask_string( $payload['message'] );
        }
        if ( isset( $payload['error'] ) && is_string( $payload['error'] ) ) {
            $payload['error'] = dsb_mask_string( $payload['error'] );
        }
    }

    $file = dsb_get_alert_log_file();
    try {
        file_put_contents( $file, wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) . PHP_EOL, FILE_APPEND | LOCK_EX );
    } catch ( \Throwable $e ) {
        return;
    }
}

function dsb_alert_log_tail( int $max_lines = 50 ): array {
    $file = dsb_get_alert_log_file();
    if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
        return [];
    }

    $max_lines = max( 1, $max_lines );
    $records = [];

    try {
        $fh = new \SplFileObject( $file, 'r' );
        $fh->seek( PHP_INT_MAX );
        $last_line = $fh->key();
        $start     = max( 0, $last_line - $max_lines + 1 );
        $fh->seek( $start );
        while ( ! $fh->eof() ) {
            $line = trim( (string) $fh->current() );
            $fh->next();
            if ( '' === $line ) {
                continue;
            }
            $decoded = json_decode( $line, true );
            if ( is_array( $decoded ) ) {
                $records[] = $decoded;
            }
        }
    } catch ( \Throwable $e ) {
        return [];
    }

    return array_reverse( $records );
}

function dsb_alert_log_clear(): void {
    $file = dsb_get_alert_log_file();
    if ( file_exists( $file ) ) {
        if ( ! @unlink( $file ) ) {
            @file_put_contents( $file, '' );
        }
    }
}

function dsb_alert_log_download(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Unauthorized', 'pixlab-license-bridge' ) );
    }

    if ( ! isset( $_POST['dsb_download_alerts_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dsb_download_alerts_nonce'] ) ), 'dsb_download_alerts' ) ) {
        wp_die( esc_html__( 'Invalid nonce.', 'pixlab-license-bridge' ) );
    }

    $file = dsb_get_alert_log_file();
    if ( ! file_exists( $file ) ) {
        nocache_headers();
        header( 'Content-Type: text/plain' );
        header( 'Content-Disposition: attachment; filename="pixlab-alerts.log"' );
        echo '';
        exit;
    }

    nocache_headers();
    header( 'Content-Type: text/plain' );
    header( 'Content-Disposition: attachment; filename="pixlab-alerts.log"' );
    readfile( $file );
    exit;
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
        '/([?&](?:token|api[_-]?key|authorization|auth|signature|key)=)([^&#\s]+)/i',
        '/(bearer\s+)([A-Za-z0-9\-\._]+)/i',
    ];
    foreach ( $patterns as $pattern ) {
        $value = preg_replace( $pattern, '$1***', $value );
    }

    $value = preg_replace_callback(
        '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
        static function ( array $matches ): string {
            return dsb_mask_email( $matches[0] );
        },
        $value
    );

    $value = preg_replace_callback(
        '/\b\d{8,}\b/',
        static function ( array $matches ): string {
            return dsb_mask_identifier( $matches[0] );
        },
        $value
    );

    $value = preg_replace_callback(
        '/\b(?=[A-Za-z0-9]{10,}\b)(?=.*\d)[A-Za-z0-9]+\b/',
        static function ( array $matches ): string {
            return dsb_mask_identifier( $matches[0] );
        },
        $value
    );

    // Mask long key-like strings.
    if ( preg_match( '/[A-Za-z0-9\-_]{24,}/', $value ) ) {
        return substr( $value, 0, 3 ) . '***';
    }

    return $value;
}

function dsb_mask_email( string $email ): string {
    $email = sanitize_email( $email );
    if ( ! $email ) {
        return '';
    }
    $parts = explode( '@', $email, 2 );
    $local = $parts[0] ?? '';
    $domain = $parts[1] ?? '';
    $prefix = substr( $local, 0, 2 );
    return $prefix . '***' . ( $domain ? '@' . $domain : '' );
}

function dsb_mask_identifier( string $value, int $keep = 4 ): string {
    $value = trim( (string) $value );
    if ( '' === $value ) {
        return '';
    }
    $len = strlen( $value );
    if ( $len <= $keep ) {
        return str_repeat( '*', $len );
    }
    return '***' . substr( $value, -1 * $keep );
}

function dsb_mask_chat_id( string $value ): string {
    $value = preg_replace( '/\s+/', '', (string) $value );
    return dsb_mask_identifier( $value, 4 );
}

// Masks tokens, auth headers, secrets, emails, chat IDs, and long identifiers before logging.
function dsb_mask_secrets( $context ) {
    $sensitive_keys = [ 'token', 'api_key', 'apikey', 'authorization', 'bearer', 'secret', 'password', 'bridge_token', 'cookie' ];

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
                if ( false !== strpos( $lower, 'email' ) ) {
                    $sanitized[ $key ] = dsb_mask_email( $value );
                } elseif ( false !== strpos( $lower, 'subscription' ) || false !== strpos( $lower, 'order' ) || false !== strpos( $lower, 'api_key' ) || false !== strpos( $lower, 'identifier' ) ) {
                    $sanitized[ $key ] = dsb_mask_identifier( $value );
                } elseif ( false !== strpos( $lower, 'telegram' ) || false !== strpos( $lower, 'chat' ) ) {
                    $sanitized[ $key ] = dsb_mask_chat_id( $value );
                } else {
                    $sanitized[ $key ] = dsb_mask_string( $value );
                }
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

function dsb_apply_tokens( string $text, array $ctx = [] ): string {
    $text = (string) $text;
    if ( '' === $text ) {
        return '';
    }

    static $plugin_name = null;
    static $plugin_version = null;
    if ( null === $plugin_name || null === $plugin_version ) {
        $plugin_name = 'PixLab License Bridge';
        $plugin_version = defined( 'DSB_VERSION' ) ? DSB_VERSION : '';
        if ( function_exists( 'get_file_data' ) && defined( 'DSB_PLUGIN_FILE' ) ) {
            $data = get_file_data(
                DSB_PLUGIN_FILE,
                [
                    'Name'    => 'Plugin Name',
                    'Version' => 'Version',
                ]
            );
            if ( ! empty( $data['Name'] ) ) {
                $plugin_name = (string) $data['Name'];
            }
            if ( ! $plugin_version && ! empty( $data['Version'] ) ) {
                $plugin_version = (string) $data['Version'];
            }
        }
    }

    $message = isset( $ctx['message'] ) ? (string) $ctx['message'] : '';
    if ( function_exists( 'dsb_mask_string' ) ) {
        $message = dsb_mask_string( $message );
    }

    $error_excerpt = isset( $ctx['error_excerpt'] ) ? (string) $ctx['error_excerpt'] : '';
    if ( function_exists( 'dsb_mask_string' ) ) {
        $error_excerpt = dsb_mask_string( $error_excerpt );
    }

    $context_value = $ctx['context'] ?? '';
    if ( is_array( $context_value ) || is_object( $context_value ) ) {
        if ( function_exists( 'dsb_mask_secrets' ) ) {
            $context_value = dsb_mask_secrets( (array) $context_value );
        }
        $context_value = wp_json_encode( $context_value );
    } else {
        $context_value = (string) $context_value;
    }
    if ( function_exists( 'dsb_mask_string' ) ) {
        $context_value = dsb_mask_string( $context_value );
    }

    $replacements = [
        '{plugin_name}'    => $plugin_name,
        '{plugin_version}' => $plugin_version,
        '{job_name}'       => isset( $ctx['job_name'] ) ? (string) $ctx['job_name'] : '',
        '{status}'         => isset( $ctx['status'] ) ? (string) $ctx['status'] : '',
        '{error_excerpt}'  => $error_excerpt,
        '{failures}'       => isset( $ctx['failures'] ) ? (string) $ctx['failures'] : '',
        '{last_run}'       => isset( $ctx['last_run'] ) ? (string) $ctx['last_run'] : '',
        '{next_run}'       => isset( $ctx['next_run'] ) ? (string) $ctx['next_run'] : '',
        '{site}'           => get_bloginfo( 'name' ),
        '{site_name}'      => get_bloginfo( 'name' ),
        '{site_url}'       => home_url(),
        '{admin_url}'      => admin_url(),
        '{date}'           => current_time( 'Y-m-d' ),
        '{time}'           => current_time( 'H:i:s' ),
        '{datetime}'       => current_time( 'Y-m-d H:i:s' ),
        '{alert_title}'    => isset( $ctx['alert_title'] ) ? (string) $ctx['alert_title'] : '',
        '{alert_code}'     => isset( $ctx['alert_code'] ) ? (string) $ctx['alert_code'] : '',
        '{severity}'       => isset( $ctx['severity'] ) ? (string) $ctx['severity'] : '',
        '{message}'        => $message,
        '{context}'        => $context_value,
    ];

    return strtr( $text, $replacements );
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
    $message = dsb_mask_string( (string) $message );

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

function dsb_delete_cron_logs(): void {
    if ( class_exists( __NAMESPACE__ . '\\DSB_Cron_Logger' ) ) {
        $dir = DSB_Cron_Logger::get_dir();
    } else {
        $dir = trailingslashit( dsb_get_log_dir() ) . 'cron/';
    }

    if ( ! is_dir( $dir ) ) {
        return;
    }

    foreach ( glob( $dir . '*', GLOB_NOSORT ) as $file ) {
        if ( is_file( $file ) ) {
            @unlink( $file );
        }
    }

    $index    = trailingslashit( $dir ) . 'index.php';
    $htaccess = trailingslashit( $dir ) . '.htaccess';
    $web_config = trailingslashit( $dir ) . 'web.config';

    if ( file_exists( $index ) ) {
        @unlink( $index );
    }
    if ( file_exists( $htaccess ) ) {
        @unlink( $htaccess );
    }
    if ( file_exists( $web_config ) ) {
        @unlink( $web_config );
    }

    $remaining = glob( $dir . '*', GLOB_NOSORT );
    if ( empty( $remaining ) ) {
        @rmdir( $dir );
    }
}

function dsb_delete_alert_logs(): void {
    $dir = dsb_get_alert_log_dir();
    if ( ! is_dir( $dir ) ) {
        return;
    }

    $file = dsb_get_alert_log_file();
    if ( file_exists( $file ) ) {
        @unlink( $file );
    }

    $index      = trailingslashit( $dir ) . 'index.php';
    $htaccess   = trailingslashit( $dir ) . '.htaccess';
    $web_config = trailingslashit( $dir ) . 'web.config';

    if ( file_exists( $index ) ) {
        @unlink( $index );
    }
    if ( file_exists( $htaccess ) ) {
        @unlink( $htaccess );
    }
    if ( file_exists( $web_config ) ) {
        @unlink( $web_config );
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
