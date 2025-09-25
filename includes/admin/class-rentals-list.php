<?php
/**
 * Clase para manejar la lista de alquileres en el admin
 * Archivo: includes/admin/class-rentals-list.php
 */

if (!defined('ABSPATH')) {
    exit;
}

// Incluir la clase WP_List_Table si no está disponible
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class WC_Rentals_List_Table extends WP_List_Table {
    
    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct(array(
            'singular' => __('Alquiler', 'wc-rental-system'),
            'plural'   => __('Alquileres', 'wc-rental-system'),
            'ajax'     => false
        ));
        
        add_action('admin_head', array($this, 'admin_header'));
    }
    
    /**
     * Estilos CSS para la tabla
     */
    public function admin_header() {
        $page = (isset($_GET['page'])) ? esc_attr($_GET['page']) : false;
        if ('wc-rentals-list' != $page) {
            return;
        }
        ?>
        <style type="text/css">
            .wp-list-table .column-id { width: 5%; }
            .wp-list-table .column-order_id { width: 8%; }
            .wp-list-table .column-customer { width: 15%; }
            .wp-list-table .column-product { width: 20%; }
            .wp-list-table .column-dates { width: 15%; }
            .wp-list-table .column-days { width: 8%; }
            .wp-list-table .column-price { width: 10%; }
            .wp-list-table .column-status { width: 10%; }
            .wp-list-table .column-actions { width: 9%; }
            
            .rental-status {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
                text-transform: uppercase;
            }
            
            .status-pending {
                background: #f0ad4e;
                color: #fff;
            }
            
            .status-confirmed {
                background: #5bc0de;
                color: #fff;
            }
            
            .status-active {
                background: #5cb85c;
                color: #fff;
            }
            
            .status-completed {
                background: #999;
                color: #fff;
            }
            
            .status-cancelled {
                background: #d9534f;
                color: #fff;
            }
            
            .rental-overdue {
                background: #fff5f5;
            }
            
            .rental-today {
                background: #f0f8ff;
            }
            
            .rental-notes {
                font-size: 12px;
                color: #666;
                font-style: italic;
            }
            
            .rental-variation {
                font-size: 12px;
                color: #666;
            }
            
            .rental-filters {
                margin-bottom: 10px;
            }
            
            .rental-filters select,
            .rental-filters input[type="text"] {
                margin-right: 5px;
            }
        </style>
        <?php
    }
    
    /**
     * Mensaje cuando no hay alquileres
     */
    public function no_items() {
        _e('No se encontraron alquileres.', 'wc-rental-system');
    }
    
    /**
     * Definir columnas de la tabla
     */
    public function get_columns() {
        $columns = array(
            'cb'        => '<input type="checkbox" />',
            'id'        => __('ID', 'wc-rental-system'),
            'order_id'  => __('Pedido', 'wc-rental-system'),
            'customer'  => __('Cliente', 'wc-rental-system'),
            'product'   => __('Producto', 'wc-rental-system'),
            'dates'     => __('Fechas', 'wc-rental-system'),
            'days'      => __('Días', 'wc-rental-system'),
            'price'     => __('Precio', 'wc-rental-system'),
            'status'    => __('Estado', 'wc-rental-system'),
            'actions'   => __('Acciones', 'wc-rental-system'),
        );
        
        return $columns;
    }
    
    /**
     * Columnas ordenables
     */
    public function get_sortable_columns() {
        $sortable_columns = array(
            'id'        => array('id', true),
            'order_id'  => array('order_id', false),
            'customer'  => array('customer_name', false),
            'product'   => array('product_name', false),
            'dates'     => array('start_date', false),
            'days'      => array('rental_days', false),
            'price'     => array('rental_price', false),
            'status'    => array('status', false),
        );
        
        return $sortable_columns;
    }
    
    /**
     * Renderizar checkbox de fila
     */
    public function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="rental[]" value="%s" />',
            $item['id']
        );
    }
    
    /**
     * Renderizar columna ID
     */
    public function column_id($item) {
        $delete_nonce = wp_create_nonce('delete_rental');
        
        $title = '<strong>#' . $item['id'] . '</strong>';
        
        $actions = array(
            'edit' => sprintf(
                '<a href="?page=wc-rentals-edit&rental=%s">%s</a>',
                absint($item['id']),
                __('Editar', 'wc-rental-system')
            ),
            'view' => sprintf(
                '<a href="?page=wc-rentals-view&rental=%s">%s</a>',
                absint($item['id']),
                __('Ver', 'wc-rental-system')
            ),
            'delete' => sprintf(
                '<a href="?page=%s&action=%s&rental=%s&_wpnonce=%s" onclick="return confirm(\'¿Estás seguro de eliminar este alquiler?\')">%s</a>',
                esc_attr($_REQUEST['page']),
                'delete',
                absint($item['id']),
                $delete_nonce,
                __('Eliminar', 'wc-rental-system')
            ),
        );
        
        return $title . $this->row_actions($actions);
    }
    
    /**
     * Renderizar columna de pedido
     */
    public function column_order_id($item) {
        if ($item['order_id']) {
            $order = wc_get_order($item['order_id']);
            if ($order) {
                return sprintf(
                    '<a href="%s">#%s</a>',
                    $order->get_edit_order_url(),
                    $order->get_order_number()
                );
            }
        }
        return '—';
    }
    
    /**
     * Renderizar columna de cliente
     */
    public function column_customer($item) {
        $customer_info = '';
        
        if ($item['customer_id']) {
            $user = get_user_by('id', $item['customer_id']);
            if ($user) {
                $customer_info = sprintf(
                    '<a href="%s">%s</a><br><small>%s</small>',
                    get_edit_user_link($item['customer_id']),
                    esc_html($user->display_name),
                    esc_html($user->user_email)
                );
                
                // Añadir teléfono si existe
                $phone = get_user_meta($item['customer_id'], 'billing_phone', true);
                if ($phone) {
                    $customer_info .= '<br><small>📞 ' . esc_html($phone) . '</small>';
                }
            }
        } else {
            $customer_info = __('Cliente eliminado', 'wc-rental-system');
        }
        
        return $customer_info;
    }
    
    /**
     * Renderizar columna de producto
     */
    public function column_product($item) {
        $product_info = '';
        
        if ($item['product_id']) {
            $product = wc_get_product($item['product_id']);
            if ($product) {
                $product_info = sprintf(
                    '<a href="%s">%s</a>',
                    get_edit_post_link($item['product_id']),
                    esc_html($product->get_name())
                );
                
                // Si es una variación, mostrar atributos
                if ($item['variation_id']) {
                    $variation = wc_get_product($item['variation_id']);
                    if ($variation && $variation->is_type('variation')) {
                        $attributes = $variation->get_variation_attributes();
                        $attr_string = array();
                        
                        foreach ($attributes as $attr_name => $attr_value) {
                            $taxonomy = str_replace('attribute_', '', $attr_name);
                            $term = get_term_by('slug', $attr_value, $taxonomy);
                            $attr_string[] = $term ? $term->name : $attr_value;
                        }
                        
                        if (!empty($attr_string)) {
                            $product_info .= '<br><span class="rental-variation">' . 
                                            implode(', ', $attr_string) . '</span>';
                        }
                    }
                }
                
                // Añadir SKU si existe
                $sku = $product->get_sku();
                if ($sku) {
                    $product_info .= '<br><small>SKU: ' . esc_html($sku) . '</small>';
                }
            } else {
                $product_info = __('Producto eliminado', 'wc-rental-system');
            }
        }
        
        return $product_info;
    }
    
    /**
     * Renderizar columna de fechas
     */
    public function column_dates($item) {
        $today = current_time('Y-m-d');
        $start_date = $item['start_date'];
        $end_date = $item['end_date'];
        
        $dates_html = sprintf(
            '<span class="rental-dates">%s<br>↓<br>%s</span>',
            date_i18n('d/m/Y', strtotime($start_date)),
            date_i18n('d/m/Y', strtotime($end_date))
        );
        
        // Indicadores visuales
        if ($item['status'] == 'active' && $end_date < $today) {
            $dates_html .= '<br><span style="color: red; font-weight: bold;">⚠ ' . 
                          __('Vencido', 'wc-rental-system') . '</span>';
        } elseif ($start_date == $today) {
            $dates_html .= '<br><span style="color: green; font-weight: bold;">📅 ' . 
                          __('Inicia hoy', 'wc-rental-system') . '</span>';
        } elseif ($end_date == $today) {
            $dates_html .= '<br><span style="color: orange; font-weight: bold;">🔄 ' . 
                          __('Devuelve hoy', 'wc-rental-system') . '</span>';
        }
        
        return $dates_html;
    }
    
    /**
     * Renderizar columna de días
     */
    public function column_days($item) {
        $days_info = '<strong>' . $item['rental_days'] . '</strong>';
        
        // Si hay período de gracia, mostrarlo
        if ($item['grace_period_days'] > 0) {
            $days_info .= '<br><small>+' . $item['grace_period_days'] . ' ' . 
                         __('gracia', 'wc-rental-system') . '</small>';
        }
        
        return $days_info;
    }
    
    /**
     * Renderizar columna de precio
     */
    public function column_price($item) {
        $price_html = '<strong>' . wc_price($item['rental_price']) . '</strong>';
        
        // Mostrar depósito si existe
        if ($item['deposit_amount'] > 0) {
            $price_html .= '<br><small>' . __('Garantía:', 'wc-rental-system') . ' ' . 
                          wc_price($item['deposit_amount']) . '</small>';
        }
        
        // Calcular total
        $total = $item['rental_price'] + $item['deposit_amount'];
        if ($item['deposit_amount'] > 0) {
            $price_html .= '<br><small style="border-top: 1px solid #ddd; display: inline-block; padding-top: 2px;">' . 
                          __('Total:', 'wc-rental-system') . ' ' . wc_price($total) . '</small>';
        }
        
        return $price_html;
    }
    
    /**
     * Renderizar columna de estado
     */
    public function column_status($item) {
        $status_labels = array(
            'pending'   => __('Pendiente', 'wc-rental-system'),
            'confirmed' => __('Confirmado', 'wc-rental-system'),
            'active'    => __('Activo', 'wc-rental-system'),
            'completed' => __('Completado', 'wc-rental-system'),
            'cancelled' => __('Cancelado', 'wc-rental-system'),
        );
        
        $status = $item['status'];
        $label = isset($status_labels[$status]) ? $status_labels[$status] : ucfirst($status);
        
        $status_html = '<span class="rental-status status-' . esc_attr($status) . '">' . 
                      esc_html($label) . '</span>';
        
        // Añadir fecha de retorno si está completado
        if ($status == 'completed' && !empty($item['return_date'])) {
            $status_html .= '<br><small>' . __('Devuelto:', 'wc-rental-system') . ' ' . 
                           date_i18n('d/m/Y', strtotime($item['return_date'])) . '</small>';
        }
        
        return $status_html;
    }
    
    /**
     * Renderizar columna de acciones
     */
    public function column_actions($item) {
        $actions_html = '';
        
        // Botones de acción rápida según el estado
        switch ($item['status']) {
            case 'pending':
                $actions_html .= sprintf(
                    '<button class="button button-small rental-action" data-action="confirm" data-id="%s">%s</button>',
                    $item['id'],
                    __('Confirmar', 'wc-rental-system')
                );
                break;
                
            case 'confirmed':
                $actions_html .= sprintf(
                    '<button class="button button-small rental-action" data-action="activate" data-id="%s">%s</button>',
                    $item['id'],
                    __('Activar', 'wc-rental-system')
                );
                break;
                
            case 'active':
                $actions_html .= sprintf(
                    '<button class="button button-small button-primary rental-action" data-action="complete" data-id="%s">%s</button>',
                    $item['id'],
                    __('Completar', 'wc-rental-system')
                );
                break;
        }
        
        // Botón de cancelar (siempre disponible excepto si ya está cancelado o completado)
        if (!in_array($item['status'], array('cancelled', 'completed'))) {
            $actions_html .= sprintf(
                '<button class="button button-small rental-action" data-action="cancel" data-id="%s" style="color: #a00;">%s</button>',
                $item['id'],
                __('Cancelar', 'wc-rental-system')
            );
        }
        
        // Añadir botón de email
        $actions_html .= sprintf(
            '<button class="button button-small rental-email" data-id="%s" title="%s">📧</button>',
            $item['id'],
            __('Enviar email', 'wc-rental-system')
        );
        
        return $actions_html;
    }
    
    /**
     * Renderizar columna por defecto
     */
    public function column_default($item, $column_name) {
        return isset($item[$column_name]) ? $item[$column_name] : '—';
    }
    
    /**
     * Obtener acciones en masa
     */
    public function get_bulk_actions() {
        $actions = array(
            'bulk-confirm'   => __('Confirmar', 'wc-rental-system'),
            'bulk-activate'  => __('Activar', 'wc-rental-system'),
            'bulk-complete'  => __('Completar', 'wc-rental-system'),
            'bulk-cancel'    => __('Cancelar', 'wc-rental-system'),
            'bulk-delete'    => __('Eliminar', 'wc-rental-system'),
            'bulk-export'    => __('Exportar CSV', 'wc-rental-system'),
        );
        
        return $actions;
    }
    
    /**
     * Procesar acciones en masa
     */
    public function process_bulk_action() {
        // Seguridad
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        // Detectar cuando se activa una acción en masa
        if ('bulk-delete' === $this->current_action()) {
            $rental_ids = isset($_REQUEST['rental']) ? $_REQUEST['rental'] : array();
            
            if (!empty($rental_ids)) {
                foreach ($rental_ids as $id) {
                    $this->delete_rental($id);
                }
                
                wp_redirect(esc_url_raw(add_query_arg()));
                exit;
            }
        }
        
        // Procesar otras acciones en masa
        $valid_actions = array('bulk-confirm', 'bulk-activate', 'bulk-complete', 'bulk-cancel');
        
        if (in_array($this->current_action(), $valid_actions)) {
            $rental_ids = isset($_REQUEST['rental']) ? $_REQUEST['rental'] : array();
            $new_status = str_replace('bulk-', '', $this->current_action());
            
            if ($new_status == 'confirm') $new_status = 'confirmed';
            if ($new_status == 'activate') $new_status = 'active';
            if ($new_status == 'complete') $new_status = 'completed';
            if ($new_status == 'cancel') $new_status = 'cancelled';
            
            if (!empty($rental_ids)) {
                foreach ($rental_ids as $id) {
                    $this->update_rental_status($id, $new_status);
                }
                
                wp_redirect(esc_url_raw(add_query_arg()));
                exit;
            }
        }
        
        // Exportar CSV
        if ('bulk-export' === $this->current_action()) {
            $this->export_csv();
        }
    }
    
    /**
     * Preparar items para la tabla
     */
    public function prepare_items() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wc_rentals';
        $per_page = $this->get_items_per_page('rentals_per_page', 20);
        $current_page = $this->get_pagenum();
        
        // Configurar columnas
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        
        $this->_column_headers = array($columns, $hidden, $sortable);
        
        // Procesar acciones en masa
        $this->process_bulk_action();
        
        // Construir consulta
        $query = "SELECT r.*, 
                         p.post_title as product_name,
                         u.display_name as customer_name,
                         u.user_email as customer_email
                  FROM $table r
                  LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
                  LEFT JOIN {$wpdb->users} u ON r.customer_id = u.ID
                  WHERE 1=1";
        
        // Filtros
        if (!empty($_REQUEST['status'])) {
            $status = sanitize_text_field($_REQUEST['status']);
            $query .= $wpdb->prepare(" AND r.status = %s", $status);
        }
        
        if (!empty($_REQUEST['product_id'])) {
            $product_id = intval($_REQUEST['product_id']);
            $query .= $wpdb->prepare(" AND r.product_id = %d", $product_id);
        }
        
        if (!empty($_REQUEST['customer_id'])) {
            $customer_id = intval($_REQUEST['customer_id']);
            $query .= $wpdb->prepare(" AND r.customer_id = %d", $customer_id);
        }
        
        if (!empty($_REQUEST['date_from'])) {
            $date_from = sanitize_text_field($_REQUEST['date_from']);
            $query .= $wpdb->prepare(" AND r.start_date >= %s", $date_from);
        }
        
        if (!empty($_REQUEST['date_to'])) {
            $date_to = sanitize_text_field($_REQUEST['date_to']);
            $query .= $wpdb->prepare(" AND r.end_date <= %s", $date_to);
        }
        
        // Búsqueda
        if (!empty($_REQUEST['s'])) {
            $search = '%' . $wpdb->esc_like($_REQUEST['s']) . '%';
            $query .= $wpdb->prepare(
                " AND (r.id LIKE %s OR r.order_id LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s OR p.post_title LIKE %s)",
                $search, $search, $search, $search, $search
            );
        }
        
        // Ordenamiento
        $orderby = !empty($_REQUEST['orderby']) ? $_REQUEST['orderby'] : 'id';
        $order = !empty($_REQUEST['order']) ? $_REQUEST['order'] : 'DESC';
        
        // Mapear nombres de columna
        $orderby_mapping = array(
            'customer_name' => 'u.display_name',
            'product_name' => 'p.post_title',
            'start_date' => 'r.start_date',
            'rental_days' => 'r.rental_days',
            'rental_price' => 'r.rental_price',
            'status' => 'r.status',
            'id' => 'r.id',
            'order_id' => 'r.order_id',
        );
        
        $orderby_sql = isset($orderby_mapping[$orderby]) ? $orderby_mapping[$orderby] : 'r.id';
        $query .= " ORDER BY $orderby_sql $order";
        
        // Calcular paginación
        $total_items = $wpdb->get_var(str_replace('SELECT r.*, p.post_title as product_name, u.display_name as customer_name, u.user_email as customer_email', 'SELECT COUNT(*)', $query));
        
        // Aplicar límite y offset
        $offset = ($current_page - 1) * $per_page;
        $query .= $wpdb->prepare(" LIMIT %d OFFSET %d", $per_page, $offset);
        
        // Obtener resultados
        $this->items = $wpdb->get_results($query, ARRAY_A);
        
        // Configurar paginación
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ));
    }
    
    /**
     * Eliminar un alquiler
     */
    private function delete_rental($id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wc_rentals';
        $availability_table = $wpdb->prefix . 'wc_rental_availability';
        
        // Eliminar registros de disponibilidad asociados
        $wpdb->delete($availability_table, array('rental_id' => $id), array('%d'));
        
        // Eliminar el alquiler
        $wpdb->delete($table, array('id' => $id), array('%d'));
    }
    
    /**
     * Actualizar estado del alquiler
     */
    private function update_rental_status($id, $new_status) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wc_rentals';
        
        // Actualizar estado
        $wpdb->update(
            $table,
            array('status' => $new_status),
            array('id' => $id),
            array('%s'),
            array('%d')
        );
        
        // Si se completa, añadir fecha de retorno
        if ($new_status == 'completed') {
            $wpdb->update(
                $table,
                array('return_date' => current_time('Y-m-d')),
                array('id' => $id),
                array('%s'),
                array('%d')
            );
            
            // Liberar disponibilidad
            $this->release_availability($id);
        }
        
        // Si se cancela, liberar disponibilidad
        if ($new_status == 'cancelled') {
            $this->release_availability($id);
        }
        
        // Si se activa, bloquear disponibilidad
        if ($new_status == 'active') {
            $this->block_availability($id);
        }
        
        // Enviar notificación por email si está configurado
        $this->send_status_notification($id, $new_status);
    }
    
    /**
     * Liberar disponibilidad
     */
    private function release_availability($rental_id) {
        global $wpdb;
        
        $availability_table = $wpdb->prefix . 'wc_rental_availability';
        
        // Eliminar bloqueos asociados a este alquiler
        $wpdb->delete(
            $availability_table,
            array('rental_id' => $rental_id),
            array('%d')
        );
    }
    
    /**
     * Bloquear disponibilidad
     */
    private function block_availability($rental_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wc_rentals';
        $availability_table = $wpdb->prefix . 'wc_rental_availability';
        
        // Obtener datos del alquiler
        $rental = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $rental_id
        ));
        
        if (!$rental) {
            return;
        }
        
        // Crear rango de fechas a bloquear
        $start = new DateTime($rental->start_date);
        $end = new DateTime($rental->end_date);
        
        // Añadir período de gracia si existe
        if ($rental->grace_period_days > 0) {
            $end->modify('+' . $rental->grace_period_days . ' days');
        }
        
        // Bloquear cada día
        $period = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));
        
        foreach ($period as $date) {
            $wpdb->insert(
                $availability_table,
                array(
                    'product_id' => $rental->product_id,
                    'variation_id' => $rental->variation_id,
                    'blocked_date' => $date->format('Y-m-d'),
                    'rental_id' => $rental_id,
                    'reason' => 'rental'
                ),
                array('%d', '%d', '%s', '%d', '%s')
            );
        }
    }
    
    /**
     * Enviar notificación de cambio de estado
     */
    private function send_status_notification($rental_id, $new_status) {
        // Obtener configuración
        $settings = get_option('wc_rental_settings', array());
        
        // Verificar si las notificaciones están activas
        if (empty($settings['email_rental_confirmed']) && $new_status == 'confirmed') {
            return;
        }
        
        // Aquí implementarías el envío de emails
        // Por ahora solo registro para referencia futura
        do_action('wc_rental_status_changed', $rental_id, $new_status);
    }
    
    /**
     * Exportar a CSV
     */
    private function export_csv() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wc_rentals';
        
        // Obtener todos los alquileres seleccionados o todos si no hay selección
        $rental_ids = isset($_REQUEST['rental']) ? $_REQUEST['rental'] : array();
        
        if (!empty($rental_ids)) {
            $ids_string = implode(',', array_map('intval', $rental_ids));
            $where = "WHERE r.id IN ($ids_string)";
        } else {
            $where = "";
        }
        
        $rentals = $wpdb->get_results(
            "SELECT r.*, 
                    p.post_title as product_name,
                    u.display_name as customer_name,
                    u.user_email as customer_email
             FROM $table r
             LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
             LEFT JOIN {$wpdb->users} u ON r.customer_id = u.ID
             $where
             ORDER BY r.id DESC",
            ARRAY_A
        );
        
        // Headers CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=alquileres-' . date('Y-m-d') . '.csv');
        
        // Crear archivo
        $output = fopen('php://output', 'w');
        
        // Añadir BOM para Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Headers
        fputcsv($output, array(
            'ID',
            'Pedido',
            'Cliente',
            'Email',
            'Producto',
            'Fecha Inicio',
            'Fecha Fin',
            'Días',
            'Precio',
            'Depósito',
            'Estado',
            'Creado'
        ));
        
        // Datos
        foreach ($rentals as $rental) {
            fputcsv($output, array(
                $rental['id'],
                $rental['order_id'],
                $rental['customer_name'],
                $rental['customer_email'],
                $rental['product_name'],
                $rental['start_date'],
                $rental['end_date'],
                $rental['rental_days'],
                $rental['rental_price'],
                $rental['deposit_amount'],
                $rental['status'],
                $rental['created_at']
            ));
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Mostrar filtros adicionales
     */
    public function extra_tablenav($which) {
        if ($which == 'top') {
            ?>
            <div class="alignleft actions rental-filters">
                <!-- Filtro por estado -->
                <select name="status" id="filter-by-status">
                    <option value=""><?php _e('Todos los estados', 'wc-rental-system'); ?></option>
                    <option value="pending" <?php selected(isset($_REQUEST['status']) ? $_REQUEST['status'] : '', 'pending'); ?>>
                        <?php _e('Pendiente', 'wc-rental-system'); ?>
                    </option>
                    <option value="confirmed" <?php selected(isset($_REQUEST['status']) ? $_REQUEST['status'] : '', 'confirmed'); ?>>
                        <?php _e('Confirmado', 'wc-rental-system'); ?>
                    </option>
                    <option value="active" <?php selected(isset($_REQUEST['status']) ? $_REQUEST['status'] : '', 'active'); ?>>
                        <?php _e('Activo', 'wc-rental-system'); ?>
                    </option>
                    <option value="completed" <?php selected(isset($_REQUEST['status']) ? $_REQUEST['status'] : '', 'completed'); ?>>
                        <?php _e('Completado', 'wc-rental-system'); ?>
                    </option>
                    <option value="cancelled" <?php selected(isset($_REQUEST['status']) ? $_REQUEST['status'] : '', 'cancelled'); ?>>
                        <?php _e('Cancelado', 'wc-rental-system'); ?>
                    </option>
                </select>
                
                <!-- Filtro por producto -->
                <select name="product_id" id="filter-by-product">
                    <option value=""><?php _e('Todos los productos', 'wc-rental-system'); ?></option>
                    <?php
                    global $wpdb;
                    $products = $wpdb->get_results(
                        "SELECT DISTINCT p.ID, p.post_title 
                         FROM {$wpdb->posts} p
                         INNER JOIN {$wpdb->prefix}wc_rental_product_settings rps ON p.ID = rps.product_id
                         WHERE p.post_type = 'product' 
                         AND p.post_status = 'publish'
                         AND rps.is_rentable = 1
                         ORDER BY p.post_title"
                    );
                    
                    foreach ($products as $product) {
                        ?>
                        <option value="<?php echo $product->ID; ?>" 
                            <?php selected(isset($_REQUEST['product_id']) ? $_REQUEST['product_id'] : '', $product->ID); ?>>
                            <?php echo esc_html($product->post_title); ?>
                        </option>
                        <?php
                    }
                    ?>
                </select>
                
                <!-- Filtro por fechas -->
                <input type="text" name="date_from" id="date_from" class="rental-datepicker" 
                    placeholder="<?php _e('Desde', 'wc-rental-system'); ?>"
                    value="<?php echo isset($_REQUEST['date_from']) ? esc_attr($_REQUEST['date_from']) : ''; ?>">
                
                <input type="text" name="date_to" id="date_to" class="rental-datepicker" 
                    placeholder="<?php _e('Hasta', 'wc-rental-system'); ?>"
                    value="<?php echo isset($_REQUEST['date_to']) ? esc_attr($_REQUEST['date_to']) : ''; ?>">
                
                <?php submit_button(__('Filtrar', 'wc-rental-system'), '', 'filter_action', false); ?>
                
                <?php if (!empty($_REQUEST['status']) || !empty($_REQUEST['product_id']) || 
                         !empty($_REQUEST['date_from']) || !empty($_REQUEST['date_to'])) : ?>
                    <a href="?page=wc-rentals-list" class="button">
                        <?php _e('Limpiar filtros', 'wc-rental-system'); ?>
                    </a>
                <?php endif; ?>
            </div>
            
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    // Inicializar datepicker
                    $('.rental-datepicker').datepicker({
                        dateFormat: 'yy-mm-dd',
                        changeMonth: true,
                        changeYear: true
                    });
                    
                    // Manejar acciones rápidas
                    $('.rental-action').on('click', function() {
                        var action = $(this).data('action');
                        var id = $(this).data('id');
                        var button = $(this);
                        
                        if (action === 'cancel') {
                            if (!confirm('<?php _e('¿Estás seguro de cancelar este alquiler?', 'wc-rental-system'); ?>')) {
                                return false;
                            }
                        }
                        
                        button.prop('disabled', true).text('...');
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'update_rental_status',
                                rental_id: id,
                                new_status: action,
                                nonce: '<?php echo wp_create_nonce('rental_status_update'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    location.reload();
                                } else {
                                    alert(response.data.message || 'Error al actualizar el estado');
                                    button.prop('disabled', false).text(button.text());
                                }
                            }
                        });
                    });
                    
                    // Email rápido
                    $('.rental-email').on('click', function() {
                        var id = $(this).data('id');
                        // Aquí implementarías el modal de email
                        alert('Función de email en desarrollo para alquiler #' + id);
                    });
                });
            </script>
            <?php
        }
    }
}

