<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Cron_Alerts {
    const OPTION_STATE = 'dsb_cron_alert_state';
    const OPTION_GENERIC_STATE = 'dsb_alert_state';

    protected static $job_labels = [
        'purge_worker' => 'Purge Worker',
        'provision_worker' => 'Provision Worker',
        'node_poll'    => 'Node Poll Sync',
        'resync'       => 'Daily Resync',
    ];

    public static function handle_job_result( string $job, string $status, string $error_excerpt, array $settings, array $context = [] ): void {
        $state      = get_option( self::OPTION_STATE, [] );
        $job_state  = isset( $state[ $job ] ) && is_array( $state[ $job ] ) ? $state[ $job ] : [];
        $job_state += [
            'failures'       => 0,
            'alert_sent_at'  => 0,
            'last_failure_at'=> 0,
            'alerted'        => false,
            'last_error'     => '',
        ];

        $clean_error = self::sanitize_excerpt( $error_excerpt );
        $is_failure  = in_array( $status, [ 'error', 'failed', 'failure', 'warning' ], true );

        if ( ! $is_failure ) {
            $send_recovery = $job_state['alerted'] && ! empty( $settings[ 'enable_recovery_' . $job ] ) && ! empty( $settings['alerts_enable_cron'] );
            $job_state['failures']      = 0;
            $job_state['alerted']       = false;
            $job_state['alert_sent_at'] = 0;
            $job_state['last_error']    = '';

            if ( $send_recovery ) {
                self::send_recovery( $job, $settings, $context );
            }

            $state[ $job ] = $job_state;
            update_option( self::OPTION_STATE, $state );
            return;
        }

        $job_state['failures']      = max( 0, (int) $job_state['failures'] ) + 1;
        $job_state['last_failure_at'] = time();
        $job_state['last_error']    = $clean_error;

        $threshold = max( 1, (int) ( $settings['alert_threshold'] ?? 3 ) );
        $cooldown  = max( 1, (int) ( $settings['alert_cooldown_minutes'] ?? 60 ) );
        $should_alert = ! empty( $settings[ 'enable_alerts_' . $job ] ) && $job_state['failures'] >= $threshold && ! empty( $settings['alerts_enable_cron'] );

        if ( $should_alert && ( time() - (int) $job_state['alert_sent_at'] ) >= ( $cooldown * MINUTE_IN_SECONDS ) ) {
            $sent = self::send_alert( $job, $settings, $job_state, $context );
            if ( $sent ) {
                $job_state['alert_sent_at'] = time();
                $job_state['alerted']       = true;
            }
        }

        $state[ $job ] = $job_state;
        update_option( self::OPTION_STATE, $state );
    }

    protected static function sanitize_excerpt( string $excerpt ): string {
        $excerpt = wp_strip_all_tags( $excerpt );
        if ( strlen( $excerpt ) > 300 ) {
            $excerpt = substr( $excerpt, 0, 300 ) . '…';
        }
        return $excerpt;
    }

    protected static function get_settings(): array {
        $defaults = [
            'alert_emails'        => '',
            'alert_email_from_name' => '',
            'telegram_bot_token'  => '',
            'telegram_chat_ids'   => '',
            'alert_template'      => '',
            'recovery_template'   => '',
            'alert_email_subject' => 'PixLab License Bridge Alert: {job_name}',
            'recovery_email_subject' => 'PixLab License Bridge Recovery: {job_name}',
            'alert_threshold'     => 3,
            'alert_cooldown_minutes' => 60,
            'alerts_enable_cron'  => 1,
            'alerts_enable_db_connectivity' => 1,
            'alerts_enable_license_validation' => 1,
            'alerts_enable_api_error_rate' => 1,
            'alerts_enable_admin_security' => 1,
            'alerts_api_error_cooldown_minutes' => 30,
        ];

        $options = get_option( DSB_Client::OPTION_SETTINGS, [] );
        return wp_parse_args( is_array( $options ) ? $options : [], $defaults );
    }

    protected static function get_job_label( string $job ): string {
        return self::$job_labels[ $job ] ?? ucfirst( str_replace( '_', ' ', $job ) );
    }

    protected static function parse_list( string $value ): array {
        $parts = preg_split( '/[\n,]+/', $value );
        $parts = array_filter( array_map( 'trim', is_array( $parts ) ? $parts : [] ) );
        return array_values( array_unique( $parts ) );
    }

    protected static function log_alert_entry( string $channel, string $severity, string $alert_code, string $status, string $message, array $context = [], string $error_excerpt = '' ): void {
        $record = [
            'channel'  => $channel,
            'severity' => $severity,
            'code'     => $alert_code,
            'status'   => $status,
            'message'  => $message,
        ];
        if ( $error_excerpt ) {
            $record['error'] = $error_excerpt;
        }
        if ( $context ) {
            $record['context'] = $context;
        }

        if ( function_exists( __NAMESPACE__ . '\\dsb_alert_log_write' ) ) {
            dsb_alert_log_write( $record );
        }

        if ( empty( $GLOBALS['wpdb'] ) ) {
            return;
        }

        $db = new DSB_DB( $GLOBALS['wpdb'] );
        $db->log_event(
            [
                'event'         => 'alert_send',
                'response_action' => $alert_code,
                'http_code'     => 'sent' === $status ? 200 : 500,
                'error_excerpt' => $error_excerpt ?: $status,
                'context'       => [
                    'channel'  => $channel,
                    'severity' => $severity,
                    'status'   => $status,
                ],
            ]
        );
    }

    protected static function send_alert( string $job, array $settings, array $job_state, array $context ): bool {
        $emails = self::parse_list( (string) ( $settings['alert_emails'] ?? '' ) );
        $emails = array_values( array_filter( $emails, 'is_email' ) );

        $chat_ids = self::parse_list( (string) ( $settings['telegram_chat_ids'] ?? '' ) );
        $bot_token = preg_replace( '/\s+/', '', trim( (string) ( $settings['telegram_bot_token'] ?? '' ) ) );

        if ( empty( $emails ) && ( empty( $bot_token ) || empty( $chat_ids ) ) ) {
            return false;
        }

        $job_label = self::get_job_label( $job );
        $template  = (string) ( $settings['alert_template'] ?? '' );
        if ( ! $template ) {
            $template = '{job_name} failed on {site}. Status: {status}. Failures: {failures}. Error: {error_excerpt}.';
        }

        $message = self::render_template( $template, $job_label, 'error', $job_state, $context );
        $sent    = false;
        $alert_code = 'cron.' . $job;
        $severity = 'error';
        $token_context = [
            'alert_title' => $job_label,
            'alert_code'  => $alert_code,
            'severity'    => $severity,
            'message'     => $message,
            'context'     => $context,
        ];
        if ( function_exists( 'dsb_apply_tokens' ) ) {
            $message = dsb_apply_tokens( $message, $token_context );
        }

        if ( $emails ) {
            $subject_template = trim( (string) ( $settings['alert_email_subject'] ?? '' ) );
            $subject = $subject_template
                ? self::render_subject( $subject_template, $job_label, 'error', $job_state, $context )
                : sprintf( '%s alert: %s', $job_label, get_bloginfo( 'name' ) );
            $from_name = isset( $settings['alert_email_from_name'] ) ? trim( (string) $settings['alert_email_from_name'] ) : '';
            if ( function_exists( 'dsb_apply_tokens' ) ) {
                $subject = dsb_apply_tokens( $subject, $token_context );
                $from_name = dsb_apply_tokens( $from_name, $token_context );
            }
            $filter = null;
            if ( $from_name ) {
                $filter = static function () use ( $from_name ): string {
                    return $from_name;
                };
                add_filter( 'wp_mail_from_name', $filter );
            }
            self::log_alert_entry( 'email', $severity, $alert_code, 'attempt', $message, $context );
            $email_sent = wp_mail( $emails, $subject, $message );
            if ( $filter ) {
                remove_filter( 'wp_mail_from_name', $filter );
            }
            $sent = $email_sent || $sent;
            self::log_alert_entry( 'email', $severity, $alert_code, $email_sent ? 'sent' : 'failed', $message, $context );
        }

        if ( $bot_token && $chat_ids ) {
            foreach ( $chat_ids as $chat_id ) {
                self::log_alert_entry( 'telegram', $severity, $alert_code, 'attempt', $message, $context );
                $response = wp_remote_post(
                    'https://api.telegram.org/bot' . $bot_token . '/sendMessage',
                    [
                        'timeout' => 10,
                        'body'    => [
                            'chat_id'                  => $chat_id,
                            'text'                     => $message,
                            'disable_web_page_preview' => true,
                        ],
                    ]
                );

                if ( ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) < 300 ) {
                    $sent = true;
                    self::log_alert_entry( 'telegram', $severity, $alert_code, 'sent', $message, $context );
                } else {
                    $error_excerpt = is_wp_error( $response ) ? $response->get_error_message() : (string) wp_remote_retrieve_response_code( $response );
                    self::log_alert_entry( 'telegram', $severity, $alert_code, 'failed', $message, $context, $error_excerpt );
                }
            }
        }

        return $sent;
    }

    protected static function send_recovery( string $job, array $settings, array $context ): void {
        $emails = self::parse_list( (string) ( $settings['alert_emails'] ?? '' ) );
        $emails = array_values( array_filter( $emails, 'is_email' ) );
        $chat_ids = self::parse_list( (string) ( $settings['telegram_chat_ids'] ?? '' ) );
        $bot_token = preg_replace( '/\s+/', '', trim( (string) ( $settings['telegram_bot_token'] ?? '' ) ) );

        if ( empty( $emails ) && ( empty( $bot_token ) || empty( $chat_ids ) ) ) {
            return;
        }

        $job_label = self::get_job_label( $job );
        $template  = (string) ( $settings['recovery_template'] ?? '' );
        if ( ! $template ) {
            $template = '{job_name} recovered on {site} at {time}.';
        }

        $message = self::render_template( $template, $job_label, 'recovered', [ 'failures' => 0, 'last_error' => '' ], $context );
        $alert_code = 'cron.' . $job;
        $severity = 'info';
        $token_context = [
            'alert_title' => $job_label,
            'alert_code'  => $alert_code,
            'severity'    => $severity,
            'message'     => $message,
            'context'     => $context,
        ];
        if ( function_exists( 'dsb_apply_tokens' ) ) {
            $message = dsb_apply_tokens( $message, $token_context );
        }

        if ( $emails ) {
            $subject_template = trim( (string) ( $settings['recovery_email_subject'] ?? '' ) );
            $subject = $subject_template
                ? self::render_subject( $subject_template, $job_label, 'recovered', [ 'failures' => 0, 'last_error' => '' ], $context )
                : sprintf( '%s recovered: %s', $job_label, get_bloginfo( 'name' ) );
            $from_name = isset( $settings['alert_email_from_name'] ) ? trim( (string) $settings['alert_email_from_name'] ) : '';
            if ( function_exists( 'dsb_apply_tokens' ) ) {
                $subject = dsb_apply_tokens( $subject, $token_context );
                $from_name = dsb_apply_tokens( $from_name, $token_context );
            }
            $filter = null;
            if ( $from_name ) {
                $filter = static function () use ( $from_name ): string {
                    return $from_name;
                };
                add_filter( 'wp_mail_from_name', $filter );
            }
            self::log_alert_entry( 'email', $severity, $alert_code, 'attempt', $message, $context );
            $email_sent = wp_mail( $emails, $subject, $message );
            if ( $filter ) {
                remove_filter( 'wp_mail_from_name', $filter );
            }
            self::log_alert_entry( 'email', $severity, $alert_code, $email_sent ? 'sent' : 'failed', $message, $context );
        }

        if ( $bot_token && $chat_ids ) {
            foreach ( $chat_ids as $chat_id ) {
                self::log_alert_entry( 'telegram', $severity, $alert_code, 'attempt', $message, $context );
                $response = wp_remote_post(
                    'https://api.telegram.org/bot' . $bot_token . '/sendMessage',
                    [
                        'timeout' => 10,
                        'body'    => [
                            'chat_id'                  => $chat_id,
                            'text'                     => $message,
                            'disable_web_page_preview' => true,
                        ],
                    ]
                );
                if ( ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) < 300 ) {
                    self::log_alert_entry( 'telegram', $severity, $alert_code, 'sent', $message, $context );
                } else {
                    $error_excerpt = is_wp_error( $response ) ? $response->get_error_message() : (string) wp_remote_retrieve_response_code( $response );
                    self::log_alert_entry( 'telegram', $severity, $alert_code, 'failed', $message, $context, $error_excerpt );
                }
            }
        }
    }

    protected static function render_template( string $template, string $job_label, string $status, array $job_state, array $context ): string {
        if ( function_exists( 'dsb_mask_secrets' ) ) {
            $context = dsb_mask_secrets( $context );
        }
        $replacements = [
            '{job_name}'       => $job_label,
            '{status}'         => $status,
            '{error_excerpt}'  => $job_state['last_error'] ?? '',
            '{failures}'       => isset( $job_state['failures'] ) ? (string) $job_state['failures'] : '0',
            '{last_run}'       => $context['last_run'] ?? '',
            '{next_run}'       => $context['next_run'] ?? '',
            '{site}'           => get_bloginfo( 'name' ),
            '{site_url}'       => home_url(),
            '{time}'           => gmdate( 'c' ),
        ];

        $rendered = strtr( $template, $replacements );
        $rendered = wp_strip_all_tags( $rendered );
        return substr( $rendered, 0, 1000 );
    }

    protected static function render_subject( string $template, string $job_label, string $status, array $job_state, array $context ): string {
        $subject = self::render_template( $template, $job_label, $status, $job_state, $context );
        return substr( $subject, 0, 150 );
    }

    protected static function append_context( string $message, array $context ): string {
        if ( function_exists( 'dsb_mask_secrets' ) ) {
            $context = dsb_mask_secrets( $context );
        }
        $context = is_array( $context ) ? $context : [];
        if ( empty( $context ) ) {
            return $message;
        }

        $lines = [];
        foreach ( $context as $key => $value ) {
            if ( is_array( $value ) ) {
                $value = wp_json_encode( $value );
            } elseif ( is_bool( $value ) ) {
                $value = $value ? 'true' : 'false';
            } elseif ( null === $value ) {
                $value = 'null';
            }
            $lines[] = sprintf( '%s: %s', sanitize_key( (string) $key ), wp_strip_all_tags( (string) $value ) );
        }

        $context_block = implode( "\n", array_slice( $lines, 0, 12 ) );
        return trim( $message . "\n\nContext:\n" . $context_block );
    }

    protected static function get_generic_state(): array {
        $state = get_option( self::OPTION_GENERIC_STATE, [] );
        return is_array( $state ) ? $state : [];
    }

    protected static function update_generic_state( array $state ): void {
        update_option( self::OPTION_GENERIC_STATE, $state, false );
    }

    protected static function is_trigger_enabled( string $type, array $settings ): bool {
        if ( 0 === strpos( $type, 'license.' ) ) {
            return ! empty( $settings['alerts_enable_license_validation'] );
        }
        if ( 0 === strpos( $type, 'db.' ) ) {
            return ! empty( $settings['alerts_enable_db_connectivity'] );
        }
        if ( 0 === strpos( $type, 'api.' ) ) {
            return ! empty( $settings['alerts_enable_api_error_rate'] );
        }
        if ( 0 === strpos( $type, 'admin.' ) ) {
            return ! empty( $settings['alerts_enable_admin_security'] );
        }
        return true;
    }

    public static function trigger_generic_alert( string $type, string $title, array $context, string $severity = 'error', ?int $cooldown_override = null ): bool {
        $settings = self::get_settings();
        if ( ! self::is_trigger_enabled( $type, $settings ) ) {
            return false;
        }

        $state = self::get_generic_state();
        $entry = isset( $state[ $type ] ) && is_array( $state[ $type ] ) ? $state[ $type ] : [];
        $entry += [
            'is_alerting'   => false,
            'last_alert_at' => 0,
            'last_error_code' => '',
        ];

        $cooldown = $cooldown_override !== null ? max( 1, $cooldown_override ) : max( 1, (int) ( $settings['alert_cooldown_minutes'] ?? 60 ) );
        if ( ( time() - (int) $entry['last_alert_at'] ) < ( $cooldown * MINUTE_IN_SECONDS ) ) {
            return false;
        }

        $job_state = [
            'failures'   => 1,
            'last_error' => self::sanitize_excerpt( (string) ( $context['error'] ?? $context['error_excerpt'] ?? '' ) ),
        ];

        $template = (string) ( $settings['alert_template'] ?? '' );
        if ( ! $template ) {
            $template = '{job_name} failed on {site}. Status: {status}. Error: {error_excerpt}.';
        }

        $message = self::render_template( $template, $title, $severity, $job_state, $context );
        $message = self::append_context( $message, $context );

        $subject_template = trim( (string) ( $settings['alert_email_subject'] ?? '' ) );
        $subject = $subject_template
            ? self::render_subject( $subject_template, $title, $severity, $job_state, $context )
            : sprintf( '%s alert: %s', $title, get_bloginfo( 'name' ) );

        $sent = self::send_message(
            $settings,
            $subject,
            $message,
            [
                'alert_code' => $type,
                'alert_title' => $title,
                'severity'   => $severity,
                'context'    => $context,
            ]
        );
        if ( $sent ) {
            $entry['is_alerting']   = true;
            $entry['last_alert_at'] = time();
            $entry['last_error_code'] = sanitize_key( $type );
            $state[ $type ] = $entry;
            self::update_generic_state( $state );
        }

        return $sent;
    }

    public static function trigger_generic_recovery( string $type, string $title, array $context, int $min_delay_seconds = 0 ): bool {
        $settings = self::get_settings();
        if ( ! self::is_trigger_enabled( $type, $settings ) ) {
            return false;
        }

        $state = self::get_generic_state();
        $entry = isset( $state[ $type ] ) && is_array( $state[ $type ] ) ? $state[ $type ] : [];
        if ( empty( $entry['is_alerting'] ) ) {
            return false;
        }

        $last_alert_at = (int) ( $entry['last_alert_at'] ?? 0 );
        if ( $min_delay_seconds > 0 && ( time() - $last_alert_at ) < $min_delay_seconds ) {
            return false;
        }

        $job_state = [
            'failures'   => 0,
            'last_error' => '',
        ];

        $template = (string) ( $settings['recovery_template'] ?? '' );
        if ( ! $template ) {
            $template = '{job_name} recovered on {site} at {time}.';
        }

        $message = self::render_template( $template, $title, 'recovered', $job_state, $context );
        $message = self::append_context( $message, $context );

        $subject_template = trim( (string) ( $settings['recovery_email_subject'] ?? '' ) );
        $subject = $subject_template
            ? self::render_subject( $subject_template, $title, 'recovered', $job_state, $context )
            : sprintf( '%s recovered: %s', $title, get_bloginfo( 'name' ) );

        $sent = self::send_message(
            $settings,
            $subject,
            $message,
            [
                'alert_code' => $type,
                'alert_title' => $title,
                'severity'   => 'info',
                'context'    => $context,
            ]
        );
        if ( $sent ) {
            $entry['is_alerting']   = false;
            $entry['last_alert_at'] = 0;
            $entry['last_error_code'] = '';
            $state[ $type ] = $entry;
            self::update_generic_state( $state );
        }

        return $sent;
    }

    public static function send_test_routing(): array {
        $settings = self::get_settings();
        $job_label = __( 'Test Alert', 'pixlab-license-bridge' );
        $job_state = [ 'failures' => 0, 'last_error' => '' ];
        $template  = (string) ( $settings['alert_template'] ?? '' );
        if ( ! $template ) {
            $template = '{job_name} test alert on {site} at {time}.';
        }
        $message = self::render_template( $template, $job_label, 'test', $job_state, [] );
        $subject_template = trim( (string) ( $settings['alert_email_subject'] ?? '' ) );
        $subject = $subject_template
            ? self::render_subject( $subject_template, $job_label, 'test', $job_state, [] )
            : sprintf( '%s alert: %s', $job_label, get_bloginfo( 'name' ) );

        $sent = self::send_message(
            $settings,
            $subject,
            $message,
            [
                'alert_code' => 'test.alert',
                'alert_title' => $job_label,
                'severity'   => 'test',
                'context'    => [],
            ]
        );
        $emails = self::parse_list( (string) ( $settings['alert_emails'] ?? '' ) );
        $emails = array_values( array_filter( $emails, 'is_email' ) );
        $chat_ids = self::parse_list( (string) ( $settings['telegram_chat_ids'] ?? '' ) );
        $bot_token = preg_replace( '/\s+/', '', trim( (string) ( $settings['telegram_bot_token'] ?? '' ) ) );

        return [
            'sent' => $sent,
            'email_attempted' => ! empty( $emails ),
            'telegram_attempted' => (bool) ( $bot_token && $chat_ids ),
        ];
    }

    protected static function send_message( array $settings, string $subject, string $message, array $meta = [] ): bool {
        $emails = self::parse_list( (string) ( $settings['alert_emails'] ?? '' ) );
        $emails = array_values( array_filter( $emails, 'is_email' ) );
        $chat_ids = self::parse_list( (string) ( $settings['telegram_chat_ids'] ?? '' ) );
        $bot_token = preg_replace( '/\s+/', '', trim( (string) ( $settings['telegram_bot_token'] ?? '' ) ) );
        $alert_code = isset( $meta['alert_code'] ) ? sanitize_text_field( (string) $meta['alert_code'] ) : 'generic.alert';
        $severity = isset( $meta['severity'] ) ? sanitize_key( (string) $meta['severity'] ) : 'error';
        $context = isset( $meta['context'] ) && is_array( $meta['context'] ) ? $meta['context'] : [];
        $token_context = [
            'alert_title' => isset( $meta['alert_title'] ) ? (string) $meta['alert_title'] : '',
            'alert_code'  => $alert_code,
            'severity'    => $severity,
            'message'     => $message,
            'context'     => $context,
        ];
        if ( function_exists( 'dsb_apply_tokens' ) ) {
            $subject = dsb_apply_tokens( $subject, $token_context );
            $message = dsb_apply_tokens( $message, $token_context );
        }

        $sent = false;
        if ( $emails ) {
            $from_name = isset( $settings['alert_email_from_name'] ) ? trim( (string) $settings['alert_email_from_name'] ) : '';
            if ( function_exists( 'dsb_apply_tokens' ) ) {
                $from_name = dsb_apply_tokens( $from_name, $token_context );
            }
            $filter = null;
            if ( $from_name ) {
                $filter = static function () use ( $from_name ): string {
                    return $from_name;
                };
                add_filter( 'wp_mail_from_name', $filter );
            }
            self::log_alert_entry( 'email', $severity, $alert_code, 'attempt', $message, $context );
            $email_sent = wp_mail( $emails, $subject, $message );
            if ( $filter ) {
                remove_filter( 'wp_mail_from_name', $filter );
            }
            $sent = $email_sent || $sent;
            self::log_alert_entry( 'email', $severity, $alert_code, $email_sent ? 'sent' : 'failed', $message, $context );
        }

        if ( $bot_token && $chat_ids ) {
            foreach ( $chat_ids as $chat_id ) {
                self::log_alert_entry( 'telegram', $severity, $alert_code, 'attempt', $message, $context );
                $response = wp_remote_post(
                    'https://api.telegram.org/bot' . $bot_token . '/sendMessage',
                    [
                        'timeout' => 10,
                        'body'    => [
                            'chat_id'                  => $chat_id,
                            'text'                     => $message,
                            'disable_web_page_preview' => true,
                        ],
                    ]
                );

                if ( ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) < 300 ) {
                    $sent = true;
                    self::log_alert_entry( 'telegram', $severity, $alert_code, 'sent', $message, $context );
                } else {
                    $error_excerpt = is_wp_error( $response ) ? $response->get_error_message() : (string) wp_remote_retrieve_response_code( $response );
                    self::log_alert_entry( 'telegram', $severity, $alert_code, 'failed', $message, $context, $error_excerpt );
                }
            }
        }

        return $sent;
    }
}
