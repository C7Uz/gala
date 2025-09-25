<?php
/**
 * Clase para manejar pedidos de alquiler
 * Archivo: includes/class-rental-order.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Rental_Order {
    
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
        // Procesar pedido cuando se completa
        add_action('woocommerce_order_status_processing', array($this, 'process_rental_order'));
        add_action('woocommerce_order_status_completed', array($this, 'complete_rental_order'));
        
        // Cancelar reservas cuando se cancela el pedido
        add_action('woocommerce_order_status_cancelled', array($this, 'cancel_rental_order'));
        add_action('woocommerce_order_status_refunded', array($this, 'cancel_rental_order'));
        add_action('woocommerce_order_status_failed', array($this, 'cancel_rental_order'));
        
        // Añadir meta box en página de pedido
        add_action('add_meta_boxes', array($this, 'add_rental_meta_box'));
        
        // Añadir columnas en listado de pedidos
        add_filter('manage_edit-shop_order_columns', array($this, 'add_rental_columns'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_rental_columns'), 10, 2);
        
        // Estados personalizados
        add_action('init', array($this, 'register_rental_order_statuses'));
        add_filter('wc_order_statuses', array($this, 'add_rental_order_statuses'));
        
        // Email notifications
        add_action('woocommerce_order_status_rental-active', array($this, 'send_rental_active_email'));
        add_action('woocommerce_order_status_rental-returned', array($this, 'send_rental_returned_email'));
        
        // AJAX handlers
        add_action('wp_ajax_process_rental_return', array($this, 'ajax_process_return'));
        add_action('wp_ajax_update_rental_status', array($this, 'ajax_update_status'));
        
        // Cron job para verificar alquileres vencidos
        add_action('wp', array($this, 'schedule_rental_check'));
        add_action('check_rental_expirations', array($this, 'check_expired_rentals'));
    }
    
    /**
     * Registrar estados personalizados
     */
    public function register_rental_order_statuses() {
        // Estado: Alquiler Activo
        register_post_status('wc-rental-active', array(
            'label' => __('Alquiler Activo', 'wc-rental-system'),
            'public' => true,
            'show_in_admin_status_list' => true,
            'show_in_admin_all_list' => true,
            'exclude_from_search' => false,
            'label_count' => _n_noop('Alquiler Activo <span class="count">(%s)</span>', 
                'Alquileres Activos <span class="count">(%s)</span>', 'wc-rental-system')
        ));
        
        // Estado: Devuelto
        register_post_status('wc-rental-returned', array(
            'label' => __('Devuelto', 'wc-rental-system'),
            'public' => true,
            'show_in_admin_status_list' => true,
            'show_in_admin_all_list' => true,
            'exclude_from_search' => false,
            'label_count' => _n_noop('Devuelto <span class="count">(%s)</span>', 
                'Devueltos <span class="count">(%s)</span>', 'wc-rental-system')
        ));
        
        // Estado: Vencido
        register_post_status('wc-rental-overdue', array(
            'label' => __('Vencido', 'wc-rental-system'),
            'public' => true,
            'show_in_admin_status_list' => true,
            'show_in_admin_all_list' => true,
            'exclude_from_search' => false,
            'label_count' => _n_noop('Vencido <span class="count">(%s)</span>', 
                'Vencidos <span class="count">(%s)</span>', 'wc-rental-system')
        ));
    }
    
    /**
     * Añadir estados al listado de WooCommerce
     */
    public function add_rental_order_statuses($order_statuses) {
        $new_order_statuses = array();
        
        // Añadir después del estado processing
        foreach ($order_statuses as $key => $status) {
            $new_order_statuses[$key] = $status;
            
            if ('wc-processing' === $key) {
                $new_order_statuses['wc-rental-active'] = __('Alquiler Activo', 'wc-rental-system');
                $new_order_statuses['wc-rental-overdue'] = __('Vencido', 'wc-rental-system');
                $new_order_statuses['wc-rental-returned'] = __('Devuelto', 'wc-rental-system');
            }
        }
        
        return $new_order_statuses;
    }
    
    /**
     * Procesar pedido de alquiler
     */
    public function process_rental_order($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_rental_reservations';
        $has_rental_items = false;
        
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $actual_product_id = $variation_id ?: $product_id;
            
            // Verificar si es producto de alquiler
            if (get_post_meta($product_id, '_is_rental', true) !== 'yes') {
                continue;
            }
            
            $has_rental_items = true;
            
            // Obtener datos de alquiler
            $start_date = $item->get_meta('_rental_start_date');
            $end_date = $item->get_meta('_rental_end_date');
            $rental_days = $item->get_meta('_rental_days');
            $rental_price = $item->get_meta('_rental_price');
            $deposit = $item->get_meta('_rental_deposit');
            
            // Crear reserva en la base de datos
            $wpdb->insert(
                $table_name,
                array(
                    'order_id' => $order_id,
                    'order_item_id' => $item_id,
                    'product_id' => $actual_product_id,
                    'customer_id' => $order->get_customer_id(),
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'rental_days' => $rental_days,
                    'rental_price' => $rental_price,
                    'deposit_amount' => $deposit,
                    'status' => 'confirmed',
                    'created_at' => current_time('mysql')
                ),
                array('%d', '%d', '%d', '%d', '%s', '%s', '%d', '%f', '%f', '%s', '%s')
            );
            
            // Añadir nota al pedido
            $order->add_order_note(sprintf(
                __('Reserva de alquiler creada: %s desde %s hasta %s', 'wc-rental-system'),
                get_the_title($actual_product_id),
                date_i18n(get_option('date_format'), strtotime($start_date)),
                date_i18n(get_option('date_format'), strtotime($end_date))
            ));
        }
        
        // Si hay items de alquiler, cambiar estado a alquiler activo
        if ($has_rental_items) {
            $order->update_status('rental-active', __('Alquiler activo', 'wc-rental-system'));
        }
    }
    
    /**
     * Completar alquiler
     */
    public function complete_rental_order($order_id) {
        $this->process_rental_order($order_id);
    }
    
    /**
     * Cancelar reservas de alquiler
     */
    public function cancel_rental_order($order_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_rental_reservations';
        
        // Actualizar estado de las reservas
        $wpdb->update(
            $table_name,
            array('status' => 'cancelled'),
            array('order_id' => $order_id),
            array('%s'),
            array('%d')
        );
        
        $order = wc_get_order($order_id);
        if ($order) {
            $order->add_order_note(__('Reservas de alquiler canceladas', 'wc-rental-system'));
        }
    }
    
    /**
     * Añadir meta box en página de pedido
     */
    public function add_rental_meta_box() {
        add_meta_box(
            'rental_details',
            __('Detalles de Alquiler', 'wc-rental-system'),
            array($this, 'render_rental_meta_box'),
            'shop_order',
            'normal',
            'high'
        );
    }
    
    /**
     * Renderizar meta box
     */
    public function render_rental_meta_box($post) {
        global $wpdb;
        $order_id = $post->ID;
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        $table_name = $wpdb->prefix . 'wc_rental_reservations';
        $reservations = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %d",
            $order_id
        ));
        
        if (empty($reservations)) {
            echo '<p>' . __('No hay alquileres en este pedido.', 'wc-rental-system') . '</p>';
            return;
        }
        ?>
        
        <div class="rental-details-wrapper">
            <style>
                .rental-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 10px;
                }
                .rental-table th,
                .rental-table td {
                    padding: 8px;
                    border: 1px solid #ddd;
                    text-align: left;
                }
                .rental-table th {
                    background-color: #f5f5f5;
                    font-weight: bold;
                }
                .status-badge {
                    display: inline-block;
                    padding: 3px 8px;
                    border-radius: 3px;
                    font-size: 12px;
                    font-weight: bold;
                }
                .status-confirmed { background-color: #4CAF50; color: white; }
                .status-active { background-color: #2196F3; color: white; }
                .status-returned { background-color: #9E9E9E; color: white; }
                .status-overdue { background-color: #f44336; color: white; }
                .status-cancelled { background-color: #FF9800; color: white; }
                .rental-actions {
                    margin-top: 10px;
                }
                .rental-actions button {
                    margin-right: 5px;
                }
            </style>
            
            <table class="rental-table">
                <thead>
                    <tr>
                        <th><?php _e('Producto', 'wc-rental-system'); ?></th>
                        <th><?php _e('Fecha Inicio', 'wc-rental-system'); ?></th>
                        <th><?php _e('Fecha Fin', 'wc-rental-system'); ?></th>
                        <th><?php _e('Días', 'wc-rental-system'); ?></th>
                        <th><?php _e('Precio', 'wc-rental-system'); ?></th>
                        <th><?php _e('Garantía', 'wc-rental-system'); ?></th>
                        <th><?php _e('Estado', 'wc-rental-system'); ?></th>
                        <th><?php _e('Acciones', 'wc-rental-system'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reservations as $reservation): ?>
                        <?php 
                        $product = wc_get_product($reservation->product_id);
                        $is_overdue = strtotime($reservation->end_date) < current_time('timestamp') && 
                                     $reservation->status === 'active';
                        ?>
                        <tr data-reservation-id="<?php echo esc_attr($reservation->id); ?>">
                            <td>
                                <?php if ($product): ?>
                                    <a href="<?php echo get_edit_post_link($reservation->product_id); ?>">
                                        <?php echo esc_html($product->get_name()); ?>
                                    </a>
                                <?php else: ?>
                                    <?php _e('Producto eliminado', 'wc-rental-system'); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date_i18n(get_option('date_format'), strtotime($reservation->start_date)); ?></td>
                            <td><?php echo date_i18n(get_option('date_format'), strtotime($reservation->end_date)); ?></td>
                            <td><?php echo esc_html($reservation->rental_days); ?></td>
                            <td><?php echo wc_price($reservation->rental_price); ?></td>
                            <td><?php echo wc_price($reservation->deposit_amount); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo esc_attr($is_overdue ? 'overdue' : $reservation->status); ?>">
                                    <?php 
                                    if ($is_overdue) {
                                        _e('Vencido', 'wc-rental-system');
                                    } else {
                                        echo $this->get_status_label($reservation->status);
                                    }
                                    ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($reservation->status === 'confirmed' || $reservation->status === 'active'): ?>
                                    <button type="button" class="button button-small process-return" 
                                            data-reservation-id="<?php echo esc_attr($reservation->id); ?>">
                                        <?php _e('Procesar Devolución', 'wc-rental-system'); ?>
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($reservation->status === 'returned'): ?>
                                    <button type="button" class="button button-small refund-deposit" 
                                            data-reservation-id="<?php echo esc_attr($reservation->id); ?>"
                                            data-deposit="<?php echo esc_attr($reservation->deposit_amount); ?>">
                                        <?php _e('Devolver Garantía', 'wc-rental-system'); ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="rental-actions">
                <?php if ($order->get_status() === 'rental-active'): ?>
                    <button type="button" class="button button-primary" id="check-all-returns">
                        <?php _e('Marcar Todo como Devuelto', 'wc-rental-system'); ?>
                    </button>
                <?php endif; ?>
                
                <button type="button" class="button" id="export-rental-data">
                    <?php _e('Exportar Datos', 'wc-rental-system'); ?>
                </button>
                
                <button type="button" class="button" id="print-rental-receipt">
                    <?php _e('Imprimir Recibo', 'wc-rental-system'); ?>
                </button>
            </div>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Procesar devolución individual
            $('.process-return').on('click', function() {
                var reservationId = $(this).data('reservation-id');
                var $button = $(this);
                
                if (confirm('<?php _e('¿Confirmar la devolución del producto?', 'wc-rental-system'); ?>')) {
                    $button.prop('disabled', true);
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'process_rental_return',
                            reservation_id: reservationId,
                            nonce: '<?php echo wp_create_nonce('rental_return_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                alert(response.data.message);
                                $button.prop('disabled', false);
                            }
                        }
                    });
                }
            });
            
            // Marcar todos como devueltos
            $('#check-all-returns').on('click', function() {
                if (confirm('<?php _e('¿Marcar todos los productos como devueltos?', 'wc-rental-system'); ?>')) {
                    var orderI = <?php echo $order_id; ?>;
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'process_all_returns',
                            order_id: orderId,
                            nonce: '<?php echo wp_create_nonce('rental_return_nonce'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                alert(response.data.message);
                            }
                        }
                    });
                }
            });
            
            // Devolver garantía
            $('.refund-deposit').on('click', function() {
                var reservationId = $(this).data('reservation-id');
                var depositAmount = $(this).data('deposit');
                
                if (confirm('<?php _e('¿Devolver la garantía al cliente?', 'wc-rental-system'); ?> (' + 
                    '<?php echo get_woocommerce_currency_symbol(); ?>' + depositAmount + ')')) {
                    // Aquí se integraría con el sistema de reembolsos de WooCommerce
                    alert('<?php _e('Función de reembolso en desarrollo', 'wc-rental-system'); ?>');
                }
            });
        });
        </script>
        
        <?php
    }
    
    /**
     * AJAX - Procesar devolución
     */
    public function ajax_process_return() {
        check_ajax_referer('rental_return_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Sin permisos', 'wc-rental-system')));
        }
        
        $reservation_id = intval($_POST['reservation_id']);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_rental_reservations';
        
        // Actualizar estado
        $updated = $wpdb->update(
            $table_name,
            array(
                'status' => 'returned',
                'returned_date' => current_time('mysql')
            ),
            array('id' => $reservation_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($updated !== false) {
            // Obtener información de la reserva
            $reservation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d",
                $reservation_id
            ));
            
            if ($reservation) {
                $order = wc_get_order($reservation->order_id);
                if ($order) {
                    $product = wc_get_product($reservation->product_id);
                    $product_name = $product ? $product->get_name() : __('Producto', 'wc-rental-system');
                    
                    $order->add_order_note(sprintf(
                        __('Producto devuelto: %s (Reserva #%d)', 'wc-rental-system'),
                        $product_name,
                        $reservation_id
                    ));
                    
                    // Verificar si todos los productos han sido devueltos
                    $pending = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM $table_name WHERE order_id = %d AND status != 'returned' AND status != 'cancelled'",
                        $reservation->order_id
                    ));
                    
                    if ($pending == 0) {
                        $order->update_status('rental-returned', __('Todos los productos devueltos', 'wc-rental-system'));
                    }
                }
            }
            
            wp_send_json_success(array('message' => __('Devolución procesada', 'wc-rental-system')));
        } else {
            wp_send_json_error(array('message' => __('Error al procesar devolución', 'wc-rental-system')));
        }
    }
    
    /**
     * Obtener etiqueta de estado
     */
    private function get_status_label($status) {
        $labels = array(
            'pending' => __('Pendiente', 'wc-rental-system'),
            'confirmed' => __('Confirmado', 'wc-rental-system'),
            'active' => __('Activo', 'wc-rental-system'),
            'returned' => __('Devuelto', 'wc-rental-system'),
            'cancelled' => __('Cancelado', 'wc-rental-system'),
            'overdue' => __('Vencido', 'wc-rental-system')
        );
        
        return isset($labels[$status]) ? $labels[$status] : $status;
    }
    
    /**
     * Añadir columnas en listado de pedidos
     */
    public function add_rental_columns($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            
            if ($key === 'order_status') {
                $new_columns['rental_info'] = __('Info Alquiler', 'wc-rental-system');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Renderizar columnas de alquiler
     */
    public function render_rental_columns($column, $post_id) {
        if ($column === 'rental_info') {
            global $wpdb;
            $table_name = $wpdb->prefix . 'wc_rental_reservations';
            
            $rentals = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table_name WHERE order_id = %d",
                $post_id
            ));
            
            if (!empty($rentals)) {
                echo '<span style="color: #2196F3; font-weight: bold;">📅 ';
                printf(
                    _n('%d alquiler', '%d alquileres', count($rentals), 'wc-rental-system'),
                    count($rentals)
                );
                echo '</span>';
                
                // Mostrar fechas del primer alquiler
                $first_rental = $rentals[0];
                echo '<br><small>';
                echo date_i18n('d/m', strtotime($first_rental->start_date));
                echo ' - ';
                echo date_i18n('d/m', strtotime($first_rental->end_date));
                echo '</small>';
            }
        }
    }
    
    /**
     * Programar verificación de alquileres
     */
    public function schedule_rental_check() {
        if (!wp_next_scheduled('check_rental_expirations')) {
            wp_schedule_event(time(), 'daily', 'check_rental_expirations');
        }
    }
    
    /**
     * Verificar alquileres vencidos
     */
    public function check_expired_rentals() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_rental_reservations';
        
        // Obtener alquileres vencidos
        $overdue = $wpdb->get_results(
            "SELECT * FROM $table_name 
             WHERE status IN ('confirmed', 'active') 
             AND end_date < CURDATE()"
        );
        
        foreach ($overdue as $rental) {
            // Actualizar estado
            $wpdb->update(
                $table_name,
                array('status' => 'overdue'),
                array('id' => $rental->id),
                array('%s'),
                array('%d')
            );
            
            // Actualizar pedido
            $order = wc_get_order($rental->order_id);
            if ($order && $order->get_status() === 'rental-active') {
                $order->update_status('rental-overdue', __('Alquiler vencido', 'wc-rental-system'));
                
                // Enviar notificación
                $this->send_overdue_notification($order, $rental);
            }
        }
    }
    
    /**
     * Enviar notificación de vencimiento
     */
    private function send_overdue_notification($order, $rental) {
        $to = $order->get_billing_email();
        $subject = sprintf(
            __('Alquiler Vencido - Pedido #%s', 'wc-rental-system'),
            $order->get_order_number()
        );
        
        $product = wc_get_product($rental->product_id);
        $product_name = $product ? $product->get_name() : __('Producto', 'wc-rental-system');
        
        $message = sprintf(
            __('Hola %s,

El período de alquiler para "%s" ha vencido.

Fecha de devolución: %s
Días de retraso: %d

Por favor, devuelve el producto lo antes posible para evitar cargos adicionales.

Gracias,
%s', 'wc-rental-system'),
            $order->get_billing_first_name(),
            $product_name,
            date_i18n(get_option('date_format'), strtotime($rental->end_date)),
            (current_time('timestamp') - strtotime($rental->end_date)) / DAY_IN_SECONDS,
            get_bloginfo('name')
        );
        
        wp_mail($to, $subject, $message);
    }
}

// Inicializar
add_action('init', function() {
    WC_Rental_Order::get_instance();
});