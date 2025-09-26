<?php
/**
 * Clase para verificar disponibilidad de productos en alquiler
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Rental_Availability_Checker {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Constructor privado para Singleton
    }
    
    /**
     * Verificar disponibilidad de un producto
     */
    public function check_availability($product_id, $variation_id = 0, $start_date, $end_date, $exclude_rental_id = 0) {
        global $wpdb;
        
        $availability_table = $wpdb->prefix . 'wc_rental_availability';
        
        // Verificar si hay bloqueos en el rango de fechas
        $query = "SELECT COUNT(*) FROM $availability_table 
                  WHERE product_id = %d 
                  AND (variation_id = %d OR variation_id = 0)
                  AND blocked_date BETWEEN %s AND %s";
        
        $params = array($product_id, $variation_id, $start_date, $end_date);
        
        if ($exclude_rental_id > 0) {
            $query .= " AND (rental_id != %d OR rental_id IS NULL)";
            $params[] = $exclude_rental_id;
        }
        
        $blocked_count = $wpdb->get_var($wpdb->prepare($query, $params));
        
        return array(
            'available' => $blocked_count == 0,
            'message' => $blocked_count == 0 ? 
                __('Disponible para las fechas seleccionadas', 'wc-rental-system') : 
                __('No disponible para estas fechas', 'wc-rental-system'),
            'blocked_days' => $blocked_count
        );
    }
    
    /**
     * Verificar si está disponible (método simplificado)
     */
    public function is_available($product_id, $start_date, $end_date, $variation_id = 0) {
        $result = $this->check_availability($product_id, $variation_id, $start_date, $end_date);
        return $result['available'];
    }
    
    /**
     * Bloquear fechas para un alquiler
     */
    public function block_dates_for_rental($rental_id, $product_id, $variation_id, $start_date, $end_date, $settings = array()) {
        global $wpdb;
        
        $availability_table = $wpdb->prefix . 'wc_rental_availability';
        
        // Crear rango de fechas a bloquear
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        
        // Añadir período de gracia si existe
        $grace_days = isset($settings['grace_period_days']) ? $settings['grace_period_days'] : 0;
        if ($grace_days > 0) {
            $end->modify('+' . $grace_days . ' days');
        }
        
        // Bloquear cada día
        $period = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));
        
        foreach ($period as $date) {
            $wpdb->replace(
                $availability_table,
                array(
                    'product_id' => $product_id,
                    'variation_id' => $variation_id,
                    'blocked_date' => $date->format('Y-m-d'),
                    'rental_id' => $rental_id,
                    'reason' => 'rental'
                ),
                array('%d', '%d', '%s', '%d', '%s')
            );
        }
    }
    
    /**
     * Liberar fechas de un alquiler
     */
    public function release_dates_for_rental($rental_id) {
        global $wpdb;
        
        $availability_table = $wpdb->prefix . 'wc_rental_availability';
        
        return $wpdb->delete(
            $availability_table,
            array('rental_id' => $rental_id),
            array('%d')
        );
    }
    
    /**
     * Obtener fechas bloqueadas para un producto
     */
    public function get_blocked_dates($product_id, $variation_id = 0, $start_date = null, $end_date = null) {
        global $wpdb;
        
        $availability_table = $wpdb->prefix . 'wc_rental_availability';
        
        $query = "SELECT blocked_date FROM $availability_table 
                  WHERE product_id = %d 
                  AND (variation_id = %d OR variation_id = 0)";
        
        $params = array($product_id, $variation_id);
        
        if ($start_date && $end_date) {
            $query .= " AND blocked_date BETWEEN %s AND %s";
            $params[] = $start_date;
            $params[] = $end_date;
        } else {
            $query .= " AND blocked_date >= CURDATE()";
        }
        
        $query .= " ORDER BY blocked_date";
        
        return $wpdb->get_col($wpdb->prepare($query, $params));
    }
}
