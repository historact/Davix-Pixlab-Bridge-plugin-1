<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Cron_Alerts {
    const OPTION_STATE = 'dsb_cron_alert_state';

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
            $send_recovery = $job_state['alerted'] && ! empty( $settings[ 'enable_recovery_' . $job ] );
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
        $should_alert = ! empty( $settings[ 'enable_alerts_' . $job ] ) && $job_state['failures'] >= $threshold;

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

    protected static function get_job_label( string $job ): string {
        return self::$job_labels[ $job ] ?? ucfirst( str_replace( '_', ' ', $job ) );
    }

    protected static function parse_list( string $value ): array {
        $parts = preg_split( '/[\n,]+/', $value );
        $parts = array_filter( array_map( 'trim', is_array( $parts ) ? $parts : [] ) );
        return array_values( array_unique( $parts ) );
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

        if ( $emails ) {
            $subject = sprintf( '%s alert: %s', $job_label, get_bloginfo( 'name' ) );
            $sent    = wp_mail( $emails, $subject, $message ) || $sent;
        }

        if ( $bot_token && $chat_ids ) {
            foreach ( $chat_ids as $chat_id ) {
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

        if ( $emails ) {
            $subject = sprintf( '%s recovered: %s', $job_label, get_bloginfo( 'name' ) );
            wp_mail( $emails, $subject, $message );
        }

        if ( $bot_token && $chat_ids ) {
            foreach ( $chat_ids as $chat_id ) {
                wp_remote_post(
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
            }
        }
    }

    protected static function render_template( string $template, string $job_label, string $status, array $job_state, array $context ): string {
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
}
