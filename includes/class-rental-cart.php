<?php
/**
 * Clase para manejar la integración del carrito con alquileres
 * Archivo: includes/class-rental-cart.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Rental_Cart {
    
    /**
     * Instance
     */
    private static $instance = null;
    
    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        // Validación antes de añadir al carrito
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_rental_add_to_cart'), 10, 5);
        
        // Añadir datos personalizados al item del carrito
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_rental_data_to_cart'), 10, 3);
        
        // Mostrar datos de alquiler en el carrito
        add_filter('woocommerce_get_item_data', array($this, 'display_rental_data_in_cart'), 10, 2);
        
        // Calcular precio personalizado
        add_action('woocommerce_before_calculate_totals', array($this, 'calculate_rental_price'), 20, 1);
        
        // Validar disponibilidad en checkout
        add_action('woocommerce_check_cart_items', array($this, 'validate_cart_rental_availability'));
        
        // Guardar datos en el pedido
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_rental_data_to_order'), 10, 4);
        
        // Validación de cantidad (siempre 1 para alquileres)
        add_filter('woocommerce_add_to_cart_quantity', array($this, 'force_single_quantity'), 10, 2);
        
        // AJAX handlers
        add_action('wp_ajax_add_rental_to_cart', array($this, 'ajax_add_to_cart'));
        add_action('wp_ajax_nopriv_add_rental_to_cart', array($this, 'ajax_add_to_cart'));
    }
    
    /**
     * Validar antes de añadir al carrito
     */
    public function validate_rental_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = array()) {
        $product = wc_get_product($variation_id ? $variation_id : $product_id);
        
        // Verificar si es producto de alquiler
        if (!$this->is_rental_product($product)) {
            return $passed;
        }
        
        // Verificar fechas
        if (!isset($_POST['rental_start_date']) || !isset($_POST['rental_end_date'])) {
            wc_add_notice(__('Por favor selecciona las fechas de alquiler.', 'wc-rental-system'), 'error');
            return false;
        }
        
        $start_date = sanitize_text_field($_POST['rental_start_date']);
        $end_date = sanitize_text_field($_POST['rental_end_date']);
        
        // Validar formato de fechas
        $start_timestamp = strtotime($start_date);
        $end_timestamp = strtotime($end_date);
        
        if (!$start_timestamp || !$end_timestamp) {
            wc_add_notice(__('Formato de fecha inválido.', 'wc-rental-system'), 'error');
            return false;
        }
        
        // Verificar que la fecha de inicio no sea pasada
        if ($start_timestamp < strtotime('today')) {
            wc_add_notice(__('La fecha de inicio no puede ser anterior a hoy.', 'wc-rental-system'), 'error');
            return false;
        }
        
        // Verificar que la fecha de fin sea posterior al inicio
        if ($end_timestamp <= $start_timestamp) {
            wc_add_notice(__('La fecha de fin debe ser posterior a la fecha de inicio.', 'wc-rental-system'), 'error');
            return false;
        }
        
        // Calcular días
        $rental_days = ceil(($end_timestamp - $start_timestamp) / DAY_IN_SECONDS);
        
        // Verificar días mínimos y máximos
        $min_days = get_post_meta($product_id, '_rental_min_days', true) ?: 1;
        $max_days = get_post_meta($product_id, '_rental_max_days', true) ?: 365;
        
        if ($rental_days < $min_days) {
            wc_add_notice(sprintf(__('El período mínimo de alquiler es de %d días.', 'wc-rental-system'), $min_days), 'error');
            return false;
        }
        
        if ($rental_days > $max_days) {
            wc_add_notice(sprintf(__('El período máximo de alquiler es de %d días.', 'wc-rental-system'), $max_days), 'error');
            return false;
        }
        
        // Verificar disponibilidad
        $availability_checker = WC_Rental_Availability_Checker::get_instance();
        $actual_product_id = $variation_id ? $variation_id : $product_id;
        
        if (!$availability_checker->is_available($actual_product_id, $start_date, $end_date)) {
            wc_add_notice(__('El producto no está disponible para las fechas seleccionadas.', 'wc-rental-system'), 'error');
            return false;
        }
        
        // Verificar si hay conflicto con otros items en el carrito
        if ($this->has_cart_conflict($actual_product_id, $start_date, $end_date)) {
            wc_add_notice(__('Ya tienes este producto en el carrito para fechas que se superponen.', 'wc-rental-system'), 'error');
            return false;
        }
        
        // Forzar cantidad a 1
        if ($quantity != 1) {
            $_POST['quantity'] = 1;
        }
        
        return $passed;
    }
    
    /**
     * Añadir datos de alquiler al item del carrito
     */
    public function add_rental_data_to_cart($cart_item_data, $product_id, $variation_id) {
        $product = wc_get_product($variation_id ? $variation_id : $product_id);
        
        if (!$this->is_rental_product($product)) {
            return $cart_item_data;
        }
        
        if (isset($_POST['rental_start_date']) && isset($_POST['rental_end_date'])) {
            $start_date = sanitize_text_field($_POST['rental_start_date']);
            $end_date = sanitize_text_field($_POST['rental_end_date']);
            
            // Calcular días y precio
            $rental_days = ceil((strtotime($end_date) - strtotime($start_date)) / DAY_IN_SECONDS);
            
            // Obtener configuración de garantía
            $deposit_type = get_post_meta($product_id, '_rental_deposit_type', true);
            $deposit_amount = get_post_meta($product_id, '_rental_deposit_amount', true);
            
            // Calcular garantía
            $base_price = $product->get_price();
            $rental_price = $base_price * $rental_days;
            
            if ($deposit_type === 'percentage') {
                $deposit = ($rental_price * $deposit_amount) / 100;
            } else {
                $deposit = $deposit_amount;
            }
            
            $cart_item_data['rental_data'] = array(
                'start_date' => $start_date,
                'end_date' => $end_date,
                'rental_days' => $rental_days,
                'base_price' => $base_price,
                'rental_price' => $rental_price,
                'deposit' => $deposit,
                'deposit_type' => $deposit_type,
                'deposit_amount' => $deposit_amount,
                'product_id' => $variation_id ? $variation_id : $product_id
            );
            
            // Hacer único el item para evitar agrupación
            $cart_item_data['unique_key'] = md5(microtime().rand());
        }
        
        return $cart_item_data;
    }
    
    /**
     * Mostrar datos de alquiler en el carrito
     */
    public function display_rental_data_in_cart($item_data, $cart_item) {
        if (empty($cart_item['rental_data'])) {
            return $item_data;
        }
        
        $rental_data = $cart_item['rental_data'];
        
        // Fecha de inicio
        $item_data[] = array(
            'name' => __('Fecha de inicio', 'wc-rental-system'),
            'value' => date_i18n(get_option('date_format'), strtotime($rental_data['start_date']))
        );
        
        // Fecha de fin
        $item_data[] = array(
            'name' => __('Fecha de devolución', 'wc-rental-system'),
            'value' => date_i18n(get_option('date_format'), strtotime($rental_data['end_date']))
        );
        
        // Días de alquiler
        $item_data[] = array(
            'name' => __('Días de alquiler', 'wc-rental-system'),
            'value' => $rental_data['rental_days']
        );
        
        // Precio por día
        $item_data[] = array(
            'name' => __('Precio por día', 'wc-rental-system'),
            'value' => wc_price($rental_data['base_price'])
        );
        
        // Subtotal alquiler
        $item_data[] = array(
            'name' => __('Subtotal alquiler', 'wc-rental-system'),
            'value' => wc_price($rental_data['rental_price'])
        );
        
        // Garantía
        if ($rental_data['deposit'] > 0) {
            $item_data[] = array(
                'name' => __('Garantía (reembolsable)', 'wc-rental-system'),
                'value' => wc_price($rental_data['deposit'])
            );
            
            // Total con garantía
            $item_data[] = array(
                'name' => __('Total con garantía', 'wc-rental-system'),
                'value' => '<strong>' . wc_price($rental_data['rental_price'] + $rental_data['deposit']) . '</strong>'
            );
        }
        
        return $item_data;
    }
    
    /**
     * Calcular precio personalizado
     */
    public function calculate_rental_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        if (did_action('woocommerce_before_calculate_totals') >= 2) {
            return;
        }
        
        foreach ($cart->get_cart() as $cart_item) {
            if (isset($cart_item['rental_data'])) {
                $rental_data = $cart_item['rental_data'];
                // Establecer el precio total (alquiler + garantía)
                $total_price = $rental_data['rental_price'] + $rental_data['deposit'];
                $cart_item['data']->set_price($total_price);
            }
        }
    }
    
    /**
     * Validar disponibilidad en checkout
     */
    public function validate_cart_rental_availability() {
        $cart = WC()->cart->get_cart();
        $availability_checker = WC_Rental_Availability_Checker::get_instance();
        
        foreach ($cart as $cart_item) {
            if (isset($cart_item['rental_data'])) {
                $rental_data = $cart_item['rental_data'];
                $product_id = $rental_data['product_id'];
                
                // Verificar disponibilidad nuevamente
                if (!$availability_checker->is_available($product_id, $rental_data['start_date'], $rental_data['end_date'])) {
                    $product = wc_get_product($product_id);
                    wc_add_notice(
                        sprintf(
                            __('El producto "%s" ya no está disponible para las fechas seleccionadas. Por favor, elimínalo del carrito.', 'wc-rental-system'),
                            $product->get_name()
                        ),
                        'error'
                    );
                }
            }
        }
    }
    
    /**
     * Guardar datos de alquiler en el pedido
     */
    public function save_rental_data_to_order($item, $cart_item_key, $values, $order) {
        if (isset($values['rental_data'])) {
            $rental_data = $values['rental_data'];
            
            // Añadir meta data al item del pedido
            $item->add_meta_data('_rental_start_date', $rental_data['start_date']);
            $item->add_meta_data('_rental_end_date', $rental_data['end_date']);
            $item->add_meta_data('_rental_days', $rental_data['rental_days']);
            $item->add_meta_data('_rental_price', $rental_data['rental_price']);
            $item->add_meta_data('_rental_deposit', $rental_data['deposit']);
            
            // Añadir meta visible
            $item->add_meta_data(__('Fecha de inicio', 'wc-rental-system'), 
                date_i18n(get_option('date_format'), strtotime($rental_data['start_date'])));
            $item->add_meta_data(__('Fecha de devolución', 'wc-rental-system'), 
                date_i18n(get_option('date_format'), strtotime($rental_data['end_date'])));
            $item->add_meta_data(__('Días de alquiler', 'wc-rental-system'), $rental_data['rental_days']);
            $item->add_meta_data(__('Garantía', 'wc-rental-system'), wc_price($rental_data['deposit']));
        }
    }
    
    /**
     * Forzar cantidad 1 para productos de alquiler
     */
    public function force_single_quantity($quantity, $product_id) {
        $product = wc_get_product($product_id);
        
        if ($this->is_rental_product($product)) {
            return 1;
        }
        
        return $quantity;
    }
    
    /**
     * AJAX handler para añadir al carrito
     */
    public function ajax_add_to_cart() {
        check_ajax_referer('wc_rental_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
        $quantity = 1; // Siempre 1 para alquileres
        
        // Establecer las fechas en POST para que las procese el filtro
        $_POST['rental_start_date'] = sanitize_text_field($_POST['start_date']);
        $_POST['rental_end_date'] = sanitize_text_field($_POST['end_date']);
        
        // Intentar añadir al carrito
        if ($variation_id) {
            $result = WC()->cart->add_to_cart($product_id, $quantity, $variation_id);
        } else {
            $result = WC()->cart->add_to_cart($product_id, $quantity);
        }
        
        if ($result) {
            // Obtener fragmentos del carrito actualizados
            WC_AJAX::get_refreshed_fragments();
        } else {
            // Obtener mensajes de error
            $notices = wc_get_notices('error');
            $error_message = !empty($notices) ? $notices[0]['notice'] : __('Error al añadir al carrito.', 'wc-rental-system');
            
            wp_send_json_error(array(
                'message' => $error_message
            ));
        }
        
        wp_die();
    }
    
    /**
     * Verificar si es producto de alquiler
     */
    private function is_rental_product($product) {
        if (!$product) {
            return false;
        }
        
        $product_id = $product->get_parent_id() ?: $product->get_id();
        return get_post_meta($product_id, '_is_rental', true) === 'yes';
    }
    
    /**
     * Verificar conflicto con items en el carrito
     */
    private function has_cart_conflict($product_id, $start_date, $end_date) {
        $cart = WC()->cart->get_cart();
        
        foreach ($cart as $cart_item) {
            if (isset($cart_item['rental_data'])) {
                $rental_data = $cart_item['rental_data'];
                
                // Si es el mismo producto
                if ($rental_data['product_id'] == $product_id) {
                    // Verificar superposición de fechas
                    $existing_start = strtotime($rental_data['start_date']);
                    $existing_end = strtotime($rental_data['end_date']);
                    $new_start = strtotime($start_date);
                    $new_end = strtotime($end_date);
                    
                    // Hay superposición si:
                    // - El nuevo inicio está entre las fechas existentes
                    // - El nuevo fin está entre las fechas existentes  
                    // - Las nuevas fechas engloban las existentes
                    if (($new_start >= $existing_start && $new_start <= $existing_end) ||
                        ($new_end >= $existing_start && $new_end <= $existing_end) ||
                        ($new_start <= $existing_start && $new_end >= $existing_end)) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
}

// Inicializar
add_action('init', function() {
    WC_Rental_Cart::get_instance();
});