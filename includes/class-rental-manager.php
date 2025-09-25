<?php
/**
 * Clase central para gestionar todas las operaciones de alquileres
 * Archivo: includes/class-rental-manager.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Rental_Manager {
    
    /**
     * Instancia única
     */
    private static $instance = null;
    
    /**
     * Verificador de disponibilidad
     */
    private $availability_checker;
    
    /**
     * Calculadora de precios
     */
    private $price_calculator;
    
    /**
     * Estados válidos de alquiler
     */
    private $valid_statuses = array(
        'pending',    // Pendiente de confirmación
        'confirmed',  // Confirmado pero no iniciado
        'active',     // En curso
        'completed',  // Completado
        'cancelled',  // Cancelado
        'overdue'     // Vencido
    );
    
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
        // Inicializar componentes
        $this->availability_checker = WC_Rental_Availability_Checker::get_instance();
        $this->price_calculator = WC_Rental_Price_Calculator::get_instance();
        
        // Hooks para gestión automática
        add_action('init', array($this, 'schedule_cron_jobs'));
        add_action('wc_rental_check_status', array($this, 'check_and_update_statuses'));
        add_action('wc_rental_send_reminders', array($this, 'send_reminder_emails'));
        
        // Hooks para sincronización con pedidos
        add_action('woocommerce_order_status_changed', array($this, 'sync_with_order_status'), 10, 3);
        add_action('woocommerce_thankyou', array($this, 'create_rental_from_order'), 10, 1);
        
        // Hooks para notificaciones
        add_action('wc_rental_status_changed', array($this, 'handle_status_change'), 10, 3);
        
        // Ajax handlers para admin
        add_action('wp_ajax_create_manual_rental', array($this, 'ajax_create_manual_rental'));
        add_action('wp_ajax_update_rental_status', array($this, 'ajax_update_rental_status'));
        add_action('wp_ajax_get_rental_details', array($this, 'ajax_get_rental_details'));
    }
    
    /**
     * Crear un nuevo alquiler
     * 
     * @param array $data Datos del alquiler
     * @return int|WP_Error ID del alquiler creado o error
     */
    public function create_rental($data) {
        global $wpdb;
        
        // Validar datos requeridos
        $validation = $this->validate_rental_data($data);
        if (is_wp_error($validation)) {
            return $validation;
        }
        
        // Verificar disponibilidad
        $availability = $this->availability_checker->check_availability(
            $data['product_id'],
            $data['variation_id'] ?? 0,
            $data['start_date'],
            $data['end_date']
        );
        
        if (!$availability['available']) {
            return new WP_Error(
                'not_available',
                $availability['message'],
                $availability
            );
        }
        
        // Calcular precio si no se proporciona
        if (!isset($data['rental_price']) || !isset($data['deposit_amount'])) {
            $price_calculation = $this->price_calculator->calculate(
                $data['product_id'],
                $data['variation_id'] ?? 0,
                $data['start_date'],
                $data['end_date'],
                $data['price_options'] ?? array()
            );
            
            if (isset($price_calculation['error'])) {
                return new WP_Error('price_calculation_failed', $price_calculation['message']);
            }
            
            $data['rental_price'] = $price_calculation['subtotal'];
            $data['deposit_amount'] = $price_calculation['deposit'];
        }
        
        // Calcular días de alquiler
        $start = new DateTime($data['start_date']);
        $end = new DateTime($data['end_date']);
        $rental_days = $start->diff($end)->days;
        
        // Obtener configuración del producto
        $settings = $this->get_product_rental_settings($data['product_id'], $data['variation_id'] ?? 0);
        
        // Preparar datos para inserción
        $rental_data = array(
            'order_id' => $data['order_id'] ?? 0,
            'order_item_id' => $data['order_item_id'] ?? 0,
            'product_id' => $data['product_id'],
            'variation_id' => $data['variation_id'] ?? 0,
            'customer_id' => $data['customer_id'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'rental_days' => $rental_days,
            'rental_price' => $data['rental_price'],
            'deposit_amount' => $data['deposit_amount'],
            'grace_period_days' => $settings['grace_period_days'] ?? 1,
            'status' => $data['status'] ?? 'pending',
            'notes' => $data['notes'] ?? '',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );
        
        // Insertar en base de datos
        $table = $wpdb->prefix . 'wc_rentals';
        $result = $wpdb->insert($table, $rental_data);
        
        if ($result === false) {
            return new WP_Error('database_error', __('Error al crear el alquiler', 'wc-rental-system'));
        }
        
        $rental_id = $wpdb->insert_id;
        
        // Bloquear fechas si el estado lo requiere
        if (in_array($rental_data['status'], array('confirmed', 'active'))) {
            $this->availability_checker->block_dates_for_rental(
                $rental_id,
                $data['product_id'],
                $data['variation_id'] ?? 0,
                $data['start_date'],
                $data['end_date'],
                $settings
            );
        }
        
        // Guardar metadatos adicionales
        if (!empty($data['meta'])) {
            foreach ($data['meta'] as $key => $value) {
                $this->update_rental_meta($rental_id, $key, $value);
            }
        }
        
        // Registrar en el log
        $this->log_rental_event($rental_id, 'created', array(
            'user_id' => get_current_user_id(),
            'data' => $rental_data
        ));
        
        // Disparar acciones
        do_action('wc_rental_created', $rental_id, $rental_data);
        
        // Enviar notificaciones
        $this->send_rental_notification($rental_id, 'created');
        
        return $rental_id;
    }
    
    /**
     * Actualizar un alquiler existente
     * 
     * @param int $rental_id ID del alquiler
     * @param array $data Datos a actualizar
     * @return bool|WP_Error
     */
    public function update_rental($rental_id, $data) {
        global $wpdb;
        
        // Obtener alquiler actual
        $rental = $this->get_rental($rental_id);
        if (!$rental) {
            return new WP_Error('rental_not_found', __('Alquiler no encontrado', 'wc-rental-system'));
        }
        
        $table = $wpdb->prefix . 'wc_rentals';
        $update_data = array();
        
        // Validar y preparar campos actualizables
        $updatable_fields = array(
            'start_date', 'end_date', 'rental_price', 'deposit_amount',
            'status', 'notes', 'return_date'
        );
        
        foreach ($updatable_fields as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
            }
        }
        
        // Si se actualizan fechas, verificar disponibilidad
        if (isset($update_data['start_date']) || isset($update_data['end_date'])) {
            $new_start = $update_data['start_date'] ?? $rental->start_date;
            $new_end = $update_data['end_date'] ?? $rental->end_date;
            
            // Verificar disponibilidad excluyendo el alquiler actual
            $availability = $this->availability_checker->check_availability(
                $rental->product_id,
                $rental->variation_id,
                $new_start,
                $new_end,
                $rental_id
            );
            
            if (!$availability['available']) {
                return new WP_Error('not_available', $availability['message']);
            }
            
            // Recalcular días
            $start = new DateTime($new_start);
            $end = new DateTime($new_end);
            $update_data['rental_days'] = $start->diff($end)->days;
        }
        
        // Actualizar timestamp
        $update_data['updated_at'] = current_time('mysql');
        
        // Ejecutar actualización
        $result = $wpdb->update(
            $table,
            $update_data,
            array('id' => $rental_id)
        );
        
        if ($result === false) {
            return new WP_Error('database_error', __('Error al actualizar el alquiler', 'wc-rental-system'));
        }
        
        // Si cambió el estado, manejar consecuencias
        if (isset($update_data['status']) && $update_data['status'] !== $rental->status) {
            $this->handle_status_transition($rental_id, $rental->status, $update_data['status']);
        }
        
        // Si cambiaron las fechas, actualizar disponibilidad
        if (isset($update_data['start_date']) || isset($update_data['end_date'])) {
            // Liberar fechas anteriores
            $this->availability_checker->release_dates_for_rental($rental_id);
            
            // Bloquear nuevas fechas si el estado lo requiere
            if (in_array($update_data['status'] ?? $rental->status, array('confirmed', 'active'))) {
                $settings = $this->get_product_rental_settings($rental->product_id, $rental->variation_id);
                $this->availability_checker->block_dates_for_rental(
                    $rental_id,
                    $rental->product_id,
                    $rental->variation_id,
                    $new_start,
                    $new_end,
                    $settings
                );
            }
        }
        
        // Registrar en el log
        $this->log_rental_event($rental_id, 'updated', array(
            'user_id' => get_current_user_id(),
            'changes' => $update_data
        ));
        
        // Disparar acciones
        do_action('wc_rental_updated', $rental_id, $update_data, $rental);
        
        return true;
    }
    
    /**
     * Cambiar estado de un alquiler
     * 
     * @param int $rental_id ID del alquiler
     * @param string $new_status Nuevo estado
     * @param string $note Nota opcional
     * @return bool|WP_Error
     */
    public function update_rental_status($rental_id, $new_status, $note = '') {
        // Validar estado
        if (!in_array($new_status, $this->valid_statuses)) {
            return new WP_Error('invalid_status', __('Estado inválido', 'wc-rental-system'));
        }
        
        // Obtener alquiler
        $rental = $this->get_rental($rental_id);
        if (!$rental) {
            return new WP_Error('rental_not_found', __('Alquiler no encontrado', 'wc-rental-system'));
        }
        
        $old_status = $rental->status;
        
        // Si no hay cambio, retornar
        if ($old_status === $new_status) {
            return true;
        }
        
        // Validar transición de estado
        if (!$this->is_valid_status_transition($old_status, $new_status)) {
            return new WP_Error(
                'invalid_transition',
                sprintf(
                    __('No se puede cambiar de %s a %s', 'wc-rental-system'),
                    $old_status,
                    $new_status
                )
            );
        }
        
        // Actualizar estado
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        $update_data = array(
            'status' => $new_status,
            'updated_at' => current_time('mysql')
        );
        
        // Si se completa, registrar fecha de devolución
        if ($new_status === 'completed' && !$rental->return_date) {
            $update_data['return_date'] = current_time('Y-m-d');
        }
        
        $result = $wpdb->update(
            $table,
            $update_data,
            array('id' => $rental_id)
        );
        
        if ($result === false) {
            return new WP_Error('database_error', __('Error al actualizar el estado', 'wc-rental-system'));
        }
        
        // Manejar transición
        $this->handle_status_transition($rental_id, $old_status, $new_status);
        
        // Registrar nota si se proporcionó
        if (!empty($note)) {
            $this->add_rental_note($rental_id, $note, array(
                'type' => 'status_change',
                'old_status' => $old_status,
                'new_status' => $new_status
            ));
        }
        
        // Registrar en el log
        $this->log_rental_event($rental_id, 'status_changed', array(
            'user_id' => get_current_user_id(),
            'old_status' => $old_status,
            'new_status' => $new_status,
            'note' => $note
        ));
        
        // Disparar acciones
        do_action('wc_rental_status_changed', $rental_id, $old_status, $new_status);
        
        // Enviar notificaciones
        $this->send_rental_notification($rental_id, 'status_changed', array(
            'old_status' => $old_status,
            'new_status' => $new_status
        ));
        
        return true;
    }
    
    /**
     * Eliminar un alquiler
     * 
     * @param int $rental_id ID del alquiler
     * @param bool $force Forzar eliminación (sin papelera)
     * @return bool|WP_Error
     */
    public function delete_rental($rental_id, $force = false) {
        global $wpdb;
        
        // Obtener alquiler
        $rental = $this->get_rental($rental_id);
        if (!$rental) {
            return new WP_Error('rental_not_found', __('Alquiler no encontrado', 'wc-rental-system'));
        }
        
        // Verificar permisos
        if (!current_user_can('delete_rentals')) {
            return new WP_Error('permission_denied', __('No tienes permisos para eliminar alquileres', 'wc-rental-system'));
        }
        
        // No permitir eliminar alquileres activos a menos que se fuerce
        if ($rental->status === 'active' && !$force) {
            return new WP_Error('active_rental', __('No se pueden eliminar alquileres activos', 'wc-rental-system'));
        }
        
        // Liberar fechas bloqueadas
        $this->availability_checker->release_dates_for_rental($rental_id);
        
        // Eliminar metadatos
        $this->delete_all_rental_meta($rental_id);
        
        // Eliminar notas
        $this->delete_all_rental_notes($rental_id);
        
        // Eliminar registro principal
        $table = $wpdb->prefix . 'wc_rentals';
        $result = $wpdb->delete($table, array('id' => $rental_id));
        
        if ($result === false) {
            return new WP_Error('database_error', __('Error al eliminar el alquiler', 'wc-rental-system'));
        }
        
        // Registrar en el log
        $this->log_rental_event($rental_id, 'deleted', array(
            'user_id' => get_current_user_id(),
            'rental_data' => $rental
        ));
        
        // Disparar acciones
        do_action('wc_rental_deleted', $rental_id, $rental);
        
        return true;
    }
    
    /**
     * Obtener un alquiler
     * 
     * @param int $rental_id ID del alquiler
     * @return object|null
     */
    public function get_rental($rental_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        $rental = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, p.post_title as product_name, u.display_name as customer_name, u.user_email as customer_email
             FROM $table r
             LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
             LEFT JOIN {$wpdb->users} u ON r.customer_id = u.ID
             WHERE r.id = %d",
            $rental_id
        ));
        
        if ($rental) {
            // Añadir metadatos
            $rental->meta = $this->get_all_rental_meta($rental_id);
            
            // Añadir información del producto
            $rental->product = wc_get_product($rental->variation_id > 0 ? $rental->variation_id : $rental->product_id);
            
            // Añadir información del pedido si existe
            if ($rental->order_id) {
                $rental->order = wc_get_order($rental->order_id);
            }
        }
        
        return $rental;
    }
    
    /**
     * Obtener alquileres con filtros
     * 
     * @param array $args Argumentos de búsqueda
     * @return array
     */
    public function get_rentals($args = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        $defaults = array(
            'customer_id' => 0,
            'product_id' => 0,
            'status' => '',
            'start_date_from' => '',
            'start_date_to' => '',
            'end_date_from' => '',
            'end_date_to' => '',
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => 20,
            'offset' => 0,
            'include_meta' => false
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Construir consulta
        $query = "SELECT r.*, p.post_title as product_name, u.display_name as customer_name, u.user_email as customer_email
                  FROM $table r
                  LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
                  LEFT JOIN {$wpdb->users} u ON r.customer_id = u.ID
                  WHERE 1=1";
        
        $params = array();
        
        // Aplicar filtros
        if ($args['customer_id']) {
            $query .= " AND r.customer_id = %d";
            $params[] = $args['customer_id'];
        }
        
        if ($args['product_id']) {
            $query .= " AND r.product_id = %d";
            $params[] = $args['product_id'];
        }
        
        if ($args['status']) {
            if (is_array($args['status'])) {
                $placeholders = array_fill(0, count($args['status']), '%s');
                $query .= " AND r.status IN (" . implode(',', $placeholders) . ")";
                $params = array_merge($params, $args['status']);
            } else {
                $query .= " AND r.status = %s";
                $params[] = $args['status'];
            }
        }
        
        if ($args['start_date_from']) {
            $query .= " AND r.start_date >= %s";
            $params[] = $args['start_date_from'];
        }
        
        if ($args['start_date_to']) {
            $query .= " AND r.start_date <= %s";
            $params[] = $args['start_date_to'];
        }
        
        if ($args['end_date_from']) {
            $query .= " AND r.end_date >= %s";
            $params[] = $args['end_date_from'];
        }
        
        if ($args['end_date_to']) {
            $query .= " AND r.end_date <= %s";
            $params[] = $args['end_date_to'];
        }
        
        // Ordenamiento
        $allowed_orderby = array('id', 'created_at', 'start_date', 'end_date', 'status', 'rental_price');
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'created_at';
        $order = in_array(strtoupper($args['order']), array('ASC', 'DESC')) ? $args['order'] : 'DESC';
        
        $query .= " ORDER BY r.$orderby $order";
        
        // Límite y offset
        if ($args['limit'] > 0) {
            $query .= " LIMIT %d OFFSET %d";
            $params[] = $args['limit'];
            $params[] = $args['offset'];
        }
        
        // Ejecutar consulta
        if (!empty($params)) {
            $rentals = $wpdb->get_results($wpdb->prepare($query, $params));
        } else {
            $rentals = $wpdb->get_results($query);
        }
        
        // Añadir metadatos si se solicita
        if ($args['include_meta'] && !empty($rentals)) {
            foreach ($rentals as &$rental) {
                $rental->meta = $this->get_all_rental_meta($rental->id);
            }
        }
        
        return $rentals;
    }
    
    /**
     * Crear alquiler desde pedido
     * 
     * @param int $order_id ID del pedido
     * @return array IDs de alquileres creados
     */
    public function create_rental_from_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return array();
        }
        
        $rental_ids = array();
        
        // Procesar cada item del pedido
        foreach ($order->get_items() as $item_id => $item) {
            // Verificar si es un producto de alquiler
            $rental_data = $item->get_meta('_rental_data');
            if (empty($rental_data)) {
                continue;
            }
            
            // Verificar si ya existe un alquiler para este item
            if ($this->rental_exists_for_order_item($order_id, $item_id)) {
                continue;
            }
            
            // Preparar datos del alquiler
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            
            $rental_args = array(
                'order_id' => $order_id,
                'order_item_id' => $item_id,
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'customer_id' => $order->get_customer_id(),
                'start_date' => $rental_data['start_date'],
                'end_date' => $rental_data['end_date'],
                'rental_price' => $rental_data['rental_price'],
                'deposit_amount' => $rental_data['rental_deposit'],
                'status' => $this->get_initial_rental_status($order->get_status()),
                'notes' => sprintf(__('Creado desde pedido #%s', 'wc-rental-system'), $order->get_order_number())
            );
            
            // Crear alquiler
            $rental_id = $this->create_rental($rental_args);
            
            if (!is_wp_error($rental_id)) {
                $rental_ids[] = $rental_id;
                
                // Guardar referencia en el item del pedido
                $item->update_meta_data('_rental_id', $rental_id);
                $item->save();
            }
        }
        
        return $rental_ids;
    }
    
    /**
     * Métodos de gestión de estado
     */
    
    /**
     * Manejar transición de estado
     */
    private function handle_status_transition($rental_id, $old_status, $new_status) {
        $rental = $this->get_rental($rental_id);
        if (!$rental) {
            return;
        }
        
        $settings = $this->get_product_rental_settings($rental->product_id, $rental->variation_id);
        
        // Acciones según el nuevo estado
        switch ($new_status) {
            case 'confirmed':
                // Bloquear fechas
                $this->availability_checker->block_dates_for_rental(
                    $rental_id,
                    $rental->product_id,
                    $rental->variation_id,
                    $rental->start_date,
                    $rental->end_date,
                    $settings
                );
                break;
                
            case 'active':
                // Asegurar que las fechas están bloqueadas
                $this->availability_checker->block_dates_for_rental(
                    $rental_id,
                    $rental->product_id,
                    $rental->variation_id,
                    $rental->start_date,
                    $rental->end_date,
                    $settings
                );
                // Reducir stock si aplica
                $this->update_product_stock($rental->product_id, $rental->variation_id, -1);
                break;
                
            case 'completed':
                // Liberar fechas
                $this->availability_checker->release_dates_for_rental($rental_id);
                // Restaurar stock
                $this->update_product_stock($rental->product_id, $rental->variation_id, 1);
                // Procesar devolución de depósito
                $this->process_deposit_return($rental_id);
                break;
                
            case 'cancelled':
                // Liberar fechas
                $this->availability_checker->release_dates_for_rental($rental_id);
                // Restaurar stock si estaba activo
                if ($old_status === 'active') {
                    $this->update_product_stock($rental->product_id, $rental->variation_id, 1);
                }
                // Procesar reembolso si aplica
                $this->process_cancellation_refund($rental_id);
                break;
                
            case 'overdue':
                // Aplicar cargos por retraso
                $this->apply_late_charges($rental_id);
                break;
        }
    }
    
    /**
     * Validar transición de estado
     */
    private function is_valid_status_transition($from, $to) {
        $valid_transitions = array(
            'pending' => array('confirmed', 'cancelled'),
            'confirmed' => array('active', 'cancelled'),
            'active' => array('completed', 'overdue', 'cancelled'),
            'overdue' => array('completed'),
            'completed' => array(),
            'cancelled' => array()
        );
        
        if (!isset($valid_transitions[$from])) {
            return false;
        }
        
        return in_array($to, $valid_transitions[$from]);
    }
    
    /**
     * Obtener estado inicial según estado del pedido
     */
    private function get_initial_rental_status($order_status) {
        $status_map = array(
            'pending' => 'pending',
            'processing' => 'confirmed',
            'completed' => 'confirmed',
            'on-hold' => 'pending',
            'cancelled' => 'cancelled',
            'refunded' => 'cancelled',
            'failed' => 'cancelled'
        );
        
        return isset($status_map[$order_status]) ? $status_map[$order_status] : 'pending';
    }
    
    /**
     * Sincronizar con estado del pedido
     */
    public function sync_with_order_status($order_id, $old_status, $new_status) {
        // Obtener alquileres del pedido
        $rentals = $this->get_rentals(array(
            'order_id' => $order_id,
            'limit' => -1
        ));
        
        foreach ($rentals as $rental) {
            // Mapear estado del pedido a estado del alquiler
            $rental_status = $this->get_initial_rental_status($new_status);
            
            // Solo actualizar si es una transición válida
            if ($this->is_valid_status_transition($rental->status, $rental_status)) {
                $this->update_rental_status(
                    $rental->id,
                    $rental_status,
                    sprintf(__('Estado actualizado por cambio en pedido #%s', 'wc-rental-system'), $order_id)
                );
            }
        }
    }
    
    /**
     * Métodos de validación
     */
    
    /**
     * Validar datos del alquiler
     */
    private function validate_rental_data($data) {
        $required_fields = array('product_id', 'customer_id', 'start_date', 'end_date');
        
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                return new WP_Error(
                    'missing_field',
                    sprintf(__('Campo requerido faltante: %s', 'wc-rental-system'), $field)
                );
            }
        }
        
        // Validar fechas
        $start = DateTime::createFromFormat('Y-m-d', $data['start_date']);
        $end = DateTime::createFromFormat('Y-m-d', $data['end_date']);
        
        if (!$start || !$end) {
            return new WP_Error('invalid_dates', __('Formato de fecha inválido', 'wc-rental-system'));
        }
        
        if ($end <= $start) {
            return new WP_Error('invalid_date_range', __('La fecha de fin debe ser posterior a la de inicio', 'wc-rental-system'));
        }
        
        // Validar producto
        $product = wc_get_product($data['product_id']);
        if (!$product) {
            return new WP_Error('invalid_product', __('Producto inválido', 'wc-rental-system'));
        }
        
        // Validar cliente
        if ($data['customer_id'] > 0) {
            $user = get_user_by('id', $data['customer_id']);
            if (!$user) {
                return new WP_Error('invalid_customer', __('Cliente inválido', 'wc-rental-system'));
            }
        }
        
        return true;
    }
    
    /**
     * Verificar si existe alquiler para un item de pedido
     */
    private function rental_exists_for_order_item($order_id, $item_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE order_id = %d AND order_item_id = %d",
            $order_id,
            $item_id
        ));
        
        return $count > 0;
    }
    
    /**
     * Métodos de notificación
     */
    
    /**
     * Enviar notificación de alquiler
     */
    private function send_rental_notification($rental_id, $type, $data = array()) {
        $rental = $this->get_rental($rental_id);
        if (!$rental) {
            return;
        }
        
        // Verificar configuración de notificaciones
        $settings = get_option('wc_rental_settings', array());
        
        switch ($type) {
            case 'created':
                if (!empty($settings['email_new_rental'])) {
                    do_action('wc_rental_send_email_new_rental', $rental);
                }
                break;
                
            case 'status_changed':
                if ($data['new_status'] === 'confirmed' && !empty($settings['email_rental_confirmed'])) {
                    do_action('wc_rental_send_email_rental_confirmed', $rental);
                } elseif ($data['new_status'] === 'cancelled' && !empty($settings['email_rental_cancelled'])) {
                    do_action('wc_rental_send_email_rental_cancelled', $rental);
                }
                break;
                
            case 'reminder':
                if (!empty($settings['email_rental_reminder'])) {
                    do_action('wc_rental_send_email_reminder', $rental);
                }
                break;
        }
    }
    
    /**
     * Enviar recordatorios por email
     */
    public function send_reminder_emails() {
        $settings = get_option('wc_rental_settings', array());
        $reminder_days = isset($settings['reminder_days']) ? intval($settings['reminder_days']) : 1;
        
        // Obtener alquileres que necesitan recordatorio
        $reminder_date = date('Y-m-d', strtotime("+{$reminder_days} days"));
        
        $rentals = $this->get_rentals(array(
            'status' => 'active',
            'end_date_from' => $reminder_date,
            'end_date_to' => $reminder_date,
            'limit' => -1
        ));
        
        foreach ($rentals as $rental) {
            // Verificar si ya se envió recordatorio
            $reminder_sent = $this->get_rental_meta($rental->id, 'reminder_sent');
            
            if (!$reminder_sent) {
                $this->send_rental_notification($rental->id, 'reminder');
                $this->update_rental_meta($rental->id, 'reminder_sent', current_time('mysql'));
                
                // Añadir nota
                $this->add_rental_note($rental->id, __('Recordatorio de devolución enviado', 'wc-rental-system'));
            }
        }
    }
    
    /**
     * Métodos de gestión de metadatos
     */
    
    /**
     * Obtener metadato de alquiler
     */
    public function get_rental_meta($rental_id, $key, $single = true) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_meta';
        
        if ($single) {
            return $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM $table WHERE rental_id = %d AND meta_key = %s",
                $rental_id,
                $key
            ));
        } else {
            return $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM $table WHERE rental_id = %d AND meta_key = %s",
                $rental_id,
                $key
            ));
        }
    }
    
    /**
     * Actualizar metadato de alquiler
     */
    public function update_rental_meta($rental_id, $key, $value) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_meta';
        
        // Verificar si existe
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE rental_id = %d AND meta_key = %s",
            $rental_id,
            $key
        ));
        
        if ($exists) {
            // Actualizar
            return $wpdb->update(
                $table,
                array('meta_value' => maybe_serialize($value)),
                array('rental_id' => $rental_id, 'meta_key' => $key)
            );
        } else {
            // Insertar
            return $wpdb->insert(
                $table,
                array(
                    'rental_id' => $rental_id,
                    'meta_key' => $key,
                    'meta_value' => maybe_serialize($value)
                )
            );
        }
    }
    
    /**
     * Eliminar metadato de alquiler
     */
    public function delete_rental_meta($rental_id, $key) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_meta';
        
        return $wpdb->delete(
            $table,
            array('rental_id' => $rental_id, 'meta_key' => $key)
        );
    }
    
    /**
     * Obtener todos los metadatos
     */
    private function get_all_rental_meta($rental_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_meta';
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM $table WHERE rental_id = %d",
            $rental_id
        ));
        
        $meta = array();
        foreach ($results as $row) {
            $meta[$row->meta_key] = maybe_unserialize($row->meta_value);
        }
        
        return $meta;
    }
    
    /**
     * Eliminar todos los metadatos
     */
    private function delete_all_rental_meta($rental_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_meta';
        
        return $wpdb->delete($table, array('rental_id' => $rental_id));
    }
    
    /**
     * Métodos de notas y logs
     */
    
    /**
     * Añadir nota a un alquiler
     */
    public function add_rental_note($rental_id, $note, $data = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_notes';
        
        return $wpdb->insert(
            $table,
            array(
                'rental_id' => $rental_id,
                'note' => $note,
                'note_data' => maybe_serialize($data),
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql')
            )
        );
    }
    
    /**
     * Obtener notas de un alquiler
     */
    public function get_rental_notes($rental_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_notes';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE rental_id = %d ORDER BY created_at DESC",
            $rental_id
        ));
    }
    
    /**
     * Eliminar todas las notas
     */
    private function delete_all_rental_notes($rental_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_notes';
        
        return $wpdb->delete($table, array('rental_id' => $rental_id));
    }
    
    /**
     * Registrar evento en el log
     */
    private function log_rental_event($rental_id, $event, $data = array()) {
        if (!apply_filters('wc_rental_enable_logging', true)) {
            return;
        }
        
        $log_entry = array(
            'rental_id' => $rental_id,
            'event' => $event,
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'data' => $data
        );
        
        // Guardar en log (puede ser base de datos o archivo)
        do_action('wc_rental_log_event', $log_entry);
    }
    
    /**
     * Métodos de cron y mantenimiento
     */
    
    /**
     * Programar trabajos cron
     */
    public function schedule_cron_jobs() {
        if (!wp_next_scheduled('wc_rental_check_status')) {
            wp_schedule_event(time(), 'hourly', 'wc_rental_check_status');
        }
        
        if (!wp_next_scheduled('wc_rental_send_reminders')) {
            wp_schedule_event(time(), 'daily', 'wc_rental_send_reminders');
        }
    }
    
    /**
     * Verificar y actualizar estados automáticamente
     */
    public function check_and_update_statuses() {
        $today = current_time('Y-m-d');
        
        // Activar alquileres confirmados que inician hoy
        $starting_rentals = $this->get_rentals(array(
            'status' => 'confirmed',
            'start_date_from' => $today,
            'start_date_to' => $today,
            'limit' => -1
        ));
        
        foreach ($starting_rentals as $rental) {
            $this->update_rental_status($rental->id, 'active', __('Activado automáticamente al iniciar período', 'wc-rental-system'));
        }
        
        // Marcar como vencidos los alquileres activos que debieron terminar
        $overdue_rentals = $this->get_rentals(array(
            'status' => 'active',
            'end_date_to' => date('Y-m-d', strtotime('-1 day')),
            'limit' => -1
        ));
        
        foreach ($overdue_rentals as $rental) {
            $this->update_rental_status($rental->id, 'overdue', __('Marcado como vencido automáticamente', 'wc-rental-system'));
        }
    }
    
    /**
     * Métodos auxiliares
     */
    
    /**
     * Obtener configuración del producto
     */
    private function get_product_rental_settings($product_id, $variation_id = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_product_settings';
        
        $settings = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE product_id = %d AND variation_id = %d",
            $product_id,
            $variation_id
        ), ARRAY_A);
        
        if (!$settings && $variation_id > 0) {
            // Intentar con producto padre
            $settings = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE product_id = %d AND variation_id = 0",
                $product_id
            ), ARRAY_A);
        }
        
        return $settings ?: array(
            'grace_period_days' => 1,
            'buffer_days_before' => 0,
            'buffer_days_after' => 1
        );
    }
    
    /**
     * Actualizar stock del producto
     */
    private function update_product_stock($product_id, $variation_id, $quantity) {
        $product = wc_get_product($variation_id > 0 ? $variation_id : $product_id);
        
        if ($product && $product->managing_stock()) {
            $new_stock = $product->get_stock_quantity() + $quantity;
            $product->set_stock_quantity($new_stock);
            $product->save();
        }
    }
    
    /**
     * Procesar devolución de depósito
     */
    private function process_deposit_return($rental_id) {
        $rental = $this->get_rental($rental_id);
        if (!$rental || $rental->deposit_amount <= 0) {
            return;
        }
        
        // Verificar si hay cargos pendientes
        $charges = $this->get_rental_meta($rental_id, 'pending_charges');
        $deposit_to_return = $rental->deposit_amount;
        
        if ($charges && is_array($charges)) {
            $total_charges = array_sum($charges);
            $deposit_to_return = max(0, $rental->deposit_amount - $total_charges);
        }
        
        if ($deposit_to_return > 0) {
            // Procesar reembolso del depósito
            do_action('wc_rental_process_deposit_return', $rental_id, $deposit_to_return);
            
            // Registrar
            $this->add_rental_note($rental_id, sprintf(
                __('Depósito devuelto: %s', 'wc-rental-system'),
                wc_price($deposit_to_return)
            ));
        }
    }
    
    /**
     * Procesar reembolso por cancelación
     */
    private function process_cancellation_refund($rental_id) {
        $rental = $this->get_rental($rental_id);
        if (!$rental) {
            return;
        }
        
        // Calcular reembolso según política
        $days_until_start = (strtotime($rental->start_date) - time()) / 86400;
        $refund_percentage = $this->get_cancellation_refund_percentage($days_until_start);
        
        if ($refund_percentage > 0) {
            $refund_amount = ($rental->rental_price * $refund_percentage / 100) + $rental->deposit_amount;
            
            // Procesar reembolso
            do_action('wc_rental_process_cancellation_refund', $rental_id, $refund_amount);
            
            // Registrar
            $this->add_rental_note($rental_id, sprintf(
                __('Reembolso por cancelación: %s (%d%%)', 'wc-rental-system'),
                wc_price($refund_amount),
                $refund_percentage
            ));
        }
    }
    
    /**
     * Obtener porcentaje de reembolso por cancelación
     */
    private function get_cancellation_refund_percentage($days_until_start) {
        $policy = get_option('wc_rental_cancellation_policy', array(
            array('days' => 7, 'percentage' => 100),
            array('days' => 3, 'percentage' => 50),
            array('days' => 1, 'percentage' => 25),
            array('days' => 0, 'percentage' => 0)
        ));
        
        foreach ($policy as $rule) {
            if ($days_until_start >= $rule['days']) {
                return $rule['percentage'];
            }
        }
        
        return 0;
    }
    
    /**
     * Aplicar cargos por retraso
     */
    private function apply_late_charges($rental_id) {
        $rental = $this->get_rental($rental_id);
        if (!$rental) {
            return;
        }
        
        $days_late = (time() - strtotime($rental->end_date)) / 86400;
        $days_late = ceil($days_late);
        
        // Calcular cargo
        $calculator = WC_Rental_Price_Calculator::get_instance();
        $charges = $calculator->calculate_penalty_charges($rental_id, array(
            'late_return_days' => $days_late
        ));
        
        // Guardar cargos
        $this->update_rental_meta($rental_id, 'late_charges', $charges);
        
        // Notificar
        do_action('wc_rental_late_charges_applied', $rental_id, $charges);
    }
    
    /**
     * Handlers AJAX
     */
    
    /**
     * Ajax: Crear alquiler manual
     */
    public function ajax_create_manual_rental() {
        // Verificar permisos
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }
        
        // Verificar nonce
        check_ajax_referer('rental_nonce', 'nonce');
        
        // Obtener datos
        $data = array(
            'product_id' => intval($_POST['product_id']),
            'variation_id' => intval($_POST['variation_id'] ?? 0),
            'customer_id' => intval($_POST['customer_id']),
            'start_date' => sanitize_text_field($_POST['start_date']),
            'end_date' => sanitize_text_field($_POST['end_date']),
            'status' => sanitize_text_field($_POST['status'] ?? 'pending'),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? '')
        );
        
        // Crear alquiler
        $rental_id = $this->create_rental($data);
        
        if (is_wp_error($rental_id)) {
            wp_send_json_error($rental_id->get_error_message());
        }
        
        wp_send_json_success(array(
            'rental_id' => $rental_id,
            'message' => __('Alquiler creado exitosamente', 'wc-rental-system')
        ));
    }
    
    /**
     * Ajax: Actualizar estado
     */
    public function ajax_update_rental_status() {
        // Verificar permisos
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }
        
        // Verificar nonce
        check_ajax_referer('rental_nonce', 'nonce');
        
        $rental_id = intval($_POST['rental_id']);
        $new_status = sanitize_text_field($_POST['status']);
        
        $result = $this->update_rental_status($rental_id, $new_status);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success(__('Estado actualizado', 'wc-rental-system'));
    }
    
    /**
     * Ajax: Obtener detalles del alquiler
     */
    public function ajax_get_rental_details() {
        // Verificar nonce
        check_ajax_referer('rental_nonce', 'nonce');
        
        $rental_id = intval($_POST['rental_id']);
        $rental = $this->get_rental($rental_id);
        
        if (!$rental) {
            wp_send_json_error(__('Alquiler no encontrado', 'wc-rental-system'));
        }
        
        // Verificar permisos
        if (!current_user_can('manage_woocommerce') && $rental->customer_id != get_current_user_id()) {
            wp_send_json_error('Permission denied');
        }
        
        wp_send_json_success($rental);
    }
    
    /**
     * Obtener estadísticas de alquileres
     */
    public function get_rental_stats($period = 'month') {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        $date_format = '%Y-%m';
        if ($period === 'day') {
            $date_format = '%Y-%m-%d';
        } elseif ($period === 'year') {
            $date_format = '%Y';
        }
        
        $stats = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                DATE_FORMAT(created_at, %s) as period,
                COUNT(*) as total_rentals,
                SUM(rental_price) as total_revenue,
                SUM(deposit_amount) as total_deposits,
                AVG(rental_days) as avg_rental_days,
                COUNT(DISTINCT customer_id) as unique_customers
             FROM $table
             WHERE status IN ('active', 'completed')
             GROUP BY period
             ORDER BY period DESC
             LIMIT 12",
            $date_format
        ));
        
        return $stats;
    }
}