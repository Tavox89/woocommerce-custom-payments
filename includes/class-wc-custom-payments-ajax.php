<?php
/**
 * Clase AJAX: maneja creación y eliminación de pedidos provisionales.
 * - Usa meta _wccp_provisional = 1 para marcar pedidos.
 * - Si ya existe un pedido provisional en sesión, lo reutiliza sin crear otro.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Custom_Payments_Ajax {

    public static function init() {
        add_action( 'wp_ajax_wccp_create_provisional_order', array( __CLASS__, 'create_provisional_order' ) );
        add_action( 'wp_ajax_nopriv_wccp_create_provisional_order', array( __CLASS__, 'create_provisional_order' ) );

        add_action( 'wp_ajax_wccp_delete_provisional_order', array( __CLASS__, 'delete_provisional_order' ) );
        add_action( 'wp_ajax_nopriv_wccp_delete_provisional_order', array( __CLASS__, 'delete_provisional_order' ) );
    }

    public static function create_provisional_order() {

        // 1) Verificamos si la API está activada
        $payment_gateways = WC()->payment_gateways()->payment_gateways();
        if ( ! isset( $payment_gateways['custom_payments_cvu'] ) ) {
            wp_send_json_error( __( 'Método de pago no disponible.', 'woocommerce-custom-payments' ) );
        }

        /** @var WC_Gateway_Custom_Payments $gateway */
        $gateway = $payment_gateways['custom_payments_cvu'];
        if ( ! $gateway->use_api ) {
            // La API está desactivada -> no creamos pedido provisional
            wp_send_json_error( __( 'La API está desactivada. No se requiere pedido provisional.', 'woocommerce-custom-payments' ) );
        }

        // 2) Verificamos el carrito
        if ( WC()->cart->is_empty() ) {
            wp_send_json_error( __( 'El carrito está vacío.', 'woocommerce-custom-payments' ) );
        }

        // 3) Revisamos si ya existe un pedido provisional válido
        $existing_order_id = WC()->session->get('wccp_provisional_order_id');
        if ( $existing_order_id ) {
            $existing_order = wc_get_order( $existing_order_id );
            if ( $existing_order 
                 && 'wc-checkout-draft' === $existing_order->get_status() 
                 && get_post_meta( $existing_order_id, '_wccp_provisional', true ) == '1' ) {

                // Retornar el mismo CVU/Alias
                $cvu   = get_post_meta( $existing_order_id, '_cvu', true );
                $alias = get_post_meta( $existing_order_id, '_alias', true );
                if ( $cvu && $alias ) {
                    $days = $gateway->get_expiration_days();
                    wp_send_json_success( array(
                        'order_id' => $existing_order_id,
                        'cvu'      => $cvu,
                        'alias'    => $alias,
                        'days'     => $days
                    ) );
                } else {
                    // Si no tiene CVU/Alias, eliminar y crear nuevo
                    wp_delete_post( $existing_order_id, true );
                    WC()->session->__unset('wccp_provisional_order_id');
                }
            } else {
                // Orden no válida, eliminar
                wp_delete_post( $existing_order_id, true );
                WC()->session->__unset('wccp_provisional_order_id');
            }
        }

        // 4) Crear un nuevo pedido provisional
        $order = wc_create_order();
        foreach ( WC()->cart->get_cart() as $cart_item_key => $values ) {
            $product = $values['data'];
            $order->add_product( $product, $values['quantity'] );
        }
        $order->calculate_totals();

        $order_id = $order->get_id();
        update_post_meta( $order_id, '_wccp_provisional', '1' );

        // 5) Intentar obtener CVU/Alias vía API o respaldo
        $result = $gateway->get_cvu_alias_data( $order_id );
        if ( $result && ! empty( $result['cvu'] ) && ! empty( $result['alias'] ) ) {
            update_post_meta( $order_id, '_cvu', sanitize_text_field( $result['cvu'] ) );
            update_post_meta( $order_id, '_alias', sanitize_text_field( $result['alias'] ) );
            WC()->session->set( 'wccp_provisional_order_id', $order_id );

            // Mensaje descriptivo
            $message = $result['is_backup']
                ? __( 'Se ha asignado el CVU/Alias de respaldo.', 'woocommerce-custom-payments' )
                : __( 'CVU/Alias asignados desde la API.', 'woocommerce-custom-payments' );

            wp_send_json_success( array(
                'order_id' => $order_id,
                'cvu'      => $result['cvu'],
                'alias'    => $result['alias'],
                'days'     => $gateway->get_expiration_days(),
                'message'  => $message,
                'is_backup'=> $result['is_backup'],
            ) );
        } else {
            // Si no hay nada (ni la API ni el backup) => error
            wp_delete_post( $order_id, true );
            wp_send_json_error( __( 'No se pudo obtener CVU/Alias (ni de la API ni de respaldo). Verifica la configuración.', 'woocommerce-custom-payments' ) );
        }
    }

    public static function delete_provisional_order() {
        $order_id = WC()->session->get('wccp_provisional_order_id');
        if ( $order_id ) {
            wp_delete_post( $order_id, true );
            WC()->session->__unset('wccp_provisional_order_id');
        }
        wp_send_json_success();
    }
}

WC_Custom_Payments_Ajax::init();
