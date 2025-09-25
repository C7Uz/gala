<?php
/**
 * Clase para manejar la página de producto en el frontend
 * Archivo: includes/frontend/class-product-page.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Rental_Product_Page {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Hooks para productos simples y variables
        add_action('woocommerce_before_add_to_cart_button', array($this, 'render_rental_fields'));
        
        // Añadir datos al carrito
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_rental_data_to_cart'), 10, 3);
        
        // Validación antes de añadir al carrito
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_rental_data'), 10, 5);
        
        // Mostrar datos en el carrito
        add_filter('woocommerce_get_item_data', array($this, 'display_rental_data_in_cart'), 10, 2);
        
        // Modificar el precio en el carrito
        add_action('woocommerce_before_calculate_totals', array($this, 'update_cart_item_price'));
        
        // Añadir badge de producto en alquiler
        add_action('woocommerce_single_product_summary', array($this, 'add_rental_badge'), 5);
        
        // Mostrar calendario de disponibilidad
        add_action('woocommerce_after_single_product_summary', array($this, 'render_availability_calendar'), 15);
        
        // Añadir información de alquiler en el resumen
        add_action('woocommerce_single_product_summary', array($this, 'render_rental_info'), 25);
        
        // Scripts y estilos específicos para la página de producto
        add_action('wp_enqueue_scripts', array($this, 'enqueue_product_scripts'));
        
        // Ajax handlers
        add_action('wp_ajax_check_product_availability', array($this, 'ajax_check_availability'));
        add_action('wp_ajax_nopriv_check_product_availability', array($this, 'ajax_check_availability'));
        add_action('wp_ajax_calculate_rental_price', array($this, 'ajax_calculate_price'));
        add_action('wp_ajax_nopriv_calculate_rental_price', array($this, 'ajax_calculate_price'));
        add_action('wp_ajax_get_blocked_dates', array($this, 'ajax_get_blocked_dates'));
        add_action('wp_ajax_nopriv_get_blocked_dates', array($this, 'ajax_get_blocked_dates'));
        
        // Modificar el texto del botón de compra
        add_filter('woocommerce_product_single_add_to_cart_text', array($this, 'change_add_to_cart_text'), 10, 2);
        add_filter('woocommerce_product_add_to_cart_text', array($this, 'change_add_to_cart_text'), 10, 2);
        
        // Ocultar el precio regular para productos en alquiler
        add_filter('woocommerce_get_price_html', array($this, 'modify_price_display'), 10, 2);
    }
    
    /**
     * Renderizar campos de alquiler
     */
    public function render_rental_fields() {
        global $product;
        
        // Verificar si es producto en alquiler
        if (!$this->is_rentable_product($product)) {
            return;
        }
        
        // Obtener configuración del producto
        $rental_settings = $this->get_product_rental_settings($product->get_id());
        
        ?>
        <div class="rental-booking-form" data-product-id="<?php echo esc_attr($product->get_id()); ?>">
            
            <!-- Información de alquiler -->
            <div class="rental-info-box">
                <h4><?php _e('Información de Alquiler', 'wc-rental-system'); ?></h4>
                <ul>
                    <li>
                        <span class="dashicons dashicons-calendar-alt"></span>
                        <?php printf(__('Mínimo %d días, máximo %d días', 'wc-rental-system'), 
                            $rental_settings['min_rental_days'], 
                            $rental_settings['max_rental_days']); ?>
                    </li>
                    
                    <?php if ($rental_settings['deposit_percentage'] > 0 || $rental_settings['deposit_fixed'] > 0) : ?>
                    <li>
                        <span class="dashicons dashicons-shield"></span>
                        <?php 
                        if ($rental_settings['deposit_percentage'] > 0) {
                            printf(__('Garantía: %s%% del total', 'wc-rental-system'), $rental_settings['deposit_percentage']);
                        } else {
                            printf(__('Garantía: %s', 'wc-rental-system'), wc_price($rental_settings['deposit_fixed']));
                        }
                        ?>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($rental_settings['grace_period_days'] > 0) : ?>
                    <li>
                        <span class="dashicons dashicons-clock"></span>
                        <?php printf(__('Período de gracia: %d día(s) para limpieza', 'wc-rental-system'), 
                            $rental_settings['grace_period_days']); ?>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            
            <!-- Selector de fechas -->
            <div class="rental-date-selector">
                <h4><?php _e('Selecciona las Fechas de Alquiler', 'wc-rental-system'); ?></h4>
                
                <div class="rental-dates-grid">
                    <div class="rental-date-field">
                        <label for="rental_start_date">
                            <?php _e('Fecha de Inicio', 'wc-rental-system'); ?> 
                            <span class="required">*</span>
                        </label>
                        <input type="text" 
                               id="rental_start_date" 
                               name="rental_start_date" 
                               class="rental-datepicker start-date" 
                               placeholder="<?php _e('Seleccionar fecha', 'wc-rental-system'); ?>"
                               data-min-days="<?php echo esc_attr($rental_settings['min_rental_days']); ?>"
                               data-max-days="<?php echo esc_attr($rental_settings['max_rental_days']); ?>"
                               readonly required>
                    </div>
                    
                    <div class="rental-date-field">
                        <label for="rental_end_date">
                            <?php _e('Fecha de Devolución', 'wc-rental-system'); ?> 
                            <span class="required">*</span>
                        </label>
                        <input type="text" 
                               id="rental_end_date" 
                               name="rental_end_date" 
                               class="rental-datepicker end-date" 
                               placeholder="<?php _e('Seleccionar fecha', 'wc-rental-system'); ?>"
                               readonly required>
                    </div>
                </div>
                
                <!-- Días seleccionados -->
                <div class="rental-days-display" style="display: none;">
                    <p>
                        <strong><?php _e('Duración del alquiler:', 'wc-rental-system'); ?></strong> 
                        <span id="rental-days-count">0</span> <?php _e('días', 'wc-rental-system'); ?>
                    </p>
                </div>
                
                <!-- Verificación de disponibilidad -->
                <div class="rental-availability-status">
                    <button type="button" class="button check-availability-btn" style="display: none;">
                        <?php _e('Verificar Disponibilidad', 'wc-rental-system'); ?>
                    </button>
                    <div class="availability-message"></div>
                </div>
            </div>
            
            <!-- Resumen de precio -->
            <div class="rental-price-summary" style="display: none;">
                <h4><?php _e('Resumen del Alquiler', 'wc-rental-system'); ?></h4>
                
                <table class="rental-price-table">
                    <tbody>
                        <tr class="rental-base-price">
                            <td><?php _e('Precio por día:', 'wc-rental-system'); ?></td>
                            <td class="price"><span id="daily-price">-</span></td>
                        </tr>
                        <tr class="rental-duration">
                            <td><?php _e('Duración:', 'wc-rental-system'); ?></td>
                            <td><span id="rental-duration">-</span></td>
                        </tr>
                        <tr class="rental-subtotal">
                            <td><?php _e('Subtotal:', 'wc-rental-system'); ?></td>
                            <td class="price"><span id="rental-subtotal">-</span></td>
                        </tr>
                        
                        <?php if ($rental_settings['deposit_percentage'] > 0 || $rental_settings['deposit_fixed'] > 0) : ?>
                        <tr class="rental-deposit">
                            <td><?php _e('Garantía:', 'wc-rental-system'); ?></td>
                            <td class="price"><span id="rental-deposit">-</span></td>
                        </tr>
                        <?php endif; ?>
                        
                        <tr class="rental-total">
                            <td><strong><?php _e('Total:', 'wc-rental-system'); ?></strong></td>
                            <td class="price"><strong><span id="rental-total">-</span></strong></td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="rental-deposit-notice">
                    <p class="notice">
                        <span class="dashicons dashicons-info"></span>
                        <?php _e('La garantía será devuelta al finalizar el alquiler en perfectas condiciones.', 'wc-rental-system'); ?>
                    </p>
                </div>
            </div>
            
            <!-- Campos ocultos para el carrito -->
            <input type="hidden" name="rental_price" id="rental_price" value="">
            <input type="hidden" name="rental_deposit" id="rental_deposit" value="">
            <input type="hidden" name="rental_days" id="rental_days" value="">
            <input type="hidden" name="is_rental" value="1">
        </div>
        
        <style>
            .rental-booking-form {
                margin: 20px 0;
                padding: 20px;
                background: #f9f9f9;
                border-radius: 5px;
                border: 1px solid #ddd;
            }
            
            .rental-info-box {
                background: #fff;
                padding: 15px;
                border-radius: 3px;
                margin-bottom: 20px;
            }
            
            .rental-info-box h4 {
                margin-top: 0;
                color: #333;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }
            
            .rental-info-box ul {
                list-style: none;
                margin: 0;
                padding: 0;
            }
            
            .rental-info-box li {
                padding: 8px 0;
                display: flex;
                align-items: center;
            }
            
            .rental-info-box .dashicons {
                margin-right: 8px;
                color: #0073aa;
            }
            
            .rental-date-selector h4 {
                margin-bottom: 15px;
                color: #333;
            }
            
            .rental-dates-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
                margin-bottom: 20px;
            }
            
            .rental-date-field label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
            }
            
            .rental-date-field input {
                width: 100%;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 3px;
                background: #fff;
            }
            
            .rental-date-field .required {
                color: #d00;
            }
            
            .rental-days-display {
                background: #e8f5e9;
                padding: 10px;
                border-radius: 3px;
                margin: 15px 0;
                text-align: center;
            }
            
            .rental-availability-status {
                margin: 20px 0;
                text-align: center;
            }
            
            .check-availability-btn {
                background: #0073aa !important;
                color: #fff !important;
                padding: 10px 20px !important;
                font-size: 14px !important;
            }
            
            .availability-message {
                margin-top: 10px;
                padding: 10px;
                border-radius: 3px;
                display: none;
            }
            
            .availability-message.success {
                background: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
                display: block;
            }
            
            .availability-message.error {
                background: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
                display: block;
            }
            
            .availability-message.loading {
                background: #cce5ff;
                color: #004085;
                border: 1px solid #b8daff;
                display: block;
            }
            
            .rental-price-summary {
                background: #fff;
                padding: 20px;
                border-radius: 3px;
                margin-top: 20px;
            }
            
            .rental-price-summary h4 {
                margin-top: 0;
                color: #333;
                border-bottom: 1px solid #eee;
                padding-bottom: 10px;
            }
            
            .rental-price-table {
                width: 100%;
                margin: 15px 0;
            }
            
            .rental-price-table td {
                padding: 8px 0;
                vertical-align: middle;
            }
            
            .rental-price-table td:last-child {
                text-align: right;
            }
            
            .rental-price-table .rental-total td {
                border-top: 2px solid #ddd;
                padding-top: 15px;
                font-size: 1.2em;
            }
            
            .rental-deposit-notice {
                background: #fff3cd;
                padding: 10px;
                border-radius: 3px;
                border: 1px solid #ffeaa7;
            }
            
            .rental-deposit-notice p {
                margin: 0;
                display: flex;
                align-items: center;
            }
            
            .rental-deposit-notice .dashicons {
                margin-right: 8px;
                color: #856404;
            }
            
            @media (max-width: 768px) {
                .rental-dates-grid {
                    grid-template-columns: 1fr;
                }
            }
            
            /* Estados del formulario */
            .rental-booking-form.checking {
                opacity: 0.7;
                pointer-events: none;
            }
            
            .rental-booking-form.available {
                border-color: #5cb85c;
            }
            
            .rental-booking-form.unavailable {
                border-color: #d9534f;
            }
            
            /* Calendario inline */
            .rental-calendar-inline {
                background: #fff;
                padding: 15px;
                border-radius: 3px;
                margin-top: 15px;
            }
            
            .ui-datepicker-inline {
                width: 100% !important;
            }
            
            .ui-state-disabled {
                background: #ffebee !important;
                opacity: 0.6 !important;
            }
            
            .ui-state-disabled .ui-state-default {
                color: #999 !important;
            }
            
            .rental-date-blocked {
                background: #ffcdd2 !important;
            }
            
            .rental-date-selected {
                background: #c8e6c9 !important;
            }
            
            .rental-date-range {
                background: #e8f5e9 !important;
            }
        </style>
        <?php
    }
    
    /**
     * Renderizar calendario de disponibilidad
     */
    public function render_availability_calendar() {
        global $product;
        
        if (!$this->is_rentable_product($product)) {
            return;
        }
        
        ?>
        <div class="rental-availability-calendar">
            <h3><?php _e('Calendario de Disponibilidad', 'wc-rental-system'); ?></h3>
            <div id="availability-calendar" 
                 data-product-id="<?php echo esc_attr($product->get_id()); ?>">
            </div>
            
            <div class="calendar-legend">
                <div class="legend-item">
                    <span class="color-box available"></span>
                    <?php _e('Disponible', 'wc-rental-system'); ?>
                </div>
                <div class="legend-item">
                    <span class="color-box unavailable"></span>
                    <?php _e('No disponible', 'wc-rental-system'); ?>
                </div>
                <div class="legend-item">
                    <span class="color-box selected"></span>
                    <?php _e('Seleccionado', 'wc-rental-system'); ?>
                </div>
            </div>
        </div>
        
        <style>
            .rental-availability-calendar {
                margin: 30px 0;
                padding: 20px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 5px;
            }
            
            .rental-availability-calendar h3 {
                margin-top: 0;
                padding-bottom: 15px;
                border-bottom: 1px solid #eee;
            }
            
            #availability-calendar {
                margin: 20px 0;
            }
            
            .calendar-legend {
                display: flex;
                gap: 20px;
                margin-top: 15px;
                padding-top: 15px;
                border-top: 1px solid #eee;
            }
            
            .legend-item {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .color-box {
                width: 20px;
                height: 20px;
                border-radius: 3px;
                border: 1px solid #ddd;
            }
            
            .color-box.available {
                background: #e8f5e9;
            }
            
            .color-box.unavailable {
                background: #ffebee;
            }
            
            .color-box.selected {
                background: #bbdefb;
            }
        </style>
        <?php
    }
    
    /**
     * Renderizar información adicional de alquiler
     */
    public function render_rental_info() {
        global $product;
        
        if (!$this->is_rentable_product($product)) {
            return;
        }
        
        ?>
        <div class="rental-additional-info">
            <h4><?php _e('Condiciones de Alquiler', 'wc-rental-system'); ?></h4>
            <ul>
                <li><?php _e('Presentar documento de identidad al recoger', 'wc-rental-system'); ?></li>
                <li><?php _e('El producto debe devolverse en las mismas condiciones', 'wc-rental-system'); ?></li>
                <li><?php _e('Cargos adicionales por daños o retraso en la devolución', 'wc-rental-system'); ?></li>
                <li><?php _e('Cancelación gratuita hasta 48h antes', 'wc-rental-system'); ?></li>
            </ul>
        </div>
        <?php
    }
    
    /**
     * Añadir badge de producto en alquiler
     */
    public function add_rental_badge() {
        global $product;
        
        if (!$this->is_rentable_product($product)) {
            return;
        }
        
        echo '<div class="rental-badge">' . __('Producto en Alquiler', 'wc-rental-system') . '</div>';
        ?>
        <style>
            .rental-badge {
                display: inline-block;
                background: #ff9800;
                color: #fff;
                padding: 5px 10px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: bold;
                text-transform: uppercase;
                margin-bottom: 10px;
            }
        </style>
        <?php
    }
    
    /**
     * Validar datos de alquiler antes de añadir al carrito
     */
    public function validate_rental_data($passed, $product_id, $quantity, $variation_id = 0, $variations = array()) {
        // Si no es un producto en alquiler, continuar normalmente
        if (!isset($_POST['is_rental']) || $_POST['is_rental'] != '1') {
            return $passed;
        }
        
        // Validar fechas
        if (empty($_POST['rental_start_date']) || empty($_POST['rental_end_date'])) {
            wc_add_notice(__('Por favor selecciona las fechas de alquiler.', 'wc-rental-system'), 'error');
            return false;
        }
        
        $start_date = sanitize_text_field($_POST['rental_start_date']);
        $end_date = sanitize_text_field($_POST['rental_end_date']);
        
        // Validar formato de fechas
        $start = DateTime::createFromFormat('Y-m-d', $start_date);
        $end = DateTime::createFromFormat('Y-m-d', $end_date);
        
        if (!$start || !$end) {
            wc_add_notice(__('Las fechas seleccionadas no son válidas.', 'wc-rental-system'), 'error');
            return false;
        }
        
        // Validar que la fecha de inicio sea futura
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        
        if ($start < $today) {
            wc_add_notice(__('La fecha de inicio debe ser futura.', 'wc-rental-system'), 'error');
            return false;
        }
        
        // Validar que la fecha de fin sea posterior a la de inicio
        if ($end <= $start) {
            wc_add_notice(__('La fecha de devolución debe ser posterior a la fecha de inicio.', 'wc-rental-system'), 'error');
            return false;
        }
        
        // Obtener configuración del producto
        $rental_settings = $this->get_product_rental_settings($product_id, $variation_id);
        
        // Calcular días de alquiler
        $interval = $start->diff($end);
        $rental_days = $interval->days;
        
        // Validar días mínimos y máximos
        if ($rental_days < $rental_settings['min_rental_days']) {
            wc_add_notice(
                sprintf(__('El alquiler mínimo es de %d días.', 'wc-rental-system'), $rental_settings['min_rental_days']), 
                'error'
            );
            return false;
        }
        
        if ($rental_days > $rental_settings['max_rental_days']) {
            wc_add_notice(
                sprintf(__('El alquiler máximo es de %d días.', 'wc-rental-system'), $rental_settings['max_rental_days']), 
                'error'
            );
            return false;
        }
        
        // Verificar disponibilidad
        if (!$this->check_availability($product_id, $variation_id, $start_date, $end_date)) {
            wc_add_notice(__('El producto no está disponible para las fechas seleccionadas.', 'wc-rental-system'), 'error');
            return false;
        }
        
        // Validar cantidad (solo 1 unidad por alquiler)
        if ($quantity > 1) {
            wc_add_notice(__('Solo se puede alquilar 1 unidad de este producto a la vez.', 'wc-rental-system'), 'error');
            return false;
        }
        
        return $passed;
    }
    
    /**
     * Añadir datos de alquiler al carrito
     */
    public function add_rental_data_to_cart($cart_item_data, $product_id, $variation_id) {
        if (isset($_POST['is_rental']) && $_POST['is_rental'] == '1') {
            $cart_item_data['rental_data'] = array(
                'start_date' => sanitize_text_field($_POST['rental_start_date']),
                'end_date' => sanitize_text_field($_POST['rental_end_date']),
                'rental_days' => intval($_POST['rental_days']),
                'rental_price' => floatval($_POST['rental_price']),
                'rental_deposit' => floatval($_POST['rental_deposit']),
                'is_rental' => true
            );
            
            // Hacer único el item del carrito
            $cart_item_data['unique_key'] = md5(microtime() . rand());
        }
        
        return $cart_item_data;
    }
    
    /**
     * Mostrar datos de alquiler en el carrito
     */
    public function display_rental_data_in_cart($item_data, $cart_item) {
        if (isset($cart_item['rental_data'])) {
            $rental_data = $cart_item['rental_data'];
            
            $item_data[] = array(
                'name' => __('Tipo', 'wc-rental-system'),
                'value' => __('Alquiler', 'wc-rental-system')
            );
            
            $item_data[] = array(
                'name' => __('Fecha de inicio', 'wc-rental-system'),
                'value' => date_i18n(get_option('date_format'), strtotime($rental_data['start_date']))
            );
            
            $item_data[] = array(
                'name' => __('Fecha de devolución', 'wc-rental-system'),
                'value' => date_i18n(get_option('date_format'), strtotime($rental_data['end_date']))
            );
            
            $item_data[] = array(
                'name' => __('Duración', 'wc-rental-system'),
                'value' => sprintf(__('%d días', 'wc-rental-system'), $rental_data['rental_days'])
            );
            
            if ($rental_data['rental_deposit'] > 0) {
                $item_data[] = array(
                    'name' => __('Garantía incluida', 'wc-rental-system'),
                    'value' => wc_price($rental_data['rental_deposit'])
                );
            }
        }
        
        return $item_data;
    }
    
    /**
     * Actualizar precio del item en el carrito
     */
    public function update_cart_item_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['rental_data'])) {
                $rental_data = $cart_item['rental_data'];
                $total_price = $rental_data['rental_price'] + $rental_data['rental_deposit'];
                $cart_item['data']->set_price($total_price);
            }
        }
    }
    
    /**
     * Cambiar texto del botón de añadir al carrito
     */
    public function change_add_to_cart_text($text, $product) {
        if ($this->is_rentable_product($product)) {
            return __('Alquilar Ahora', 'wc-rental-system');
        }
        return $text;
    }
    
    /**
     * Modificar display del precio para productos en alquiler
     */
    public function modify_price_display($price, $product) {
        if ($this->is_rentable_product($product)) {
            $rental_price = $product->get_price();
            $rental_settings = $this->get_product_rental_settings($product->get_id());
            
            $price = '<span class="rental-price-display">';
            $price .= sprintf(
                __('Desde %s / día', 'wc-rental-system'),
                wc_price($rental_price)
            );
            
            if ($rental_settings['min_rental_days'] > 1) {
                $price .= '<br><small>' . sprintf(
                    __('(Mínimo %d días)', 'wc-rental-system'),
                    $rental_settings['min_rental_days']
                ) . '</small>';
            }
            
            $price .= '</span>';
        }
        
        return $price;
    }
    
    /**
     * Ajax: Verificar disponibilidad
     */
    public function ajax_check_availability() {
        // Verificar nonce
        check_ajax_referer('rental_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);
        
        $is_available = $this->check_availability($product_id, $variation_id, $start_date, $end_date);
        
        wp_send_json(array(
            'available' => $is_available,
            'message' => $is_available ? 
                __('✓ Disponible para las fechas seleccionadas', 'wc-rental-system') : 
                __('✗ No disponible para estas fechas', 'wc-rental-system')
        ));
    }
    
    /**
     * Ajax: Calcular precio de alquiler
     */
    public function ajax_calculate_price() {
        // Verificar nonce
        check_ajax_referer('rental_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);
        
        // Obtener el producto
        if ($variation_id > 0) {
            $product = wc_get_product($variation_id);
        } else {
            $product = wc_get_product($product_id);
        }
        
        if (!$product) {
            wp_send_json_error(array('message' => __('Producto no encontrado', 'wc-rental-system')));
        }
        
        // Calcular días
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $interval = $start->diff($end);
        $rental_days = $interval->days;
        
        // Obtener precio base
        $daily_price = $product->get_price();
        $subtotal = $daily_price * $rental_days;
        
        // Obtener configuración de garantía
        $rental_settings = $this->get_product_rental_settings($product_id, $variation_id);
        
        // Calcular garantía
        $deposit = 0;
        if ($rental_settings['deposit_percentage'] > 0) {
            $deposit = $subtotal * ($rental_settings['deposit_percentage'] / 100);
        } elseif ($rental_settings['deposit_fixed'] > 0) {
            $deposit = $rental_settings['deposit_fixed'];
        }
        
        $total = $subtotal + $deposit;
        
        wp_send_json(array(
            'success' => true,
            'daily_price' => $daily_price,
            'rental_days' => $rental_days,
            'subtotal' => $subtotal,
            'deposit' => $deposit,
            'total' => $total,
            'formatted' => array(
                'daily_price' => wc_price($daily_price),
                'subtotal' => wc_price($subtotal),
                'deposit' => wc_price($deposit),
                'total' => wc_price($total),
                'duration' => sprintf(__('%d días', 'wc-rental-system'), $rental_days)
            )
        ));
    }
    
    /**
     * Ajax: Obtener fechas bloqueadas
     */
    public function ajax_get_blocked_dates() {
        // Verificar nonce
        check_ajax_referer('rental_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
        
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_availability';
        
        // Obtener fechas bloqueadas
        $blocked_dates = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT blocked_date 
             FROM $table 
             WHERE product_id = %d 
             AND (variation_id = %d OR variation_id = 0)
             AND blocked_date >= CURDATE()",
            $product_id,
            $variation_id
        ));
        
        // Obtener fechas bloqueadas manualmente del producto
        $manual_blocked = get_post_meta($product_id, '_blocked_dates', true);
        if ($manual_blocked) {
            $manual_dates = explode(',', $manual_blocked);
            foreach ($manual_dates as $date) {
                $date = trim($date);
                if ($date) {
                    // Convertir formato DD/MM/AAAA a AAAA-MM-DD
                    $parts = explode('/', $date);
                    if (count($parts) == 3) {
                        $blocked_dates[] = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
                    }
                }
            }
        }
        
        // Eliminar duplicados y ordenar
        $blocked_dates = array_unique($blocked_dates);
        sort($blocked_dates);
        
        wp_send_json(array(
            'success' => true,
            'blocked_dates' => $blocked_dates
        ));
    }
    
    /**
     * Verificar si un producto es alquilable
     */
    private function is_rentable_product($product) {
        if (!$product) {
            return false;
        }
        
        $product_id = $product->get_id();
        
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_product_settings';
        
        $is_rentable = $wpdb->get_var($wpdb->prepare(
            "SELECT is_rentable FROM $table WHERE product_id = %d AND variation_id = 0",
            $product_id
        ));
        
        return $is_rentable == 1;
    }
    
    /**
     * Obtener configuración de alquiler del producto
     */
    private function get_product_rental_settings($product_id, $variation_id = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_product_settings';
        
        // Primero intentar obtener configuración de la variación
        if ($variation_id > 0) {
            $settings = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE product_id = %d AND variation_id = %d",
                $product_id,
                $variation_id
            ), ARRAY_A);
            
            if ($settings) {
                return $settings;
            }
        }
        
        // Si no hay configuración de variación, obtener del producto padre
        $settings = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE product_id = %d AND variation_id = 0",
            $product_id
        ), ARRAY_A);
        
        // Si no hay configuración, devolver valores por defecto
        if (!$settings) {
            $settings = array(
                'min_rental_days' => 1,
                'max_rental_days' => 30,
                'deposit_percentage' => 0,
                'deposit_fixed' => 0,
                'grace_period_days' => 1,
                'buffer_days_before' => 0,
                'buffer_days_after' => 1
            );
        }
        
        return $settings;
    }
    
    /**
     * Verificar disponibilidad
     */
    private function check_availability($product_id, $variation_id, $start_date, $end_date) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_availability';
        
        // Verificar si hay bloqueos en el rango de fechas
        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table 
             WHERE product_id = %d 
             AND (variation_id = %d OR variation_id = 0)
             AND blocked_date BETWEEN %s AND %s",
            $product_id,
            $variation_id,
            $start_date,
            $end_date
        );
        
        $blocked_count = $wpdb->get_var($query);
        
        return $blocked_count == 0;
    }
    
    /**
     * Cargar scripts específicos para productos
     */
    public function enqueue_product_scripts() {
        if (!is_product()) {
            return;
        }
        
        global $product;
        
        if (!$product || !$this->is_rentable_product($product)) {
            return;
        }
        
        // jQuery UI para datepicker
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
        
        // Script personalizado para el frontend
        wp_add_inline_script('jquery-ui-datepicker', $this->get_inline_script(), 'after');
    }
    
    /**
     * Obtener script inline para la página de producto
     */
    private function get_inline_script() {
        ob_start();
        ?>
        jQuery(document).ready(function($) {
            // Variables globales
            var blockedDates = [];
            var productId = $('.rental-booking-form').data('product-id');
            var startDatePicker = $('#rental_start_date');
            var endDatePicker = $('#rental_end_date');
            var minDays = parseInt(startDatePicker.data('min-days')) || 1;
            var maxDays = parseInt(startDatePicker.data('max-days')) || 30;
            
            // Cargar fechas bloqueadas
            function loadBlockedDates() {
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'get_blocked_dates',
                        product_id: productId,
                        variation_id: $('input[name="variation_id"]').val() || 0,
                        nonce: '<?php echo wp_create_nonce('rental_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            blockedDates = response.blocked_dates;
                            initializeDatepickers();
                        }
                    }
                });
            }
            
            // Inicializar datepickers
            function initializeDatepickers() {
                // Configuración común
                var datePickerConfig = {
                    dateFormat: 'yy-mm-dd',
                    minDate: 0,
                    beforeShowDay: function(date) {
                        var dateString = $.datepicker.formatDate('yy-mm-dd', date);
                        if ($.inArray(dateString, blockedDates) !== -1) {
                            return [false, 'rental-date-blocked', 'No disponible'];
                        }
                        return [true, '', ''];
                    },
                    onSelect: function() {
                        calculateDays();
                        checkAvailability();
                    }
                };
                
                // Datepicker de fecha de inicio
                startDatePicker.datepicker($.extend({}, datePickerConfig, {
                    onSelect: function(selectedDate) {
                        var startDate = $(this).datepicker('getDate');
                        var minEndDate = new Date(startDate);
                        minEndDate.setDate(minEndDate.getDate() + minDays);
                        
                        var maxEndDate = new Date(startDate);
                        maxEndDate.setDate(maxEndDate.getDate() + maxDays);
                        
                        endDatePicker.datepicker('option', 'minDate', minEndDate);
                        endDatePicker.datepicker('option', 'maxDate', maxEndDate);
                        
                        calculateDays();
                        
                        if (endDatePicker.val()) {
                            checkAvailability();
                        }
                    }
                }));
                
                // Datepicker de fecha de fin
                endDatePicker.datepicker(datePickerConfig);
                
                // Calendario inline de disponibilidad
                $('#availability-calendar').datepicker({
                    dateFormat: 'yy-mm-dd',
                    numberOfMonths: 2,
                    beforeShowDay: function(date) {
                        var dateString = $.datepicker.formatDate('yy-mm-dd', date);
                        if ($.inArray(dateString, blockedDates) !== -1) {
                            return [false, 'rental-date-blocked', 'No disponible'];
                        }
                        return [true, 'rental-date-available', 'Disponible'];
                    }
                });
            }
            
            // Calcular días de alquiler
            function calculateDays() {
                var startDate = startDatePicker.val();
                var endDate = endDatePicker.val();
                
                if (startDate && endDate) {
                    var start = new Date(startDate);
                    var end = new Date(endDate);
                    var days = Math.floor((end - start) / (1000 * 60 * 60 * 24));
                    
                    $('#rental-days-count').text(days);
                    $('#rental_days').val(days);
                    $('.rental-days-display').show();
                    $('.check-availability-btn').show();
                }
            }
            
            // Verificar disponibilidad
            function checkAvailability() {
                var startDate = startDatePicker.val();
                var endDate = endDatePicker.val();
                
                if (!startDate || !endDate) {
                    return;
                }
                
                $('.availability-message')
                    .removeClass('success error')
                    .addClass('loading')
                    .html('<span class="dashicons dashicons-update spin"></span> Verificando disponibilidad...')
                    .show();
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'check_product_availability',
                        product_id: productId,
                        variation_id: $('input[name="variation_id"]').val() || 0,
                        start_date: startDate,
                        end_date: endDate,
                        nonce: '<?php echo wp_create_nonce('rental_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.available) {
                            $('.availability-message')
                                .removeClass('loading error')
                                .addClass('success')
                                .html(response.message);
                            
                            $('.rental-booking-form')
                                .removeClass('unavailable')
                                .addClass('available');
                            
                            // Calcular precio
                            calculatePrice();
                        } else {
                            $('.availability-message')
                                .removeClass('loading success')
                                .addClass('error')
                                .html(response.message);
                            
                            $('.rental-booking-form')
                                .removeClass('available')
                                .addClass('unavailable');
                            
                            $('.rental-price-summary').hide();
                        }
                    }
                });
            }
            
            // Calcular precio
            function calculatePrice() {
                var startDate = startDatePicker.val();
                var endDate = endDatePicker.val();
                
                if (!startDate || !endDate) {
                    return;
                }
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'calculate_rental_price',
                        product_id: productId,
                        variation_id: $('input[name="variation_id"]').val() || 0,
                        start_date: startDate,
                        end_date: endDate,
                        nonce: '<?php echo wp_create_nonce('rental_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#daily-price').html(response.formatted.daily_price);
                            $('#rental-duration').text(response.formatted.duration);
                            $('#rental-subtotal').html(response.formatted.subtotal);
                            $('#rental-deposit').html(response.formatted.deposit);
                            $('#rental-total').html(response.formatted.total);
                            
                            $('#rental_price').val(response.subtotal);
                            $('#rental_deposit').val(response.deposit);
                            
                            $('.rental-price-summary').fadeIn();
                        }
                    }
                });
            }
            
            // Botón de verificar disponibilidad
            $('.check-availability-btn').on('click', function() {
                checkAvailability();
            });
            
            // Cuando cambia la variación
            $('form.variations_form').on('found_variation', function(event, variation) {
                loadBlockedDates();
            });
            
            // Validar antes de añadir al carrito
            $('form.cart').on('submit', function(e) {
                if ($('.rental-booking-form').length > 0) {
                    var startDate = startDatePicker.val();
                    var endDate = endDatePicker.val();
                    
                    if (!startDate || !endDate) {
                        e.preventDefault();
                        alert('<?php _e('Por favor selecciona las fechas de alquiler', 'wc-rental-system'); ?>');
                        return false;
                    }
                    
                    if ($('.rental-booking-form').hasClass('unavailable')) {
                        e.preventDefault();
                        alert('<?php _e('El producto no está disponible para las fechas seleccionadas', 'wc-rental-system'); ?>');
                        return false;
                    }
                }
            });
            
            // Cargar fechas bloqueadas al inicio
            loadBlockedDates();
        });
        <?php
        return ob_get_clean();
    }
}