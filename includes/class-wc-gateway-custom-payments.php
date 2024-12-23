<?php
/**
 * Clase gateway: maneja la configuración, el proceso de pago y la integración con API.
 * Usa el pedido provisional si existe al finalizar el pedido.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Gateway_Custom_Payments extends WC_Payment_Gateway {

    /**
     * Constructor: carga opciones y define propiedades.
     */
    public function __construct() {
        $this->id                 = 'custom_payments_cvu';
        $this->method_title       = __( 'Transferencia CVU/Alias', 'woocommerce-custom-payments' );
        $this->method_description = __( 'Asigna un CVU/Alias al crear un pedido provisional (si la API está activa). Muestra los datos en el checkout y envía por correo al finalizar.', 'woocommerce-custom-payments' );
        $this->has_fields         = true;
        $this->supports           = array( 'products' );

        $this->init_form_fields();
        $this->init_settings();

        // Obtener valores de configuración
        $this->enabled              = $this->get_option( 'enabled' );
        $this->title                = $this->get_option( 'title' );
        $this->description          = $this->get_option( 'description' );
        $this->api_token            = $this->get_option( 'api_token' );
        $this->api_url              = $this->get_option( 'api_url' );
        $this->order_expiration_days= absint( $this->get_option( 'order_expiration_days', 7 ) );
        $this->show_days            = 'yes' === $this->get_option( 'show_days' );
        $this->enable_2days_reminder= 'yes' === $this->get_option( 'enable_2days_reminder' );

        // Nuevos campos
        $this->use_api      = 'yes' === $this->get_option( 'use_api', 'yes' );
        $this->backup_cvu   = $this->get_option( 'backup_cvu' );
        $this->backup_alias = $this->get_option( 'backup_alias' );

        // Guardado de ajustes
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        // Hook para mostrar datos en "Gracias por su compra", en mails y admin
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_order_meta_admin' ), 10, 1 );

        // Para enviar recordatorios manuales
        add_action( 'admin_init', array( $this, 'handle_manual_reminder_action' ) );
        // Para enviar correo al cambiar a On-Hold
        add_action( 'woocommerce_order_status_on-hold', array( $this, 'send_on_hold_email' ), 10, 2 );
    }

    /**
     * Campos de configuración en el panel de administración.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Activar', 'woocommerce-custom-payments' ),
                'type'    => 'checkbox',
                'default' => 'yes',
            ),
            'title' => array(
                'title'       => __( 'Título', 'woocommerce-custom-payments' ),
                'type'        => 'text',
                'default'     => __( 'Transferencia (CVU/Alias)', 'woocommerce-custom-payments' ),
            ),
            'description' => array(
                'title'       => __( 'Descripción', 'woocommerce-custom-payments' ),
                'type'        => 'textarea',
                'default'     => __( 'Se asignará un CVU/Alias para tu transferencia.', 'woocommerce-custom-payments' ),
            ),
            'api_url' => array(
                'title'       => __( 'URL de la API', 'woocommerce-custom-payments' ),
                'type'        => 'text',
                'default'     => '',
            ),
            'api_token' => array(
                'title'       => __( 'Token de Autenticación', 'woocommerce-custom-payments' ),
                'type'        => 'text',
                'default'     => '',
            ),
            'use_api' => array(
                'title'       => __( 'Usar API Externa', 'woocommerce-custom-payments' ),
                'type'        => 'checkbox',
                'label'       => __( 'Activar / Desactivar el uso de la API Externa', 'woocommerce-custom-payments' ),
                'default'     => 'yes',
                'description' => __( 'Si se desactiva, se utilizará el CVU/Alias de seguridad y no se crearán pedidos provisionales.', 'woocommerce-custom-payments' ),
            ),
            'backup_cvu' => array(
                'title'       => __( 'CVU de Respaldo', 'woocommerce-custom-payments' ),
                'type'        => 'text',
                'default'     => '',
                'description' => __( 'Se usará este CVU si falla la API o si la API está desactivada.', 'woocommerce-custom-payments' ),
            ),
            'backup_alias' => array(
                'title'       => __( 'Alias de Respaldo', 'woocommerce-custom-payments' ),
                'type'        => 'text',
                'default'     => '',
                'description' => __( 'Se usará este Alias si falla la API o si la API está desactivada.', 'woocommerce-custom-payments' ),
            ),
            'order_expiration_days' => array(
                'title'       => __( 'Días de Expiración', 'woocommerce-custom-payments' ),
                'type'        => 'number',
                'default'     => '7',
            ),
            'show_days' => array(
                'title'       => __( 'Mostrar Días Restantes', 'woocommerce-custom-payments' ),
                'type'        => 'checkbox',
                'default'     => 'yes',
            ),
            'enable_2days_reminder' => array(
                'title'       => __( 'Recordatorio 2 Días Antes', 'woocommerce-custom-payments' ),
                'type'        => 'checkbox',
                'default'     => 'yes',
            ),
        );
    }

    /**
     * Validaciones al guardar opciones.
     */
    public function process_admin_options() {
        parent::process_admin_options();
        // Si la API está desactivada, validar que existan backup_cvu y backup_alias
        if ( 'no' === $this->get_option( 'use_api' ) ) {
            if ( empty( $this->get_option( 'backup_cvu' ) ) || empty( $this->get_option( 'backup_alias' ) ) ) {
                WC_Admin_Settings::add_error( __( 'Debes configurar el CVU y el Alias de respaldo si la API está desactivada.', 'woocommerce-custom-payments' ) );
            }
        }
    }

    /**
     * Campos en el checkout.
     * Si la API está desactivada, mostramos directamente el CVU/Alias de respaldo. 
     */
    public function payment_fields() {
        echo '<div style="margin-top:10px; padding:10px; border:1px solid #ddd;">';

        if ( ! $this->use_api ) {
            // API desactivada -> mostrar CVU/Alias de respaldo directamente
            if ( ! empty( $this->backup_cvu ) && ! empty( $this->backup_alias ) ) {
                echo '<p><strong>' . __( 'CVU:', 'woocommerce-custom-payments' ) . '</strong> ' . esc_html( $this->backup_cvu ) . '<br>';
                echo '<strong>' . __( 'Alias:', 'woocommerce-custom-payments' ) . '</strong> ' . esc_html( $this->backup_alias ) . '<br>';
                echo __( 'Usando CVU/Alias de respaldo.', 'woocommerce-custom-payments' ) . '</p>';
            } else {
                // No hay respaldo configurado
                echo '<p style="color:red;">' . __( 'No se encontró CVU/Alias de respaldo. Verifica tu configuración.', 'woocommerce-custom-payments' ) . '</p>';
            }
        } else {
            // API activada -> mensaje habitual
            echo '<p>' . __( 'Al seleccionar este método se generará un CVU/Alias para tu transferencia. Esta información se mostrará aquí y se enviará por correo al finalizar el pedido.', 'woocommerce-custom-payments' ) . '</p>';
        }

        echo '</div>';

        if ( $this->use_api ) {
            // Solo si la API está activa, usamos el div AJAX
            echo '<div id="wccp_payment_info" style="display:none; margin-top:10px; padding:10px; border:1px solid #ddd;"></div>';
        }
    }

    /**
     * Procesa el pago final.
     */
    public function process_payment( $order_id ) {

        if ( $this->use_api ) {
            // --- API ACTIVADA: lógica de pedido provisional ---

            $provisional_id = WC()->session->get('wccp_provisional_order_id');

            // Si existe un pedido provisional, usar ese como final
            if ( $provisional_id && $provisional_id != $order_id ) {
                wp_delete_post( $order_id, true );
                $order_id = $provisional_id;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                wc_add_notice( __( 'Error al procesar el pedido.', 'woocommerce-custom-payments' ), 'error' );
                return;
            }

            // Recuperar datos billing/shipping
            $data = WC()->checkout()->get_posted_data();
            if ( empty( $data ) ) {
                wc_add_notice( __( 'Faltan datos del checkout.', 'woocommerce-custom-payments' ), 'error' );
                return;
            }

            $billing = array(
                'first_name' => isset($data['billing_first_name']) ? $data['billing_first_name'] : '',
                'last_name'  => isset($data['billing_last_name']) ? $data['billing_last_name'] : '',
                'company'    => isset($data['billing_company']) ? $data['billing_company'] : '',
                'address_1'  => isset($data['billing_address_1']) ? $data['billing_address_1'] : '',
                'address_2'  => isset($data['billing_address_2']) ? $data['billing_address_2'] : '',
                'city'       => isset($data['billing_city']) ? $data['billing_city'] : '',
                'state'      => isset($data['billing_state']) ? $data['billing_state'] : '',
                'postcode'   => isset($data['billing_postcode']) ? $data['billing_postcode'] : '',
                'country'    => isset($data['billing_country']) ? $data['billing_country'] : '',
                'email'      => isset($data['billing_email']) ? $data['billing_email'] : '',
                'phone'      => isset($data['billing_phone']) ? $data['billing_phone'] : ''
            );

            $shipping = array(
                'first_name' => isset($data['shipping_first_name']) ? $data['shipping_first_name'] : '',
                'last_name'  => isset($data['shipping_last_name']) ? $data['shipping_last_name'] : '',
                'company'    => isset($data['shipping_company']) ? $data['shipping_company'] : '',
                'address_1'  => isset($data['shipping_address_1']) ? $data['shipping_address_1'] : '',
                'address_2'  => isset($data['shipping_address_2']) ? $data['shipping_address_2'] : '',
                'city'       => isset($data['shipping_city']) ? $data['shipping_city'] : '',
                'state'      => isset($data['shipping_state']) ? $data['shipping_state'] : '',
                'postcode'   => isset($data['shipping_postcode']) ? $data['shipping_postcode'] : '',
                'country'    => isset($data['shipping_country']) ? $data['shipping_country'] : ''
            );

            $order->set_address( $billing, 'billing' );
            $order->set_address( $shipping, 'shipping' );
            $order->set_payment_method( $this->id );
            $order->save();

            // Recupera CVU/Alias del pedido provisional
            $cvu   = get_post_meta( $order_id, '_cvu', true );
            $alias = get_post_meta( $order_id, '_alias', true );
            if ( !$cvu || !$alias ) {
                wc_add_notice( __( 'No se encontró el CVU/Alias. Por favor, inténtalo de nuevo.', 'woocommerce-custom-payments' ), 'error' );
                return;
            }

            // Cambiamos estado, vaciamos carrito, etc.
            $order->update_status( 'on-hold', __( 'Esperando transferencia.', 'woocommerce-custom-payments' ) );
            WC()->cart->empty_cart();
            WC()->session->__unset('wccp_provisional_order_id');

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order )
            );

        } else {
            // --- API DESACTIVADA: NO se crea pedido provisional ---

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                wc_add_notice( __( 'Error al procesar el pedido.', 'woocommerce-custom-payments' ), 'error' );
                return;
            }

            // Datos de checkout
            $data = WC()->checkout()->get_posted_data();
            if ( empty( $data ) ) {
                wc_add_notice( __( 'Faltan datos del checkout.', 'woocommerce-custom-payments' ), 'error' );
                return;
            }

            $billing = array(
                'first_name' => isset($data['billing_first_name']) ? $data['billing_first_name'] : '',
                'last_name'  => isset($data['billing_last_name']) ? $data['billing_last_name'] : '',
                'company'    => isset($data['billing_company']) ? $data['billing_company'] : '',
                'address_1'  => isset($data['billing_address_1']) ? $data['billing_address_1'] : '',
                'address_2'  => isset($data['billing_address_2']) ? $data['billing_address_2'] : '',
                'city'       => isset($data['billing_city']) ? $data['billing_city'] : '',
                'state'      => isset($data['billing_state']) ? $data['billing_state'] : '',
                'postcode'   => isset($data['billing_postcode']) ? $data['billing_postcode'] : '',
                'country'    => isset($data['billing_country']) ? $data['billing_country'] : '',
                'email'      => isset($data['billing_email']) ? $data['billing_email'] : '',
                'phone'      => isset($data['billing_phone']) ? $data['billing_phone'] : ''
            );

            $shipping = array(
                'first_name' => isset($data['shipping_first_name']) ? $data['shipping_first_name'] : '',
                'last_name'  => isset($data['shipping_last_name']) ? $data['shipping_last_name'] : '',
                'company'    => isset($data['shipping_company']) ? $data['shipping_company'] : '',
                'address_1'  => isset($data['shipping_address_1']) ? $data['shipping_address_1'] : '',
                'address_2'  => isset($data['shipping_address_2']) ? $data['shipping_address_2'] : '',
                'city'       => isset($data['shipping_city']) ? $data['shipping_city'] : '',
                'state'      => isset($data['shipping_state']) ? $data['shipping_state'] : '',
                'postcode'   => isset($data['shipping_postcode']) ? $data['shipping_postcode'] : '',
                'country'    => isset($data['shipping_country']) ? $data['shipping_country'] : ''
            );

            $order->set_address( $billing, 'billing' );
            $order->set_address( $shipping, 'shipping' );
            $order->set_payment_method( $this->id );
            $order->save();

            // Asignar CVU/Alias de respaldo al pedido final
            if ( ! empty( $this->backup_cvu ) && ! empty( $this->backup_alias ) ) {
                update_post_meta( $order_id, '_cvu', $this->backup_cvu );
                update_post_meta( $order_id, '_alias', $this->backup_alias );
            } else {
                wc_add_notice( __( 'No se encontró el CVU/Alias de respaldo. Verifica la configuración.', 'woocommerce-custom-payments' ), 'error' );
                return;
            }

            // Cambiamos a "on-hold" para esperar la transferencia
            $order->update_status( 'on-hold', __( 'Esperando transferencia (API inactiva).', 'woocommerce-custom-payments' ) );
            WC()->cart->empty_cart();

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order )
            );
        }
    }

    /**
     * Llama a la API externa para obtener CVU y Alias.
     * (Sólo se usa si la API está activada.)
     */
    public function get_cvu_alias_from_api( $number ) {
        $args = array(
            'headers' => array(
                'Authorization' => 'Token ' . $this->api_token
            ),
            'body' => array(
                'number' => $number
            ),
            'timeout' => 20
        );

        $response = wp_remote_post( $this->api_url, $args );
        if ( is_wp_error( $response ) ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $data['cvu'], $data['alias'] ) ) {
            return $data;
        }
        return false;
    }

    /**
     * Lógica de fallback: Se intenta la API y si falla, se usa el backup.
     * Esta función se llama sólo cuando la API está activa (desde AJAX).
     */
    public function get_cvu_alias_data( $order_id ) {
        // Primero intentamos la API
        $api_response = $this->get_cvu_alias_from_api( $order_id );
        if ( $api_response && ! empty( $api_response['cvu'] ) && ! empty( $api_response['alias'] ) ) {
            return array(
                'cvu'       => $api_response['cvu'],
                'alias'     => $api_response['alias'],
                'is_backup' => false,
            );
        }

        // Si falla, usar respaldo
        if ( ! empty( $this->backup_cvu ) && ! empty( $this->backup_alias ) ) {
            return array(
                'cvu'       => $this->backup_cvu,
                'alias'     => $this->backup_alias,
                'is_backup' => true,
            );
        }

        // Falla total
        return false;
    }

    /**
     * Página de "Gracias por tu compra"
     */
    public function thankyou_page( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order && $order->get_payment_method() === $this->id ) {
            $cvu   = get_post_meta( $order_id, '_cvu', true );
            $alias = get_post_meta( $order_id, '_alias', true );
            if ( $cvu && $alias ) {
                $days_left = $this->calculate_days_left( $order_id );
                echo '<h2>' . __( 'Instrucciones de Pago', 'woocommerce-custom-payments' ) . '</h2>';
                echo '<p><strong>' . __( 'CVU:', 'woocommerce-custom-payments' ) . '</strong> ' . esc_html( $cvu ) . '<br>';
                echo '<strong>' . __( 'Alias:', 'woocommerce-custom-payments' ) . '</strong> ' . esc_html( $alias ) . '</p>';
                echo '<p>' . sprintf( __( 'Tienes %d %s', 'woocommerce-custom-payments' ), $days_left, __( 'días para completar el pago.', 'woocommerce-custom-payments' ) ) . ' ' . __( 'Esta información también se envió a tu correo.', 'woocommerce-custom-payments' ) . '</p>';
            }
        }
    }

    /**
     * Instrucciones por correo
     */
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        if ( $sent_to_admin || $order->get_payment_method() !== $this->id ) {
            return;
        }
        $cvu   = get_post_meta( $order->get_id(), '_cvu', true );
        $alias = get_post_meta( $order->get_id(), '_alias', true );
        if ( $cvu && $alias ) {
            $days_left = $this->calculate_days_left( $order->get_id() );
            echo "\n<h2>" . __( 'Detalles para realizar tu pago', 'woocommerce-custom-payments' ) . "</h2>\n";
            echo '<p><strong>' . __( 'CVU:', 'woocommerce-custom-payments' ) . '</strong> ' . esc_html( $cvu ) . '<br>';
            echo '<strong>' . __( 'Alias:', 'woocommerce-custom-payments' ) . '</strong> ' . esc_html( $alias ) . '<br>';
            echo sprintf( __( 'Tienes %d días para completar el pago.', 'woocommerce-custom-payments' ), $days_left ) . ' ' . __( 'Gracias por tu compra.', 'woocommerce-custom-payments' );
            echo '</p>';
        }
    }

    /**
     * Mostrar datos en el admin de WooCommerce
     */
    public function display_order_meta_admin( $order ) {
        if ( $order->get_payment_method() !== $this->id ) {
            return;
        }

        static $printed = false;
        if ( $printed ) {
            return;
        }
        $printed = true;

        $order_id = $order->get_id();
        $cvu      = get_post_meta( $order_id, '_cvu', true );
        $alias    = get_post_meta( $order_id, '_alias', true );
        if ( $cvu && $alias ) {
            $days_left = $this->calculate_days_left( $order_id );
            echo '<div style="margin-top:20px; padding:10px; border:1px solid #ccc; background:#f9f9f9;">';
            echo '<h3>' . __( 'Detalles de Pago CVU/Alias', 'woocommerce-custom-payments' ) . '</h3>';
            echo '<p><strong>' . __( 'CVU:', 'woocommerce-custom-payments' ) . '</strong> ' . esc_html( $cvu ) . '<br>';
            echo '<strong>' . __( 'Alias:', 'woocommerce-custom-payments' ) . '</strong> ' . esc_html( $alias ) . '</p>';
            if ( $this->show_days ) {
                echo '<p><strong>' . __( 'Días restantes:', 'woocommerce-custom-payments' ) . '</strong> ' . intval( $days_left ) . '</p>';
            }

            $url = wp_nonce_url( add_query_arg( array(
                'wccp_action' => 'send_reminder',
                'order_id'    => $order_id
            ), admin_url('edit.php?post_type=shop_order')), 'wccp_send_reminder' );
            echo '<p><a class="button" href="' . esc_url( $url ) . '">' . __( 'Enviar correo recordatorio', 'woocommerce-custom-payments' ) . '</a></p>';
            echo '</div>';
        }
    }

    /**
     * Acción manual para enviar recordatorio
     */
    public function handle_manual_reminder_action() {
        if ( isset( $_GET['wccp_action'], $_GET['order_id'] ) && $_GET['wccp_action'] === 'send_reminder' ) {
            if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'wccp_send_reminder' ) ) {
                return;
            }
            $order_id = intval( $_GET['order_id'] );
            $order = wc_get_order( $order_id );
            if ( $order && $order->get_payment_method() === $this->id ) {
                $this->send_reminder_email( $order_id );
                wp_redirect( remove_query_arg( array('wccp_action','order_id','_wpnonce') ) );
                exit;
            }
        }
    }

    /**
     * Hook al cambiar un pedido a on-hold
     */
    public function send_on_hold_email( $order_id, $order ) {
        if ( $order->get_payment_method() === $this->id ) {
            $this->send_reminder_email( $order_id, true );
        }
    }

    private function send_reminder_email( $order_id, $on_hold = false ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $cvu   = get_post_meta( $order_id, '_cvu', true );
        $alias = get_post_meta( $order_id, '_alias', true );
        if ( ! $cvu || ! $alias ) return;

        $days_left = $this->calculate_days_left( $order_id );
        $to        = $order->get_billing_email();
        $blogname  = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
        $headers   = array('Content-Type: text/html; charset=UTF-8');

        if ( $on_hold ) {
            $subject = __( 'Datos para realizar tu pago', 'woocommerce-custom-payments' );
            $message = '<h2>' . sprintf( __( 'Tu pedido en %s está en espera de pago', 'woocommerce-custom-payments' ), $blogname ) . '</h2>';
            $message .= '<p>' . sprintf( __( 'Se ha asignado un CVU/Alias. Tienes %d días para completar la transferencia.', 'woocommerce-custom-payments' ), $days_left ) . '</p>';
        } else {
            $subject = __( 'Recordatorio de pago pendiente', 'woocommerce-custom-payments' );
            $message = '<h2>' . __( 'Recordatorio de pago', 'woocommerce-custom-payments' ) . '</h2>';
            $message .= '<p>' . sprintf( __( 'Faltan %d días para completar el pago de tu pedido en %s.', 'woocommerce-custom-payments' ), $days_left, $blogname ) . '</p>';
        }

        $message .= '<p><strong>' . __( 'CVU:', 'woocommerce-custom-payments' ) . '</strong> ' . esc_html( $cvu ) . '<br>';
        $message .= '<strong>' . __( 'Alias:', 'woocommerce-custom-payments' ) . '</strong> ' . esc_html( $alias ) . '</p>';
        $message .= '<p>' . __( 'Realiza la transferencia antes de que se agote el tiempo. Una vez confirmada, procesaremos tu pedido.', 'woocommerce-custom-payments' ) . '</p>';
        $message .= '<p>' . __( 'Gracias por elegirnos.', 'woocommerce-custom-payments' ) . '</p>';

        wp_mail( $to, $subject, $message, $headers );
    }

    private function calculate_days_left( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return $this->order_expiration_days;
        $created = $order->get_date_created();
        if ( ! $created ) return $this->order_expiration_days;

        $expiration = $created->getTimestamp() + ( $this->order_expiration_days * DAY_IN_SECONDS );
        $now        = current_time( 'timestamp' );
        $diff       = ceil( ( $expiration - $now ) / DAY_IN_SECONDS );
        return max( $diff, 0 );
    }

    public function is_2days_reminder_enabled() {
        return $this->enable_2days_reminder;
    }

    public function get_expiration_days() {
        return $this->order_expiration_days;
    }
}
