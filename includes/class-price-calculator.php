<?php
/**
 * Clase para manejar el cálculo de precios de alquileres
 * Archivo: includes/class-price-calculator.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Rental_Price_Calculator {
    
    /**
     * Instancia única
     */
    private static $instance = null;
    
    /**
     * Cache de cálculos
     */
    private $calculation_cache = array();
    
    /**
     * Obtener instancia única
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
    private function __construct() {
        // Hooks para modificar precios en WooCommerce
        add_filter('woocommerce_product_get_price', array($this, 'modify_product_price'), 10, 2);
        add_filter('woocommerce_product_variation_get_price', array($this, 'modify_product_price'), 10, 2);
        
        // Limpiar cache cuando cambian precios
        add_action('woocommerce_product_object_updated_props', array($this, 'clear_cache_on_price_update'), 10, 2);
        
        // Aplicar descuentos por duración
        add_filter('wc_rental_calculated_price', array($this, 'apply_duration_discounts'), 10, 4);
        
        // Aplicar tarifas de temporada
        add_filter('wc_rental_calculated_price', array($this, 'apply_seasonal_rates'), 20, 4);
    }
    
    /**
     * Calcular precio total del alquiler
     * 
     * @param int $product_id ID del producto
     * @param int $variation_id ID de la variación
     * @param string $start_date Fecha de inicio
     * @param string $end_date Fecha de fin
     * @param array $options Opciones adicionales
     * @return array Desglose de precios
     */
    public function calculate($product_id, $variation_id = 0, $start_date, $end_date, $options = array()) {
        // Generar clave de cache
        $cache_key = $this->get_cache_key($product_id, $variation_id, $start_date, $end_date, $options);
        
        // Verificar cache
        if (isset($this->calculation_cache[$cache_key])) {
            return $this->calculation_cache[$cache_key];
        }
        
        // Obtener producto
        $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
        if (!$product) {
            return $this->error_response(__('Producto no encontrado', 'wc-rental-system'));
        }
        
        // Calcular días de alquiler
        $rental_days = $this->calculate_rental_days($start_date, $end_date);
        if ($rental_days <= 0) {
            return $this->error_response(__('Fechas inválidas', 'wc-rental-system'));
        }
        
        // Obtener configuración del producto
        $settings = $this->get_product_settings($product_id, $variation_id);
        
        // Validar días mínimos y máximos
        if ($rental_days < $settings['min_rental_days']) {
            return $this->error_response(
                sprintf(__('El alquiler mínimo es de %d días', 'wc-rental-system'), $settings['min_rental_days'])
            );
        }
        
        if ($rental_days > $settings['max_rental_days']) {
            return $this->error_response(
                sprintf(__('El alquiler máximo es de %d días', 'wc-rental-system'), $settings['max_rental_days'])
            );
        }
        
        // Obtener precio base por día
        $base_price_per_day = $this->get_base_price_per_day($product, $variation_id, $options);
        
        // Calcular precio base total
        $base_total = $base_price_per_day * $rental_days;
        
        // Aplicar modificadores de precio
        $price_breakdown = array(
            'base_price_per_day' => $base_price_per_day,
            'rental_days' => $rental_days,
            'base_total' => $base_total,
            'modifiers' => array()
        );
        
        // Aplicar descuentos por duración
        $duration_discount = $this->calculate_duration_discount($base_total, $rental_days, $settings);
        if ($duration_discount > 0) {
            $price_breakdown['modifiers']['duration_discount'] = array(
                'label' => __('Descuento por duración', 'wc-rental-system'),
                'amount' => -$duration_discount,
                'percentage' => $this->get_duration_discount_percentage($rental_days, $settings)
            );
        }
        
        // Aplicar tarifas de temporada
        $seasonal_adjustment = $this->calculate_seasonal_adjustment(
            $base_total, 
            $start_date, 
            $end_date, 
            $product_id
        );
        if ($seasonal_adjustment != 0) {
            $price_breakdown['modifiers']['seasonal_rate'] = array(
                'label' => $seasonal_adjustment > 0 ? 
                    __('Tarifa de temporada alta', 'wc-rental-system') : 
                    __('Descuento de temporada baja', 'wc-rental-system'),
                'amount' => $seasonal_adjustment
            );
        }
        
        // Aplicar cargos adicionales
        $additional_charges = $this->calculate_additional_charges(
            $product_id, 
            $variation_id, 
            $rental_days, 
            $options
        );
        foreach ($additional_charges as $charge_key => $charge) {
            $price_breakdown['modifiers'][$charge_key] = $charge;
        }
        
        // Calcular subtotal después de modificadores
        $subtotal = $base_total;
        foreach ($price_breakdown['modifiers'] as $modifier) {
            $subtotal += $modifier['amount'];
        }
        $price_breakdown['subtotal'] = max(0, $subtotal); // Asegurar que no sea negativo
        
        // Calcular depósito/garantía
        $deposit = $this->calculate_deposit($price_breakdown['subtotal'], $settings);
        $price_breakdown['deposit'] = $deposit;
        $price_breakdown['deposit_info'] = $this->get_deposit_info($deposit, $settings);
        
        // Calcular impuestos si aplica
        if (wc_tax_enabled() && isset($options['calculate_tax']) && $options['calculate_tax']) {
            $tax = $this->calculate_tax($price_breakdown['subtotal'], $product);
            $price_breakdown['tax'] = $tax;
        } else {
            $price_breakdown['tax'] = 0;
        }
        
        // Calcular total final
        $price_breakdown['total'] = $price_breakdown['subtotal'] + 
                                    $price_breakdown['deposit'] + 
                                    $price_breakdown['tax'];
        
        // Añadir información adicional
        $price_breakdown['currency'] = get_woocommerce_currency();
        $price_breakdown['currency_symbol'] = get_woocommerce_currency_symbol();
        $price_breakdown['start_date'] = $start_date;
        $price_breakdown['end_date'] = $end_date;
        $price_breakdown['product_id'] = $product_id;
        $price_breakdown['variation_id'] = $variation_id;
        $price_breakdown['settings'] = $settings;
        
        // Formatear precios para display
        $price_breakdown['formatted'] = $this->format_price_breakdown($price_breakdown);
        
        // Aplicar filtros para permitir modificaciones
        $price_breakdown = apply_filters('wc_rental_price_breakdown', $price_breakdown, $product_id, $variation_id);
        
        // Guardar en cache
        $this->calculation_cache[$cache_key] = $price_breakdown;
        
        return $price_breakdown;
    }
    
    /**
     * Calcular precio para múltiples períodos (para comparación)
     * 
     * @param int $product_id ID del producto
     * @param int $variation_id ID de la variación
     * @param array $periods Array de períodos a calcular
     * @return array
     */
    public function calculate_multiple_periods($product_id, $variation_id = 0, $periods = array()) {
        $results = array();
        
        $default_periods = array(
            1 => __('1 día', 'wc-rental-system'),
            3 => __('3 días', 'wc-rental-system'),
            7 => __('1 semana', 'wc-rental-system'),
            14 => __('2 semanas', 'wc-rental-system'),
            30 => __('1 mes', 'wc-rental-system')
        );
        
        if (empty($periods)) {
            $periods = $default_periods;
        }
        
        $today = date('Y-m-d');
        
        foreach ($periods as $days => $label) {
            $end_date = date('Y-m-d', strtotime("+{$days} days"));
            $calculation = $this->calculate($product_id, $variation_id, $today, $end_date);
            
            if (!isset($calculation['error'])) {
                $results[$days] = array(
                    'label' => $label,
                    'days' => $days,
                    'total' => $calculation['total'],
                    'subtotal' => $calculation['subtotal'],
                    'deposit' => $calculation['deposit'],
                    'price_per_day' => $calculation['total'] / $days,
                    'formatted_total' => $calculation['formatted']['total'],
                    'formatted_per_day' => wc_price($calculation['total'] / $days),
                    'has_discount' => isset($calculation['modifiers']['duration_discount'])
                );
            }
        }
        
        return $results;
    }
    
    /**
     * Obtener tabla de precios por duración
     * 
     * @param int $product_id ID del producto
     * @param int $variation_id ID de la variación
     * @return array
     */
    public function get_pricing_table($product_id, $variation_id = 0) {
        $settings = $this->get_product_settings($product_id, $variation_id);
        $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
        
        if (!$product) {
            return array();
        }
        
        $base_price = $product->get_price();
        $pricing_table = array();
        
        // Generar tabla de precios según configuración
        $duration_rules = $this->get_duration_discount_rules($settings);
        
        foreach ($duration_rules as $rule) {
            $days = $rule['days'];
            $discount_percentage = $rule['discount'];
            $price_per_day = $base_price * (1 - $discount_percentage / 100);
            $total = $price_per_day * $days;
            
            $pricing_table[] = array(
                'days' => $days,
                'label' => $this->get_duration_label($days),
                'price_per_day' => $price_per_day,
                'discount_percentage' => $discount_percentage,
                'total' => $total,
                'savings' => ($base_price * $days) - $total,
                'formatted' => array(
                    'price_per_day' => wc_price($price_per_day),
                    'total' => wc_price($total),
                    'savings' => wc_price(($base_price * $days) - $total)
                )
            );
        }
        
        return $pricing_table;
    }
    
    /**
     * Calcular precio prorrateado (para extensiones o devoluciones anticipadas)
     * 
     * @param int $rental_id ID del alquiler
     * @param string $new_end_date Nueva fecha de fin
     * @param string $type 'extension' o 'early_return'
     * @return array
     */
    public function calculate_prorated($rental_id, $new_end_date, $type = 'extension') {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        // Obtener datos del alquiler original
        $rental = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $rental_id
        ));
        
        if (!$rental) {
            return $this->error_response(__('Alquiler no encontrado', 'wc-rental-system'));
        }
        
        $result = array(
            'rental_id' => $rental_id,
            'type' => $type,
            'original_end_date' => $rental->end_date,
            'new_end_date' => $new_end_date,
            'original_price' => $rental->rental_price,
            'original_days' => $rental->rental_days
        );
        
        if ($type === 'extension') {
            // Calcular días adicionales
            $additional_days = $this->calculate_rental_days($rental->end_date, $new_end_date);
            
            if ($additional_days <= 0) {
                return $this->error_response(__('La nueva fecha debe ser posterior a la fecha actual de fin', 'wc-rental-system'));
            }
            
            // Calcular precio de los días adicionales
            $price_per_day = $rental->rental_price / $rental->rental_days;
            $additional_price = $price_per_day * $additional_days;
            
            // Aplicar descuentos si el total de días califica
            $total_days = $rental->rental_days + $additional_days;
            $settings = $this->get_product_settings($rental->product_id, $rental->variation_id);
            
            // Recalcular con descuentos
            $new_total_calculation = $this->calculate(
                $rental->product_id,
                $rental->variation_id,
                $rental->start_date,
                $new_end_date
            );
            
            $result['additional_days'] = $additional_days;
            $result['additional_price'] = $additional_price;
            $result['new_total_days'] = $total_days;
            $result['new_total_price'] = $new_total_calculation['subtotal'];
            $result['amount_to_pay'] = max(0, $new_total_calculation['subtotal'] - $rental->rental_price);
            
        } elseif ($type === 'early_return') {
            // Calcular días no usados
            $unused_days = $this->calculate_rental_days($new_end_date, $rental->end_date);
            
            if ($unused_days <= 0) {
                return $this->error_response(__('La fecha de devolución debe ser anterior a la fecha original', 'wc-rental-system'));
            }
            
            // Calcular reembolso parcial
            $price_per_day = $rental->rental_price / $rental->rental_days;
            $used_days = $rental->rental_days - $unused_days;
            
            // Aplicar política de reembolso
            $refund_policy = $this->get_refund_policy($rental->product_id);
            $refund_percentage = $this->calculate_refund_percentage($unused_days, $rental->rental_days, $refund_policy);
            
            $potential_refund = $price_per_day * $unused_days;
            $actual_refund = $potential_refund * ($refund_percentage / 100);
            
            $result['unused_days'] = $unused_days;
            $result['used_days'] = $used_days;
            $result['potential_refund'] = $potential_refund;
            $result['refund_percentage'] = $refund_percentage;
            $result['actual_refund'] = $actual_refund;
        }
        
        $result['formatted'] = $this->format_prorated_calculation($result);
        
        return $result;
    }
    
    /**
     * Calcular cargos por daños o retraso
     * 
     * @param int $rental_id ID del alquiler
     * @param array $charges Array de cargos a aplicar
     * @return array
     */
    public function calculate_penalty_charges($rental_id, $charges = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        $rental = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $rental_id
        ));
        
        if (!$rental) {
            return $this->error_response(__('Alquiler no encontrado', 'wc-rental-system'));
        }
        
        $total_charges = 0;
        $charge_breakdown = array();
        
        // Cargo por retraso en la devolución
        if (isset($charges['late_return_days']) && $charges['late_return_days'] > 0) {
            $late_fee_per_day = $this->get_late_fee_per_day($rental->product_id, $rental->rental_price);
            $late_charge = $late_fee_per_day * $charges['late_return_days'];
            
            $charge_breakdown['late_return'] = array(
                'label' => sprintf(__('Retraso en devolución (%d días)', 'wc-rental-system'), $charges['late_return_days']),
                'amount' => $late_charge,
                'calculation' => sprintf('%s x %d días', wc_price($late_fee_per_day), $charges['late_return_days'])
            );
            
            $total_charges += $late_charge;
        }
        
        // Cargo por daños
        if (isset($charges['damage']) && $charges['damage'] > 0) {
            $charge_breakdown['damage'] = array(
                'label' => __('Reparación de daños', 'wc-rental-system'),
                'amount' => $charges['damage'],
                'description' => isset($charges['damage_description']) ? $charges['damage_description'] : ''
            );
            
            $total_charges += $charges['damage'];
        }
        
        // Cargo por pérdida
        if (isset($charges['loss']) && $charges['loss']) {
            $product = wc_get_product($rental->product_id);
            $replacement_cost = $this->get_replacement_cost($product);
            
            $charge_breakdown['loss'] = array(
                'label' => __('Pérdida del producto', 'wc-rental-system'),
                'amount' => $replacement_cost,
                'description' => __('Costo de reemplazo del producto', 'wc-rental-system')
            );
            
            $total_charges += $replacement_cost;
        }
        
        // Cargo por limpieza especial
        if (isset($charges['cleaning']) && $charges['cleaning'] > 0) {
            $charge_breakdown['cleaning'] = array(
                'label' => __('Limpieza especial', 'wc-rental-system'),
                'amount' => $charges['cleaning']
            );
            
            $total_charges += $charges['cleaning'];
        }
        
        // Aplicar depósito si está disponible
        $deposit_applied = min($rental->deposit_amount, $total_charges);
        $remaining_charges = $total_charges - $deposit_applied;
        
        return array(
            'rental_id' => $rental_id,
            'charges' => $charge_breakdown,
            'total_charges' => $total_charges,
            'deposit_available' => $rental->deposit_amount,
            'deposit_applied' => $deposit_applied,
            'remaining_charges' => $remaining_charges,
            'deposit_to_return' => max(0, $rental->deposit_amount - $deposit_applied),
            'formatted' => array(
                'total_charges' => wc_price($total_charges),
                'deposit_applied' => wc_price($deposit_applied),
                'remaining_charges' => wc_price($remaining_charges),
                'deposit_to_return' => wc_price(max(0, $rental->deposit_amount - $deposit_applied))
            )
        );
    }
    
    /**
     * Métodos privados auxiliares
     */
    
    /**
     * Calcular días de alquiler
     */
    private function calculate_rental_days($start_date, $end_date) {
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $interval = $start->diff($end);
        return $interval->days;
    }
    
    /**
     * Obtener precio base por día
     */
    private function get_base_price_per_day($product, $variation_id = 0, $options = array()) {
        // Precio base del producto
        $base_price = $product->get_price();
        
        // Verificar si hay precio especial para alquiler
        $rental_price = get_post_meta($product->get_id(), '_rental_price_per_day', true);
        if ($rental_price && $rental_price > 0) {
            $base_price = $rental_price;
        }
        
        // Si es variación, verificar precio específico de variación
        if ($variation_id > 0) {
            $variation_rental_price = get_post_meta($variation_id, '_variation_rental_price_per_day', true);
            if ($variation_rental_price && $variation_rental_price > 0) {
                $base_price = $variation_rental_price;
            }
        }
        
        // Aplicar filtros para permitir modificaciones
        $base_price = apply_filters('wc_rental_base_price_per_day', $base_price, $product, $variation_id, $options);
        
        return floatval($base_price);
    }
    
    /**
     * Calcular descuento por duración
     */
    private function calculate_duration_discount($base_total, $rental_days, $settings) {
        $discount_percentage = $this->get_duration_discount_percentage($rental_days, $settings);
        
        if ($discount_percentage > 0) {
            return $base_total * ($discount_percentage / 100);
        }
        
        return 0;
    }
    
    /**
     * Obtener porcentaje de descuento por duración
     */
    private function get_duration_discount_percentage($rental_days, $settings) {
        // Obtener reglas de descuento
        $discount_rules = $this->get_duration_discount_rules($settings);
        
        $applicable_discount = 0;
        foreach ($discount_rules as $rule) {
            if ($rental_days >= $rule['days']) {
                $applicable_discount = $rule['discount'];
            }
        }
        
        return $applicable_discount;
    }
    
    /**
     * Obtener reglas de descuento por duración
     */
    private function get_duration_discount_rules($settings) {
        // Primero verificar si hay reglas personalizadas
        $custom_rules = get_option('wc_rental_duration_discounts', array());
        
        if (!empty($custom_rules)) {
            return $custom_rules;
        }
        
        // Reglas por defecto
        return array(
            array('days' => 3, 'discount' => 5),    // 5% descuento para 3+ días
            array('days' => 7, 'discount' => 10),   // 10% descuento para 7+ días
            array('days' => 14, 'discount' => 15),  // 15% descuento para 14+ días
            array('days' => 30, 'discount' => 20),  // 20% descuento para 30+ días
        );
    }
    
    /**
     * Calcular ajuste de temporada
     */
    private function calculate_seasonal_adjustment($base_total, $start_date, $end_date, $product_id) {
        // Obtener reglas de temporada
        $seasonal_rules = $this->get_seasonal_rules($product_id);
        
        if (empty($seasonal_rules)) {
            return 0;
        }
        
        $adjustment = 0;
        $days_in_season = 0;
        $total_days = $this->calculate_rental_days($start_date, $end_date);
        
        // Verificar cada día del período
        $current = new DateTime($start_date);
        $end = new DateTime($end_date);
        
        while ($current <= $end) {
            foreach ($seasonal_rules as $rule) {
                if ($this->is_date_in_season($current->format('Y-m-d'), $rule)) {
                    $days_in_season++;
                    break;
                }
            }
            $current->modify('+1 day');
        }
        
        // Calcular ajuste proporcional
        if ($days_in_season > 0) {
            $season_percentage = ($days_in_season / $total_days);
            
            // Obtener el ajuste de precio promedio
            $avg_adjustment = 0;
            foreach ($seasonal_rules as $rule) {
                if ($rule['type'] === 'increase') {
                    $avg_adjustment += $rule['percentage'];
                } else {
                    $avg_adjustment -= $rule['percentage'];
                }
            }
            
            $adjustment = $base_total * ($avg_adjustment / 100) * $season_percentage;
        }
        
        return $adjustment;
    }
    
    /**
     * Obtener reglas de temporada
     */
    private function get_seasonal_rules($product_id) {
        // Primero verificar reglas específicas del producto
        $product_rules = get_post_meta($product_id, '_rental_seasonal_rules', true);
        
        if (!empty($product_rules)) {
            return $product_rules;
        }
        
        // Luego verificar reglas globales
        $global_rules = get_option('wc_rental_seasonal_rules', array());
        
        // Reglas de ejemplo (pueden ser configuradas en admin)
        if (empty($global_rules)) {
            $global_rules = array(
                array(
                    'name' => 'Temporada Alta',
                    'start_month' => 6,  // Junio
                    'start_day' => 1,
                    'end_month' => 8,    // Agosto
                    'end_day' => 31,
                    'type' => 'increase',
                    'percentage' => 25
                ),
                array(
                    'name' => 'Temporada Baja',
                    'start_month' => 1,  // Enero
                    'start_day' => 15,
                    'end_month' => 3,    // Marzo
                    'end_day' => 15,
                    'type' => 'discount',
                    'percentage' => 15
                )
            );
        }
        
        return $global_rules;
    }
    
    /**
     * Verificar si una fecha está en temporada
     */
    private function is_date_in_season($date, $rule) {
        $check_date = new DateTime($date);
        $year = $check_date->format('Y');
        
        $season_start = new DateTime(sprintf('%d-%02d-%02d', $year, $rule['start_month'], $rule['start_day']));
        $season_end = new DateTime(sprintf('%d-%02d-%02d', $year, $rule['end_month'], $rule['end_day']));
        
        // Si la temporada cruza el año
        if ($season_end < $season_start) {
            return $check_date >= $season_start || $check_date <= $season_end;
        }
        
        return $check_date >= $season_start && $check_date <= $season_end;
    }
    
    /**
     * Calcular cargos adicionales
     */
    private function calculate_additional_charges($product_id, $variation_id, $rental_days, $options) {
        $charges = array();
        
        // Cargo por entrega
        if (isset($options['delivery']) && $options['delivery']) {
            $delivery_fee = $this->get_delivery_fee($product_id, $options['delivery_distance'] ?? 0);
            if ($delivery_fee > 0) {
                $charges['delivery'] = array(
                    'label' => __('Entrega a domicilio', 'wc-rental-system'),
                    'amount' => $delivery_fee
                );
            }
        }
        
        // Cargo por recogida
        if (isset($options['pickup']) && $options['pickup']) {
            $pickup_fee = $this->get_pickup_fee($product_id);
            if ($pickup_fee > 0) {
                $charges['pickup'] = array(
                    'label' => __('Recogida a domicilio', 'wc-rental-system'),
                    'amount' => $pickup_fee
                );
            }
        }
        
        // Seguro opcional
        if (isset($options['insurance']) && $options['insurance']) {
            $insurance_fee = $this->calculate_insurance_fee($product_id, $rental_days);
            if ($insurance_fee > 0) {
                $charges['insurance'] = array(
                    'label' => __('Seguro opcional', 'wc-rental-system'),
                    'amount' => $insurance_fee
                );
            }
        }
        
        // Limpieza express
        if (isset($options['express_cleaning']) && $options['express_cleaning']) {
            $cleaning_fee = $this->get_express_cleaning_fee($product_id);
            if ($cleaning_fee > 0) {
                $charges['express_cleaning'] = array(
                    'label' => __('Limpieza express', 'wc-rental-system'),
                    'amount' => $cleaning_fee
                );
            }
        }
        
        // Aplicar filtros para permitir cargos personalizados
        $charges = apply_filters('wc_rental_additional_charges', $charges, $product_id, $variation_id, $rental_days, $options);
        
        return $charges;
    }
    
    /**
     * Calcular depósito/garantía
     */
    private function calculate_deposit($subtotal, $settings) {
        $deposit = 0;
        
        if ($settings['deposit_percentage'] > 0) {
            $deposit = $subtotal * ($settings['deposit_percentage'] / 100);
        } elseif ($settings['deposit_fixed'] > 0) {
            $deposit = $settings['deposit_fixed'];
        }
        
        // Aplicar mínimo y máximo si están configurados
        $min_deposit = get_option('wc_rental_min_deposit', 0);
        $max_deposit = get_option('wc_rental_max_deposit', 0);
        
        if ($min_deposit > 0) {
            $deposit = max($deposit, $min_deposit);
        }
        
        if ($max_deposit > 0) {
            $deposit = min($deposit, $max_deposit);
        }
        
        return round($deposit, 2);
    }
    
    /**
     * Obtener información del depósito
     */
    private function get_deposit_info($deposit, $settings) {
        $info = array(
            'amount' => $deposit,
            'type' => 'none',
            'percentage' => 0,
            'is_refundable' => true,
            'conditions' => __('El depósito será devuelto al finalizar el alquiler si el producto se devuelve en perfectas condiciones', 'wc-rental-system')
        );
        
        if ($settings['deposit_percentage'] > 0) {
            $info['type'] = 'percentage';
            $info['percentage'] = $settings['deposit_percentage'];
        } elseif ($settings['deposit_fixed'] > 0) {
            $info['type'] = 'fixed';
        }
        
        return $info;
    }
    
    /**
     * Calcular impuestos
     */
    private function calculate_tax($subtotal, $product) {
        if (!wc_tax_enabled()) {
            return 0;
        }
        
        $tax_rates = WC_Tax::get_rates($product->get_tax_class());
        $taxes = WC_Tax::calc_tax($subtotal, $tax_rates, false);
        
        return array_sum($taxes);
    }
    
    /**
     * Formatear desglose de precios
     */
    private function format_price_breakdown($breakdown) {
        $formatted = array(
            'base_price_per_day' => wc_price($breakdown['base_price_per_day']),
            'base_total' => wc_price($breakdown['base_total']),
            'subtotal' => wc_price($breakdown['subtotal']),
            'deposit' => wc_price($breakdown['deposit']),
            'tax' => wc_price($breakdown['tax']),
            'total' => wc_price($breakdown['total']),
            'rental_days' => sprintf(_n('%d día', '%d días', $breakdown['rental_days'], 'wc-rental-system'), $breakdown['rental_days'])
        );
        
        // Formatear modificadores
        $formatted['modifiers'] = array();
        foreach ($breakdown['modifiers'] as $key => $modifier) {
            $formatted['modifiers'][$key] = array(
                'label' => $modifier['label'],
                'amount' => wc_price(abs($modifier['amount'])),
                'is_discount' => $modifier['amount'] < 0
            );
        }
        
        return $formatted;
    }
    
    /**
     * Formatear cálculo prorrateado
     */
    private function format_prorated_calculation($calculation) {
        $formatted = array();
        
        if ($calculation['type'] === 'extension') {
            $formatted = array(
                'additional_days' => sprintf(_n('%d día', '%d días', $calculation['additional_days'], 'wc-rental-system'), $calculation['additional_days']),
                'additional_price' => wc_price($calculation['additional_price']),
                'new_total_price' => wc_price($calculation['new_total_price']),
                'amount_to_pay' => wc_price($calculation['amount_to_pay'])
            );
        } elseif ($calculation['type'] === 'early_return') {
            $formatted = array(
                'unused_days' => sprintf(_n('%d día', '%d días', $calculation['unused_days'], 'wc-rental-system'), $calculation['unused_days']),
                'potential_refund' => wc_price($calculation['potential_refund']),
                'refund_percentage' => $calculation['refund_percentage'] . '%',
                'actual_refund' => wc_price($calculation['actual_refund'])
            );
        }
        
        return $formatted;
    }
    
    /**
     * Obtener configuración del producto
     */
    private function get_product_settings($product_id, $variation_id = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_product_settings';
        
        // Intentar obtener configuración de la variación
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
        
        // Obtener configuración del producto padre
        $settings = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE product_id = %d AND variation_id = 0",
            $product_id
        ), ARRAY_A);
        
        // Valores por defecto
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
     * Obtener tarifa de entrega
     */
    private function get_delivery_fee($product_id, $distance = 0) {
        // Primero verificar tarifa específica del producto
        $product_fee = get_post_meta($product_id, '_rental_delivery_fee', true);
        if ($product_fee && $product_fee > 0) {
            return floatval($product_fee);
        }
        
        // Luego usar tarifa global
        $base_fee = get_option('wc_rental_delivery_base_fee', 10);
        $per_km_fee = get_option('wc_rental_delivery_per_km', 1);
        
        return $base_fee + ($distance * $per_km_fee);
    }
    
    /**
     * Obtener tarifa de recogida
     */
    private function get_pickup_fee($product_id) {
        $product_fee = get_post_meta($product_id, '_rental_pickup_fee', true);
        if ($product_fee && $product_fee > 0) {
            return floatval($product_fee);
        }
        
        return get_option('wc_rental_pickup_fee', 10);
    }
    
    /**
     * Calcular tarifa de seguro
     */
    private function calculate_insurance_fee($product_id, $rental_days) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return 0;
        }
        
        // Calcular basado en valor del producto
        $product_value = $product->get_price();
        $insurance_rate = get_option('wc_rental_insurance_rate', 0.02); // 2% por día por defecto
        
        return $product_value * $insurance_rate * $rental_days;
    }
    
    /**
     * Obtener tarifa de limpieza express
     */
    private function get_express_cleaning_fee($product_id) {
        $product_fee = get_post_meta($product_id, '_rental_express_cleaning_fee', true);
        if ($product_fee && $product_fee > 0) {
            return floatval($product_fee);
        }
        
        return get_option('wc_rental_express_cleaning_fee', 25);
    }
    
    /**
     * Obtener tarifa por retraso
     */
    private function get_late_fee_per_day($product_id, $rental_price) {
        // Verificar configuración específica del producto
        $product_fee = get_post_meta($product_id, '_rental_late_fee_per_day', true);
        if ($product_fee && $product_fee > 0) {
            return floatval($product_fee);
        }
        
        // Usar configuración global
        $late_fee_type = get_option('wc_rental_late_fee_type', 'percentage');
        
        if ($late_fee_type === 'percentage') {
            $percentage = get_option('wc_rental_late_fee_percentage', 50); // 50% del precio diario
            $daily_price = $rental_price / 30; // Aproximación
            return $daily_price * ($percentage / 100);
        } else {
            return get_option('wc_rental_late_fee_fixed', 20);
        }
    }
    
    /**
     * Obtener costo de reemplazo
     */
    private function get_replacement_cost($product) {
        if (!$product) {
            return 0;
        }
        
        // Verificar si hay costo de reemplazo específico
        $replacement_cost = get_post_meta($product->get_id(), '_rental_replacement_cost', true);
        if ($replacement_cost && $replacement_cost > 0) {
            return floatval($replacement_cost);
        }
        
        // Usar precio del producto multiplicado por factor
        $factor = get_option('wc_rental_replacement_cost_factor', 1.5);
        return $product->get_price() * $factor;
    }
    
    /**
     * Obtener política de reembolso
     */
    private function get_refund_policy($product_id) {
        // Primero verificar política específica del producto
        $product_policy = get_post_meta($product_id, '_rental_refund_policy', true);
        if (!empty($product_policy)) {
            return $product_policy;
        }
        
        // Usar política global
        return get_option('wc_rental_refund_policy', array(
            array('days_before' => 7, 'refund_percentage' => 100),
            array('days_before' => 3, 'refund_percentage' => 50),
            array('days_before' => 1, 'refund_percentage' => 25),
            array('days_before' => 0, 'refund_percentage' => 0)
        ));
    }
    
    /**
     * Calcular porcentaje de reembolso
     */
    private function calculate_refund_percentage($unused_days, $total_days, $policy) {
        $percentage_by_days = ($unused_days / $total_days) * 100;
        
        // Aplicar política de reembolso
        foreach ($policy as $rule) {
            if ($unused_days >= $rule['days_before']) {
                return min($percentage_by_days, $rule['refund_percentage']);
            }
        }
        
        return 0;
    }
    
    /**
     * Obtener etiqueta de duración
     */
    private function get_duration_label($days) {
        if ($days == 1) {
            return __('1 día', 'wc-rental-system');
        } elseif ($days < 7) {
            return sprintf(__('%d días', 'wc-rental-system'), $days);
        } elseif ($days == 7) {
            return __('1 semana', 'wc-rental-system');
        } elseif ($days == 14) {
            return __('2 semanas', 'wc-rental-system');
        } elseif ($days == 30) {
            return __('1 mes', 'wc-rental-system');
        } else {
            return sprintf(__('%d días', 'wc-rental-system'), $days);
        }
    }
    
    /**
     * Modificar precio del producto para mostrar precio por día
     */
    public function modify_product_price($price, $product) {
        // Solo en frontend y para productos alquilables
        if (is_admin() || !$this->is_rentable_product($product)) {
            return $price;
        }
        
        // Si estamos en el contexto de alquiler, devolver precio por día
        if (apply_filters('wc_rental_show_daily_price', true, $product)) {
            $rental_price = get_post_meta($product->get_id(), '_rental_price_per_day', true);
            if ($rental_price && $rental_price > 0) {
                return $rental_price;
            }
        }
        
        return $price;
    }
    
    /**
     * Verificar si un producto es alquilable
     */
    private function is_rentable_product($product) {
        if (!$product) {
            return false;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_product_settings';
        
        $is_rentable = $wpdb->get_var($wpdb->prepare(
            "SELECT is_rentable FROM $table WHERE product_id = %d AND variation_id = 0",
            $product->get_id()
        ));
        
        return $is_rentable == 1;
    }
    
    /**
     * Limpiar cache cuando se actualiza el precio
     */
    public function clear_cache_on_price_update($product, $updated_props) {
        if (in_array('price', $updated_props) || in_array('regular_price', $updated_props)) {
            $this->clear_cache();
        }
    }
    
    /**
     * Generar clave de cache
     */
    private function get_cache_key($product_id, $variation_id, $start_date, $end_date, $options) {
        return md5(serialize(array(
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'options' => $options
        )));
    }
    
    /**
     * Limpiar cache
     */
    public function clear_cache() {
        $this->calculation_cache = array();
    }
    
    /**
     * Respuesta de error
     */
    private function error_response($message) {
        return array(
            'error' => true,
            'message' => $message,
            'total' => 0,
            'formatted' => array(
                'total' => wc_price(0),
                'message' => $message
            )
        );
    }
    
    /**
     * Aplicar descuentos por duración (filtro)
     */
    public function apply_duration_discounts($price, $days, $product_id, $variation_id) {
        $settings = $this->get_product_settings($product_id, $variation_id);
        $discount = $this->calculate_duration_discount($price, $days, $settings);
        return $price - $discount;
    }
    
    /**
     * Aplicar tarifas de temporada (filtro)
     */
    public function apply_seasonal_rates($price, $start_date, $end_date, $product_id) {
        $adjustment = $this->calculate_seasonal_adjustment($price, $start_date, $end_date, $product_id);
        return $price + $adjustment;
    }
}