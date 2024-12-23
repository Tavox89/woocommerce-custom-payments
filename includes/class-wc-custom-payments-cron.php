<?php
/**
 * Clase CRON: Limpia pedidos provisionales con más de 20 minutos.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Custom_Payments_Cron {

    /**
     * Limpia pedidos provisionales con más de 20 minutos de antigüedad.
     */
    public static function cleanup_provisional_orders() {
        $query_args = array(
            'post_type'   => 'shop_order',
            'post_status' => 'wc-checkout-draft',
            'meta_key'    => '_wccp_provisional',
            'meta_value'  => '1',
            'posts_per_page' => -1,
            'fields'      => 'ids'
        );

        $orders = get_posts( $query_args );
        $now = current_time( 'timestamp' );

        foreach ( $orders as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $created = $order->get_date_created();
                if ( $created ) {
                    $order_time = $created->getTimestamp();
                    $diff = ( $now - $order_time ) / 60;
                    if ( $diff > 20 ) {
                        wp_delete_post( $order_id, true );
                    }
                }
            }
        }
    }
}
