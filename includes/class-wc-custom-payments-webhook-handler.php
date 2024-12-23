<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Maneja el webhook de confirmación de pago:
 * Actualiza el pedido a procesando si se recibe "status": "paid".
 */
class WC_Custom_Payments_Webhook_Handler {

    public static function handle_payment_confirmation( $request ) {
        $params = $request->get_json_params();
        if ( empty( $params['order_id'] ) || empty( $params['status'] ) ) {
            return new WP_Error( 'missing_data', __( 'Faltan parámetros.', 'woocommerce-custom-payments' ), array( 'status' => 400 ) );
        }

        $order_id = intval( $params['order_id'] );
        $status   = sanitize_text_field( $params['status'] );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'invalid_order', __( 'Pedido no encontrado.', 'woocommerce-custom-payments' ), array( 'status' => 404 ) );
        }

        if ( $order->get_payment_method() !== 'custom_payments_cvu' ) {
            return new WP_Error( 'invalid_payment_method', __( 'Método de pago no válido.', 'woocommerce-custom-payments' ), array( 'status' => 400 ) );
        }

        if ( $status === 'paid' ) {
            $order->payment_complete();
            $order->add_order_note( __( 'Pago confirmado vía API externa.', 'woocommerce-custom-payments' ) );
            return array(
                'success' => true,
                'message' => __( 'Pedido actualizado a procesando.', 'woocommerce-custom-payments' )
            );
        } else {
            return new WP_Error( 'invalid_status', __( 'Estado desconocido.', 'woocommerce-custom-payments' ), array( 'status' => 400 ) );
        }
    }
}
