<?php
/**
 * Plugin Name: WooCommerce Custom Payments (CVU/Alias)
 * Plugin URI: https://venezuelaonline.net
 * Description: Método de pago personalizado con integración a API externa para generar CVU/Alias. Configurable desde el panel de administración.
 * Version: 1.0.5
 * Author: Gustavo Gonzalez
 * Author URI: https://venezuelaonline.net
 * Text Domain: woocommerce-custom-payments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Carga el dominio de texto.
 */
function wccp_load_textdomain() {
    load_plugin_textdomain( 'woocommerce-custom-payments', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'wccp_load_textdomain' );

/**
 * Verifica que WooCommerce esté activo.
 */
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    add_action( 'admin_notices', 'wccp_woocommerce_missing_notice' );
    return;
}
function wccp_woocommerce_missing_notice() {
    echo '<div class="error"><p>' . esc_html__( 'WooCommerce Custom Payments requiere WooCommerce activo.', 'woocommerce-custom-payments' ) . '</p></div>';
}

/**
 * Inicializa clases del gateway, AJAX y cron.
 */
add_action( 'plugins_loaded', 'wccp_init_plugin', 11 );
function wccp_init_plugin() {
    if ( class_exists( 'WC_Payment_Gateway' ) ) {
        include_once dirname( __FILE__ ) . '/includes/class-wc-gateway-custom-payments.php';
        include_once dirname( __FILE__ ) . '/includes/class-wc-custom-payments-webhook-handler.php';
        include_once dirname( __FILE__ ) . '/includes/class-wc-custom-payments-cron.php';
        include_once dirname( __FILE__ ) . '/includes/class-wc-custom-payments-ajax.php';

        add_filter( 'woocommerce_payment_gateways', 'wccp_add_gateway' );

        // Añade un intervalo de cron de 5 minutos
        add_filter( 'cron_schedules', 'wccp_add_5mins_schedule' );
        function wccp_add_5mins_schedule( $schedules ) {
            if ( ! isset( $schedules['every5mins'] ) ) {
                $schedules['every5mins'] = array(
                    'interval' => 300,
                    'display'  => __( 'Cada 5 minutos', 'woocommerce-custom-payments' )
                );
            }
            return $schedules;
        }

        // Evento cron para limpieza cada 5 minutos
        if ( ! wp_next_scheduled( 'wccp_cleanup_provisional_orders_event' ) ) {
            wp_schedule_event( time(), 'every5mins', 'wccp_cleanup_provisional_orders_event' );
        }
        add_action( 'wccp_cleanup_provisional_orders_event', array( 'WC_Custom_Payments_Cron', 'cleanup_provisional_orders' ) );
    }
}

function wccp_add_gateway( $gateways ) {
    $gateways[] = 'WC_Gateway_Custom_Payments';
    return $gateways;
}

/**
 * Endpoint para confirmación de pago (webhook).
 */
add_action( 'rest_api_init', function() {
    register_rest_route( 'wccp/v1', '/payment-confirmation', array(
        'methods'  => 'POST',
        'callback' => array( 'WC_Custom_Payments_Webhook_Handler', 'handle_payment_confirmation' ),
        'permission_callback' => '__return_true'
    ) );
} );

/**
 * Enlace a Ajustes en la página de plugins.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wccp_add_action_links' );
function wccp_add_action_links( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=custom_payments_cvu' ) . '">' . __( 'Ajustes', 'woocommerce-custom-payments' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

/**
 * Encola scripts y estilos en el checkout.
 */
add_action( 'wp_enqueue_scripts', 'wccp_enqueue_scripts' );
function wccp_enqueue_scripts() {
    if ( is_checkout() ) {
        // JS
        wp_enqueue_script( 'wccp-js', plugins_url( 'assets/js/custom-payments.js', __FILE__ ), array( 'jquery' ), '1.0.1', true );

        // Localizamos uso de API para el front
        // Esto permite al JS saber si la API está activa o no.
        $gateway_settings = get_option( 'woocommerce_custom_payments_cvu_settings', array() );
        $use_api = isset($gateway_settings['use_api']) && $gateway_settings['use_api'] === 'yes' ? 'yes' : 'no';

        wp_localize_script( 'wccp-js', 'wccp_params', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'use_api'  => $use_api,
            'texts'    => array(
                'generating' => __( 'Generando CVU/Alias...', 'woocommerce-custom-payments' ),
                'error_com'  => __( 'Error de comunicación.', 'woocommerce-custom-payments' ),
                'cvu_label'  => __( 'CVU:', 'woocommerce-custom-payments' ),
                'alias_label'=> __( 'Alias:', 'woocommerce-custom-payments' ),
                'days_label' => __( 'días para completar el pago.', 'woocommerce-custom-payments' ),
                'assigned'   => __( 'CVU/Alias asignados. También se enviarán por correo al finalizar el pedido.', 'woocommerce-custom-payments' ),
                'deleting'   => __( 'Eliminando pedido provisional...', 'woocommerce-custom-payments' )
            )
        ));

        // CSS
        wp_enqueue_style( 'wccp-css', plugins_url( 'assets/css/custom-payments.css', __FILE__ ), array(), '1.0.0' );
    }
}