/**
 * Clase auxiliar para manejar la lista cuando se llama directamente
 */
class WC_Rental_Rentals_List {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_update_rental_status', array($this, 'ajax_update_status'));
    }
    
    /**
     * Ajax: Actualizar estado del alquiler
     */
    public function ajax_update_status() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'rental_status_update')) {
            wp_send_json_error(array('message' => __('Error de seguridad', 'wc-rental-system')));
        }
        
        // Verificar permisos
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Sin permisos', 'wc-rental-system')));
        }
        
        $rental_id = intval($_POST['rental_id']);
        $action = sanitize_text_field($_POST['new_status']);
        
        // Mapear acción a estado
        $status_map = array(
            'confirm'  => 'confirmed',
            'activate' => 'active',
            'complete' => 'completed',
            'cancel'   => 'cancelled'
        );
        
        $new_status = isset($status_map[$action]) ? $status_map[$action] : false;
        
        if (!$new_status) {
            wp_send_json_error(array('message' => __('Acción inválida', 'wc-rental-system')));
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        // Actualizar estado
        $result = $wpdb->update(
            $table,
            array('status' => $new_status),
            array('id' => $rental_id),
            array('%s'),
            array('%d')
        );
        
        if ($result !== false) {
            // Acciones adicionales según el estado
            if ($new_status == 'completed') {
                $wpdb->update(
                    $table,
                    array('return_date' => current_time('Y-m-d')),
                    array('id' => $rental_id),
                    array('%s'),
                    array('%d')
                );
            }
            
            wp_send_json_success(array(
                'message' => __('Estado actualizado correctamente', 'wc-rental-system'),
                'new_status' => $new_status
            ));
        } else {
            wp_send_json_error(array('message' => __('Error al actualizar el estado', 'wc-rental-system')));
        }
    }
}