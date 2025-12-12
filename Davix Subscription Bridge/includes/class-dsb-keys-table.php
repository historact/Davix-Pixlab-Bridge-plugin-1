<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class DSB_Keys_Table extends \WP_List_Table {
    protected $db;
    protected $client;

    public function __construct( DSB_DB $db, DSB_Client $client ) {
        $this->db     = $db;
        $this->client = $client;

        parent::__construct(
            [
                'singular' => 'bridge-key',
                'plural'   => 'bridge-keys',
                'ajax'     => false,
            ]
        );
    }

    public function get_columns() {
        return [
            'subscription_id' => __( 'Subscription ID', 'davix-sub-bridge' ),
            'customer_email'  => __( 'Customer Email', 'davix-sub-bridge' ),
            'plan_slug'       => __( 'Plan', 'davix-sub-bridge' ),
            'status'          => __( 'Status', 'davix-sub-bridge' ),
            'key_prefix'      => __( 'Key Prefix', 'davix-sub-bridge' ),
            'key_last4'       => __( 'Key Last4', 'davix-sub-bridge' ),
            'updated_at'      => __( 'Updated', 'davix-sub-bridge' ),
            'actions'         => __( 'Actions', 'davix-sub-bridge' ),
        ];
    }

    public function prepare_items() {
        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $data         = $this->db->get_keys( $per_page, $current_page );
        $total_items  = $this->db->count_keys();

        $this->items = $data;
        $this->_column_headers = [ $this->get_columns(), [], [] ];

        $this->set_pagination_args(
            [
                'total_items' => $total_items,
                'per_page'    => $per_page,
            ]
        );
    }

    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'subscription_id':
            case 'customer_email':
            case 'plan_slug':
            case 'status':
            case 'key_prefix':
            case 'key_last4':
            case 'updated_at':
                return esc_html( $item[ $column_name ] ?? '' );
            case 'actions':
                $actions = [
                    'activate'   => sprintf( '<a href="%s">%s</a>', esc_url( $this->action_url( 'activate', $item ) ), __( 'Activate', 'davix-sub-bridge' ) ),
                    'deactivate' => sprintf( '<a href="%s">%s</a>', esc_url( $this->action_url( 'deactivate', $item ) ), __( 'Deactivate', 'davix-sub-bridge' ) ),
                    'regenerate' => sprintf( '<a href="%s">%s</a>', esc_url( $this->action_url( 'regenerate', $item ) ), __( 'Regenerate', 'davix-sub-bridge' ) ),
                ];
                return implode( ' | ', $actions );
            default:
                return '';
        }
    }

    protected function action_url( string $action, array $item ): string {
        return wp_nonce_url(
            add_query_arg(
                [
                    'page' => 'davix-bridge',
                    'tab'  => 'keys',
                    'dsb_action' => $action,
                    'subscription_id' => $item['subscription_id'],
                ],
                admin_url( 'admin.php' )
            ),
            'dsb_keys_action'
        );
    }
}
