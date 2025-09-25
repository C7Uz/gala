<?php
/**
 * Clase para manejar la sección de alquileres en Mi Cuenta
 * Archivo: includes/frontend/class-my-account.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Rental_My_Account {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Añadir endpoint
        add_action('init', array($this, 'add_endpoints'));
        add_filter('query_vars', array($this, 'add_query_vars'), 0);
        
        // Añadir item al menú de Mi Cuenta
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_items'));
        
        // Contenido del endpoint
        add_action('woocommerce_account_mis-alquileres_endpoint', array($this, 'mis_alquileres_content'));
        add_action('woocommerce_account_ver-alquiler_endpoint', array($this, 'ver_alquiler_content'));
        
        // Título de la página
        add_filter('woocommerce_endpoint_mis-alquileres_title', array($this, 'mis_alquileres_title'));
        add_filter('woocommerce_endpoint_ver-alquiler_title', array($this, 'ver_alquiler_title'));
        
        // Ajax handlers
        add_action('wp_ajax_cancel_rental', array($this, 'ajax_cancel_rental'));
        add_action('wp_ajax_extend_rental', array($this, 'ajax_extend_rental'));
        add_action('wp_ajax_request_rental_invoice', array($this, 'ajax_request_invoice'));
        
        // Añadir información de alquiler en el detalle del pedido
        add_action('woocommerce_order_details_after_order_table', array($this, 'show_rental_details_in_order'));
        
        // Dashboard de Mi Cuenta - añadir widget de alquileres
        add_action('woocommerce_account_dashboard', array($this, 'dashboard_rental_widget'));
        
        // Estilos específicos para Mi Cuenta
        add_action('wp_enqueue_scripts', array($this, 'enqueue_account_scripts'));
    }
    
    /**
     * Añadir endpoints
     */
    public function add_endpoints() {
        add_rewrite_endpoint('mis-alquileres', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('ver-alquiler', EP_ROOT | EP_PAGES);
    }
    
    /**
     * Añadir query vars
     */
    public function add_query_vars($vars) {
        $vars[] = 'mis-alquileres';
        $vars[] = 'ver-alquiler';
        return $vars;
    }
    
    /**
     * Añadir items al menú de Mi Cuenta
     */
    public function add_menu_items($items) {
        // Insertar después de "Pedidos"
        $new_items = array();
        
        foreach ($items as $key => $value) {
            $new_items[$key] = $value;
            
            if ($key === 'orders') {
                $new_items['mis-alquileres'] = __('Mis Alquileres', 'wc-rental-system');
            }
        }
        
        return $new_items;
    }
    
    /**
     * Título de la página de alquileres
     */
    public function mis_alquileres_title() {
        return __('Mis Alquileres', 'wc-rental-system');
    }
    
    /**
     * Título de la página de ver alquiler
     */
    public function ver_alquiler_title() {
        return __('Detalle del Alquiler', 'wc-rental-system');
    }
    
    /**
     * Contenido de la página Mis Alquileres
     */
    public function mis_alquileres_content() {
        global $wpdb;
        
        $current_user_id = get_current_user_id();
        
        if (!$current_user_id) {
            echo '<p>' . __('Debes iniciar sesión para ver tus alquileres.', 'wc-rental-system') . '</p>';
            return;
        }
        
        // Obtener filtro si existe
        $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';
        
        ?>
        <div class="woocommerce-MyAccount-rentals">
            
            <!-- Tabs de filtro -->
            <div class="rental-tabs">
                <ul class="rental-tab-list">
                    <li class="<?php echo $filter === 'all' ? 'active' : ''; ?>">
                        <a href="?filter=all"><?php _e('Todos', 'wc-rental-system'); ?></a>
                    </li>
                    <li class="<?php echo $filter === 'active' ? 'active' : ''; ?>">
                        <a href="?filter=active"><?php _e('Activos', 'wc-rental-system'); ?></a>
                    </li>
                    <li class="<?php echo $filter === 'upcoming' ? 'active' : ''; ?>">
                        <a href="?filter=upcoming"><?php _e('Próximos', 'wc-rental-system'); ?></a>
                    </li>
                    <li class="<?php echo $filter === 'completed' ? 'active' : ''; ?>">
                        <a href="?filter=completed"><?php _e('Completados', 'wc-rental-system'); ?></a>
                    </li>
                    <li class="<?php echo $filter === 'cancelled' ? 'active' : ''; ?>">
                        <a href="?filter=cancelled"><?php _e('Cancelados', 'wc-rental-system'); ?></a>
                    </li>
                </ul>
            </div>
            
            <!-- Estadísticas rápidas -->
            <?php $this->render_rental_stats($current_user_id); ?>
            
            <!-- Lista de alquileres -->
            <?php $this->render_rentals_list($current_user_id, $filter); ?>
            
        </div>
        
        <style>
            .rental-tabs {
                margin-bottom: 30px;
                border-bottom: 2px solid #ddd;
            }
            
            .rental-tab-list {
                list-style: none;
                margin: 0;
                padding: 0;
                display: flex;
            }
            
            .rental-tab-list li {
                margin-right: 20px;
            }
            
            .rental-tab-list li a {
                display: block;
                padding: 10px 0;
                text-decoration: none;
                color: #666;
                position: relative;
            }
            
            .rental-tab-list li.active a {
                color: #333;
                font-weight: bold;
            }
            
            .rental-tab-list li.active a:after {
                content: '';
                position: absolute;
                bottom: -2px;
                left: 0;
                right: 0;
                height: 2px;
                background: #333;
            }
            
            .rental-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }
            
            .stat-card {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 5px;
                text-align: center;
                border: 1px solid #e9ecef;
            }
            
            .stat-card .stat-number {
                font-size: 32px;
                font-weight: bold;
                color: #333;
                margin-bottom: 5px;
            }
            
            .stat-card .stat-label {
                color: #666;
                font-size: 14px;
            }
            
            .rentals-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }
            
            .rentals-table th {
                background: #f8f9fa;
                padding: 12px;
                text-align: left;
                font-weight: 600;
                border-bottom: 2px solid #dee2e6;
            }
            
            .rentals-table td {
                padding: 12px;
                border-bottom: 1px solid #dee2e6;
            }
            
            .rental-status {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
                text-transform: uppercase;
            }
            
            .status-pending { background: #fff3cd; color: #856404; }
            .status-confirmed { background: #cce5ff; color: #004085; }
            .status-active { background: #d4edda; color: #155724; }
            .status-completed { background: #e2e3e5; color: #383d41; }
            .status-cancelled { background: #f8d7da; color: #721c24; }
            
            .rental-actions {
                display: flex;
                gap: 5px;
            }
            
            .rental-actions .button {
                padding: 4px 10px;
                font-size: 12px;
            }
            
            .rental-empty {
                text-align: center;
                padding: 40px;
                background: #f8f9fa;
                border-radius: 5px;
            }
            
            .rental-empty .dashicons {
                font-size: 48px;
                width: 48px;
                height: 48px;
                color: #ccc;
                margin-bottom: 10px;
            }
            
            @media (max-width: 768px) {
                .rentals-table {
                    font-size: 14px;
                }
                
                .rentals-table th,
                .rentals-table td {
                    padding: 8px;
                }
                
                .rental-actions {
                    flex-direction: column;
                }
                
                .rental-tab-list {
                    overflow-x: auto;
                }
            }
        </style>
        <?php
    }
    
    /**
     * Renderizar estadísticas de alquileres
     */
    private function render_rental_stats($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        $stats = array(
            'active' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE customer_id = %d AND status = 'active'",
                $user_id
            )),
            'upcoming' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE customer_id = %d AND status IN ('pending', 'confirmed') AND start_date > CURDATE()",
                $user_id
            )),
            'total' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE customer_id = %d",
                $user_id
            )),
            'next_return' => $wpdb->get_var($wpdb->prepare(
                "SELECT MIN(end_date) FROM $table WHERE customer_id = %d AND status = 'active' AND end_date >= CURDATE()",
                $user_id
            ))
        );
        
        ?>
        <div class="rental-stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo intval($stats['active']); ?></div>
                <div class="stat-label"><?php _e('Alquileres Activos', 'wc-rental-system'); ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-number"><?php echo intval($stats['upcoming']); ?></div>
                <div class="stat-label"><?php _e('Próximos Alquileres', 'wc-rental-system'); ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-number"><?php echo intval($stats['total']); ?></div>
                <div class="stat-label"><?php _e('Total de Alquileres', 'wc-rental-system'); ?></div>
            </div>
            
            <?php if ($stats['next_return']) : ?>
            <div class="stat-card">
                <div class="stat-number"><?php echo date_i18n('d/m', strtotime($stats['next_return'])); ?></div>
                <div class="stat-label"><?php _e('Próxima Devolución', 'wc-rental-system'); ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Renderizar lista de alquileres
     */
    private function render_rentals_list($user_id, $filter = 'all') {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        // Construir query según filtro
        $where = "WHERE customer_id = %d";
        $params = array($user_id);
        
        switch ($filter) {
            case 'active':
                $where .= " AND status = 'active'";
                break;
            case 'upcoming':
                $where .= " AND status IN ('pending', 'confirmed') AND start_date > CURDATE()";
                break;
            case 'completed':
                $where .= " AND status = 'completed'";
                break;
            case 'cancelled':
                $where .= " AND status = 'cancelled'";
                break;
        }
        
        $query = "SELECT r.*, p.post_title as product_name 
                  FROM $table r
                  LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
                  $where
                  ORDER BY r.created_at DESC";
        
        $rentals = $wpdb->get_results($wpdb->prepare($query, $params));
        
        if (empty($rentals)) {
            $this->render_empty_state($filter);
            return;
        }
        
        ?>
        <table class="rentals-table">
            <thead>
                <tr>
                    <th><?php _e('ID', 'wc-rental-system'); ?></th>
                    <th><?php _e('Producto', 'wc-rental-system'); ?></th>
                    <th><?php _e('Fechas', 'wc-rental-system'); ?></th>
                    <th><?php _e('Estado', 'wc-rental-system'); ?></th>
                    <th><?php _e('Total', 'wc-rental-system'); ?></th>
                    <th><?php _e('Acciones', 'wc-rental-system'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rentals as $rental) : 
                    $this->render_rental_row($rental);
                endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Renderizar fila de alquiler
     */
    private function render_rental_row($rental) {
        $product = wc_get_product($rental->product_id);
        $product_name = $product ? $product->get_name() : $rental->product_name;
        
        // Si es variación, obtener información adicional
        if ($rental->variation_id > 0) {
            $variation = wc_get_product($rental->variation_id);
            if ($variation && $variation->is_type('variation')) {
                $attributes = $variation->get_variation_attributes();
                $attr_string = array();
                foreach ($attributes as $attr_name => $attr_value) {
                    $taxonomy = str_replace('attribute_', '', $attr_name);
                    $term = get_term_by('slug', $attr_value, $taxonomy);
                    $attr_string[] = $term ? $term->name : $attr_value;
                }
                if (!empty($attr_string)) {
                    $product_name .= ' - ' . implode(', ', $attr_string);
                }
            }
        }
        
        // Estado con indicadores especiales
        $status_html = $this->get_status_html($rental);
        
        // Total con depósito
        $total = $rental->rental_price + $rental->deposit_amount;
        
        ?>
        <tr>
            <td>#<?php echo $rental->id; ?></td>
            <td>
                <?php echo esc_html($product_name); ?>
                <?php if ($rental->order_id) : ?>
                    <br><small><?php printf(__('Pedido #%s', 'wc-rental-system'), $rental->order_id); ?></small>
                <?php endif; ?>
            </td>
            <td>
                <?php echo date_i18n('d/m/Y', strtotime($rental->start_date)); ?><br>
                <?php echo date_i18n('d/m/Y', strtotime($rental->end_date)); ?>
                <small>(<?php echo $rental->rental_days; ?> <?php _e('días', 'wc-rental-system'); ?>)</small>
            </td>
            <td><?php echo $status_html; ?></td>
            <td><?php echo wc_price($total); ?></td>
            <td>
                <div class="rental-actions">
                    <?php $this->render_rental_actions($rental); ?>
                </div>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Obtener HTML del estado con indicadores
     */
    private function get_status_html($rental) {
        $status_labels = array(
            'pending'   => __('Pendiente', 'wc-rental-system'),
            'confirmed' => __('Confirmado', 'wc-rental-system'),
            'active'    => __('Activo', 'wc-rental-system'),
            'completed' => __('Completado', 'wc-rental-system'),
            'cancelled' => __('Cancelado', 'wc-rental-system'),
        );
        
        $status = $rental->status;
        $label = isset($status_labels[$status]) ? $status_labels[$status] : ucfirst($status);
        $html = '<span class="rental-status status-' . esc_attr($status) . '">' . esc_html($label) . '</span>';
        
        // Añadir indicadores especiales
        $today = current_time('Y-m-d');
        
        if ($status === 'active') {
            if ($rental->end_date < $today) {
                $html .= '<br><small style="color: red;">⚠ ' . __('Vencido', 'wc-rental-system') . '</small>';
            } elseif ($rental->end_date == $today) {
                $html .= '<br><small style="color: orange;">🔄 ' . __('Devuelve hoy', 'wc-rental-system') . '</small>';
            }
        } elseif (in_array($status, array('pending', 'confirmed'))) {
            if ($rental->start_date == $today) {
                $html .= '<br><small style="color: green;">📅 ' . __('Inicia hoy', 'wc-rental-system') . '</small>';
            }
        }
        
        return $html;
    }
    
    /**
     * Renderizar acciones del alquiler
     */
    private function render_rental_actions($rental) {
        $view_url = wc_get_account_endpoint_url('ver-alquiler') . $rental->id;
        
        echo '<a href="' . esc_url($view_url) . '" class="button">' . __('Ver', 'wc-rental-system') . '</a>';
        
        // Acciones según estado
        switch ($rental->status) {
            case 'pending':
            case 'confirmed':
                if (strtotime($rental->start_date) > time() + (48 * 3600)) {
                    echo '<button class="button cancel-rental" data-rental-id="' . $rental->id . '">' . 
                         __('Cancelar', 'wc-rental-system') . '</button>';
                }
                break;
                
            case 'active':
                // Opción de extender si está disponible
                if ($this->can_extend_rental($rental)) {
                    echo '<button class="button extend-rental" data-rental-id="' . $rental->id . '">' . 
                         __('Extender', 'wc-rental-system') . '</button>';
                }
                break;
                
            case 'completed':
                // Opción de volver a alquilar
                if ($product = wc_get_product($rental->product_id)) {
                    echo '<a href="' . get_permalink($rental->product_id) . '" class="button">' . 
                         __('Alquilar de nuevo', 'wc-rental-system') . '</a>';
                }
                
                // Solicitar factura
                echo '<button class="button request-invoice" data-rental-id="' . $rental->id . '">' . 
                     __('Factura', 'wc-rental-system') . '</button>';
                break;
        }
    }
    
    /**
     * Estado vacío
     */
    private function render_empty_state($filter) {
        $messages = array(
            'all' => __('No tienes alquileres todavía.', 'wc-rental-system'),
            'active' => __('No tienes alquileres activos.', 'wc-rental-system'),
            'upcoming' => __('No tienes alquileres próximos.', 'wc-rental-system'),
            'completed' => __('No tienes alquileres completados.', 'wc-rental-system'),
            'cancelled' => __('No tienes alquileres cancelados.', 'wc-rental-system'),
        );
        
        $message = isset($messages[$filter]) ? $messages[$filter] : $messages['all'];
        
        ?>
        <div class="rental-empty">
            <span class="dashicons dashicons-calendar-alt"></span>
            <p><?php echo esc_html($message); ?></p>
            <a href="<?php echo get_permalink(wc_get_page_id('shop')); ?>" class="button">
                <?php _e('Ver productos en alquiler', 'wc-rental-system'); ?>
            </a>
        </div>
        <?php
    }
    
    /**
     * Contenido de ver alquiler individual
     */
    public function ver_alquiler_content($rental_id = null) {
        if (!$rental_id) {
            $rental_id = get_query_var('ver-alquiler');
        }
        
        if (!$rental_id) {
            echo '<p>' . __('Alquiler no encontrado.', 'wc-rental-system') . '</p>';
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        $rental = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND customer_id = %d",
            $rental_id,
            get_current_user_id()
        ));
        
        if (!$rental) {
            echo '<p>' . __('No tienes permiso para ver este alquiler.', 'wc-rental-system') . '</p>';
            return;
        }
        
        // Obtener información del producto
        $product = wc_get_product($rental->product_id);
        $product_name = $product ? $product->get_name() : __('Producto eliminado', 'wc-rental-system');
        
        // Si es variación, obtener detalles
        if ($rental->variation_id > 0) {
            $variation = wc_get_product($rental->variation_id);
            if ($variation && $variation->is_type('variation')) {
                $product_name = $variation->get_name();
            }
        }
        
        ?>
        <div class="rental-details">
            <div class="rental-header">
                <h2><?php printf(__('Alquiler #%d', 'wc-rental-system'), $rental->id); ?></h2>
                <?php echo $this->get_status_html($rental); ?>
            </div>
            
            <div class="rental-info-grid">
                <!-- Información del producto -->
                <div class="info-section">
                    <h3><?php _e('Producto', 'wc-rental-system'); ?></h3>
                    <div class="info-content">
                        <?php if ($product && $product->get_image_id()) : ?>
                            <div class="product-image">
                                <?php echo $product->get_image('thumbnail'); ?>
                            </div>
                        <?php endif; ?>
                        <div class="product-details">
                            <strong><?php echo esc_html($product_name); ?></strong>
                            <?php if ($product) : ?>
                                <br><a href="<?php echo get_permalink($rental->product_id); ?>">
                                    <?php _e('Ver producto', 'wc-rental-system'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Fechas del alquiler -->
                <div class="info-section">
                    <h3><?php _e('Fechas', 'wc-rental-system'); ?></h3>
                    <div class="info-content">
                        <p>
                            <strong><?php _e('Inicio:', 'wc-rental-system'); ?></strong><br>
                            <?php echo date_i18n('l, d F Y', strtotime($rental->start_date)); ?>
                        </p>
                        <p>
                            <strong><?php _e('Devolución:', 'wc-rental-system'); ?></strong><br>
                            <?php echo date_i18n('l, d F Y', strtotime($rental->end_date)); ?>
                        </p>
                        <p>
                            <strong><?php _e('Duración:', 'wc-rental-system'); ?></strong><br>
                            <?php printf(__('%d días', 'wc-rental-system'), $rental->rental_days); ?>
                        </p>
                        
                        <?php if ($rental->grace_period_days > 0) : ?>
                        <p class="grace-period">
                            <small>
                                <?php printf(
                                    __('Incluye %d día(s) de gracia para limpieza', 'wc-rental-system'),
                                    $rental->grace_period_days
                                ); ?>
                            </small>
                        </p>
                        <?php endif; ?>
                        
                        <?php if ($rental->return_date) : ?>
                        <p>
                            <strong><?php _e('Fecha de devolución real:', 'wc-rental-system'); ?></strong><br>
                            <?php echo date_i18n('l, d F Y', strtotime($rental->return_date)); ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Precios -->
                <div class="info-section">
                    <h3><?php _e('Desglose de Precio', 'wc-rental-system'); ?></h3>
                    <div class="info-content">
                        <table class="price-breakdown">
                            <tr>
                                <td><?php _e('Precio del alquiler:', 'wc-rental-system'); ?></td>
                                <td><?php echo wc_price($rental->rental_price); ?></td>
                            </tr>
                            <?php if ($rental->deposit_amount > 0) : ?>
                            <tr>
                                <td><?php _e('Garantía:', 'wc-rental-system'); ?></td>
                                <td><?php echo wc_price($rental->deposit_amount); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr class="total-row">
                                <td><strong><?php _e('Total:', 'wc-rental-system'); ?></strong></td>
                                <td><strong><?php echo wc_price($rental->rental_price + $rental->deposit_amount); ?></strong></td>
                            </tr>
                        </table>
                        
                        <?php if ($rental->deposit_amount > 0) : ?>
                        <p class="deposit-info">
                            <small>
                                <?php 
                                if ($rental->status === 'completed') {
                                    _e('La garantía ha sido devuelta.', 'wc-rental-system');
                                } else {
                                    _e('La garantía será devuelta al completar el alquiler.', 'wc-rental-system');
                                }
                                ?>
                            </small>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Información del pedido -->
                <?php if ($rental->order_id) : ?>
                <div class="info-section">
                    <h3><?php _e('Información del Pedido', 'wc-rental-system'); ?></h3>
                    <div class="info-content">
                        <?php
                        $order = wc_get_order($rental->order_id);
                        if ($order) :
                        ?>
                            <p>
                                <strong><?php _e('Pedido:', 'wc-rental-system'); ?></strong> 
                                #<?php echo $order->get_order_number(); ?>
                            </p>
                            <p>
                                <strong><?php _e('Fecha:', 'wc-rental-system'); ?></strong> 
                                <?php echo $order->get_date_created()->date_i18n(get_option('date_format')); ?>
                            </p>
                            <p>
                                <strong><?php _e('Estado:', 'wc-rental-system'); ?></strong> 
                                <?php echo wc_get_order_status_name($order->get_status()); ?>
                            </p>
                            <a href="<?php echo esc_url($order->get_view_order_url()); ?>" class="button">
                                <?php _e('Ver pedido completo', 'wc-rental-system'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Notas -->
                <?php if (!empty($rental->notes)) : ?>
                <div class="info-section">
                    <h3><?php _e('Notas', 'wc-rental-system'); ?></h3>
                    <div class="info-content">
                        <p><?php echo esc_html($rental->notes); ?></p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Acciones disponibles -->
            <div class="rental-actions-section">
                <h3><?php _e('Acciones', 'wc-rental-system'); ?></h3>
                <div class="action-buttons">
                    <?php $this->render_detail_actions($rental); ?>
                    <a href="<?php echo wc_get_account_endpoint_url('mis-alquileres'); ?>" class="button">
                        <?php _e('Volver a mis alquileres', 'wc-rental-system'); ?>
                    </a>
                </div>
            </div>
            
            <!-- Timeline del alquiler -->
            <div class="rental-timeline">
                <h3><?php _e('Historial', 'wc-rental-system'); ?></h3>
                <?php $this->render_rental_timeline($rental); ?>
            </div>
        </div>
        
        <style>
            .rental-details {
                max-width: 1000px;
            }
            
            .rental-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 30px;
                padding-bottom: 15px;
                border-bottom: 2px solid #ddd;
            }
            
            .rental-info-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 30px;
                margin-bottom: 30px;
            }
            
            .info-section {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 5px;
            }
            
            .info-section h3 {
                margin-top: 0;
                margin-bottom: 15px;
                font-size: 16px;
                color: #333;
            }
            
            .info-content {
                color: #666;
            }
            
            .product-image {
                float: left;
                margin-right: 15px;
            }
            
            .product-image img {
                width: 60px;
                height: 60px;
                object-fit: cover;
                border-radius: 3px;
            }
            
            .price-breakdown {
                width: 100%;
            }
            
            .price-breakdown td {
                padding: 5px 0;
            }
            
            .price-breakdown td:last-child {
                text-align: right;
            }
            
            .price-breakdown .total-row td {
                padding-top: 10px;
                border-top: 1px solid #ddd;
            }
            
            .deposit-info,
            .grace-period {
                margin-top: 10px;
                padding: 10px;
                background: #fff3cd;
                border-radius: 3px;
            }
            
            .rental-actions-section {
                margin: 30px 0;
                padding: 20px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 5px;
            }
            
            .rental-actions-section h3 {
                margin-top: 0;
            }
            
            .action-buttons {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }
            
            .rental-timeline {
                margin-top: 30px;
            }
            
            .timeline-list {
                position: relative;
                padding-left: 30px;
            }
            
            .timeline-list:before {
                content: '';
                position: absolute;
                left: 10px;
                top: 5px;
                bottom: 5px;
                width: 2px;
                background: #ddd;
            }
            
            .timeline-item {
                position: relative;
                padding-bottom: 20px;
            }
            
            .timeline-item:before {
                content: '';
                position: absolute;
                left: -24px;
                top: 5px;
                width: 12px;
                height: 12px;
                border-radius: 50%;
                background: #fff;
                border: 2px solid #0073aa;
            }
            
            .timeline-date {
                font-size: 12px;
                color: #999;
                margin-bottom: 5px;
            }
            
            .timeline-content {
                background: #f8f9fa;
                padding: 10px;
                border-radius: 3px;
            }
            
            @media (max-width: 768px) {
                .rental-info-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php
    }
    
    /**
     * Renderizar acciones de detalle
     */
    private function render_detail_actions($rental) {
        // Cancelar
        if (in_array($rental->status, array('pending', 'confirmed'))) {
            if (strtotime($rental->start_date) > time() + (48 * 3600)) {
                echo '<button class="button button-cancel cancel-rental" data-rental-id="' . $rental->id . '">' . 
                     __('Cancelar alquiler', 'wc-rental-system') . '</button>';
            }
        }
        
        // Extender
        if ($rental->status === 'active' && $this->can_extend_rental($rental)) {
            echo '<button class="button button-primary extend-rental" data-rental-id="' . $rental->id . '">' . 
                 __('Extender alquiler', 'wc-rental-system') . '</button>';
        }
        
        // Factura
        if ($rental->status === 'completed') {
            echo '<button class="button request-invoice" data-rental-id="' . $rental->id . '">' . 
                 __('Descargar factura', 'wc-rental-system') . '</button>';
        }
        
        // Contactar
        echo '<a href="' . get_permalink(wc_get_page_id('contact')) . '?rental_id=' . $rental->id . '" class="button">' . 
             __('Contactar soporte', 'wc-rental-system') . '</a>';
    }
    
    /**
     * Renderizar timeline del alquiler
     */
    private function render_rental_timeline($rental) {
        $timeline = array();
        
        // Creación
        $timeline[] = array(
            'date' => $rental->created_at,
            'title' => __('Alquiler creado', 'wc-rental-system'),
            'description' => __('Se creó la reserva de alquiler', 'wc-rental-system')
        );
        
        // Estados
        if (in_array($rental->status, array('confirmed', 'active', 'completed'))) {
            $timeline[] = array(
                'date' => $rental->created_at, // Aquí deberías tener un log real
                'title' => __('Alquiler confirmado', 'wc-rental-system'),
                'description' => __('El alquiler fue confirmado y pagado', 'wc-rental-system')
            );
        }
        
        if (in_array($rental->status, array('active', 'completed'))) {
            $timeline[] = array(
                'date' => $rental->start_date,
                'title' => __('Alquiler iniciado', 'wc-rental-system'),
                'description' => __('El producto fue recogido/enviado', 'wc-rental-system')
            );
        }
        
        if ($rental->status === 'completed') {
            $timeline[] = array(
                'date' => $rental->return_date ?: $rental->end_date,
                'title' => __('Alquiler completado', 'wc-rental-system'),
                'description' => __('El producto fue devuelto correctamente', 'wc-rental-system')
            );
        }
        
        if ($rental->status === 'cancelled') {
            $timeline[] = array(
                'date' => $rental->updated_at,
                'title' => __('Alquiler cancelado', 'wc-rental-system'),
                'description' => __('El alquiler fue cancelado', 'wc-rental-system')
            );
        }
        
        ?>
        <div class="timeline-list">
            <?php foreach ($timeline as $event) : ?>
            <div class="timeline-item">
                <div class="timeline-date">
                    <?php echo date_i18n('d/m/Y H:i', strtotime($event['date'])); ?>
                </div>
                <div class="timeline-content">
                    <strong><?php echo esc_html($event['title']); ?></strong><br>
                    <?php echo esc_html($event['description']); ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }
    
    /**
     * Widget en el dashboard de Mi Cuenta
     */
    public function dashboard_rental_widget() {
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        // Obtener alquileres activos y próximos
        $active_rentals = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, p.post_title as product_name
             FROM $table r
             LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
             WHERE r.customer_id = %d 
             AND (r.status = 'active' OR (r.status IN ('pending', 'confirmed') AND r.start_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)))
             ORDER BY r.start_date ASC
             LIMIT 3",
            $user_id
        ));
        
        if (empty($active_rentals)) {
            return;
        }
        
        ?>
        <div class="woocommerce-message woocommerce-message--info">
            <h3><?php _e('Tus Alquileres', 'wc-rental-system'); ?></h3>
            
            <?php foreach ($active_rentals as $rental) : ?>
            <div style="padding: 10px 0; border-bottom: 1px solid #eee;">
                <strong><?php echo esc_html($rental->product_name); ?></strong><br>
                
                <?php if ($rental->status === 'active') : ?>
                    <?php 
                    $days_left = (strtotime($rental->end_date) - strtotime(current_time('Y-m-d'))) / 86400;
                    if ($days_left == 0) {
                        echo '<span style="color: orange;">⚠ ' . __('Devolver hoy', 'wc-rental-system') . '</span>';
                    } elseif ($days_left < 0) {
                        echo '<span style="color: red;">⚠ ' . __('Vencido', 'wc-rental-system') . '</span>';
                    } else {
                        printf(__('Devolver en %d días', 'wc-rental-system'), $days_left);
                    }
                    ?>
                <?php else : ?>
                    <?php 
                    $days_until = (strtotime($rental->start_date) - strtotime(current_time('Y-m-d'))) / 86400;
                    if ($days_until == 0) {
                        echo '<span style="color: green;">📅 ' . __('Recoger hoy', 'wc-rental-system') . '</span>';
                    } else {
                        printf(__('Recoger en %d días', 'wc-rental-system'), $days_until);
                    }
                    ?>
                <?php endif; ?>
                
                <br>
                <small>
                    <?php echo date_i18n('d/m', strtotime($rental->start_date)); ?> - 
                    <?php echo date_i18n('d/m', strtotime($rental->end_date)); ?>
                </small>
            </div>
            <?php endforeach; ?>
            
            <p style="margin-top: 15px;">
                <a href="<?php echo wc_get_account_endpoint_url('mis-alquileres'); ?>" class="button">
                    <?php _e('Ver todos los alquileres', 'wc-rental-system'); ?>
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Mostrar detalles de alquiler en el pedido
     */
    public function show_rental_details_in_order($order) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        $rentals = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE order_id = %d",
            $order->get_id()
        ));
        
        if (empty($rentals)) {
            return;
        }
        
        ?>
        <h2><?php _e('Detalles del Alquiler', 'wc-rental-system'); ?></h2>
        <table class="woocommerce-table woocommerce-table--rental-details shop_table">
            <thead>
                <tr>
                    <th><?php _e('Producto', 'wc-rental-system'); ?></th>
                    <th><?php _e('Fecha Inicio', 'wc-rental-system'); ?></th>
                    <th><?php _e('Fecha Fin', 'wc-rental-system'); ?></th>
                    <th><?php _e('Estado', 'wc-rental-system'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rentals as $rental) : ?>
                <tr>
                    <td>
                        <?php 
                        $product = wc_get_product($rental->product_id);
                        echo $product ? esc_html($product->get_name()) : __('Producto eliminado', 'wc-rental-system');
                        ?>
                    </td>
                    <td><?php echo date_i18n(get_option('date_format'), strtotime($rental->start_date)); ?></td>
                    <td><?php echo date_i18n(get_option('date_format'), strtotime($rental->end_date)); ?></td>
                    <td><?php echo $this->get_status_html($rental); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Verificar si se puede extender un alquiler
     */
    private function can_extend_rental($rental) {
        // Solo alquileres activos
        if ($rental->status !== 'active') {
            return false;
        }
        
        // Solo si faltan al menos 2 días para terminar
        $days_left = (strtotime($rental->end_date) - strtotime(current_time('Y-m-d'))) / 86400;
        if ($days_left < 2) {
            return false;
        }
        
        // Verificar disponibilidad para extensión
        // Aquí deberías verificar si el producto está disponible para las fechas siguientes
        
        return true;
    }
    
    /**
     * Ajax: Cancelar alquiler
     */
    public function ajax_cancel_rental() {
        // Verificar nonce
        check_ajax_referer('rental_nonce', 'nonce');
        
        $rental_id = intval($_POST['rental_id']);
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            wp_send_json_error(array('message' => __('Debes iniciar sesión', 'wc-rental-system')));
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        // Verificar que el alquiler pertenece al usuario
        $rental = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND customer_id = %d",
            $rental_id,
            $user_id
        ));
        
        if (!$rental) {
            wp_send_json_error(array('message' => __('Alquiler no encontrado', 'wc-rental-system')));
        }
        
        // Verificar que se puede cancelar (48h antes)
        if (strtotime($rental->start_date) <= time() + (48 * 3600)) {
            wp_send_json_error(array('message' => __('No se puede cancelar con menos de 48 horas de antelación', 'wc-rental-system')));
        }
        
        // Cancelar
        $wpdb->update(
            $table,
            array('status' => 'cancelled'),
            array('id' => $rental_id),
            array('%s'),
            array('%d')
        );
        
        // Liberar disponibilidad
        $availability_table = $wpdb->prefix . 'wc_rental_availability';
        $wpdb->delete(
            $availability_table,
            array('rental_id' => $rental_id),
            array('%d')
        );
        
        wp_send_json_success(array('message' => __('Alquiler cancelado correctamente', 'wc-rental-system')));
    }
    
    /**
     * Ajax: Extender alquiler
     */
    public function ajax_extend_rental() {
        // Verificar nonce
        check_ajax_referer('rental_nonce', 'nonce');
        
        $rental_id = intval($_POST['rental_id']);
        $extra_days = intval($_POST['extra_days']);
        
        // Aquí implementarías la lógica de extensión
        // Por ahora solo devolvemos un mensaje
        
        wp_send_json_success(array(
            'message' => __('Función de extensión en desarrollo', 'wc-rental-system'),
            'redirect' => wc_get_account_endpoint_url('mis-alquileres')
        ));
    }
    
    /**
     * Ajax: Solicitar factura
     */
    public function ajax_request_invoice() {
        // Verificar nonce
        check_ajax_referer('rental_nonce', 'nonce');
        
        $rental_id = intval($_POST['rental_id']);
        
        // Aquí implementarías la generación de factura
        // Por ahora solo devolvemos un mensaje
        
        wp_send_json_success(array(
            'message' => __('La factura será enviada a tu email', 'wc-rental-system')
        ));
    }
    
    /**
     * Cargar scripts para Mi Cuenta
     */
    public function enqueue_account_scripts() {
        if (!is_account_page()) {
            return;
        }
        
        wp_add_inline_script('jquery', $this->get_account_inline_script(), 'after');
    }
    
    /**
     * Script inline para Mi Cuenta
     */
    private function get_account_inline_script() {
        ob_start();
        ?>
        jQuery(document).ready(function($) {
            // Cancelar alquiler
            $('.cancel-rental').on('click', function(e) {
                e.preventDefault();
                
                if (!confirm('<?php _e('¿Estás seguro de que quieres cancelar este alquiler?', 'wc-rental-system'); ?>')) {
                    return;
                }
                
                var button = $(this);
                var rentalId = button.data('rental-id');
                
                button.prop('disabled', true).text('<?php _e('Cancelando...', 'wc-rental-system'); ?>');
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'cancel_rental',
                        rental_id: rentalId,
                        nonce: '<?php echo wp_create_nonce('rental_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert(response.data.message);
                            button.prop('disabled', false).text('<?php _e('Cancelar', 'wc-rental-system'); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php _e('Error al procesar la solicitud', 'wc-rental-system'); ?>');
                        button.prop('disabled', false).text('<?php _e('Cancelar', 'wc-rental-system'); ?>');
                    }
                });
            });
            
            // Extender alquiler
            $('.extend-rental').on('click', function(e) {
                e.preventDefault();
                
                var rentalId = $(this).data('rental-id');
                var days = prompt('<?php _e('¿Cuántos días adicionales deseas alquilar?', 'wc-rental-system'); ?>', '7');
                
                if (!days || isNaN(days) || days < 1) {
                    return;
                }
                
                var button = $(this);
                button.prop('disabled', true).text('<?php _e('Procesando...', 'wc-rental-system'); ?>');
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'extend_rental',
                        rental_id: rentalId,
                        extra_days: days,
                        nonce: '<?php echo wp_create_nonce('rental_nonce'); ?>'
                    },
                    success: function(response) {
                        alert(response.data.message);
                        if (response.data.redirect) {
                            window.location.href = response.data.redirect;
                        }
                    },
                    error: function() {
                        alert('<?php _e('Error al procesar la solicitud', 'wc-rental-system'); ?>');
                        button.prop('disabled', false).text('<?php _e('Extender', 'wc-rental-system'); ?>');
                    }
                });
            });
            
            // Solicitar factura
            $('.request-invoice').on('click', function(e) {
                e.preventDefault();
                
                var button = $(this);
                var rentalId = button.data('rental-id');
                
                button.prop('disabled', true).text('<?php _e('Solicitando...', 'wc-rental-system'); ?>');
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'request_rental_invoice',
                        rental_id: rentalId,
                        nonce: '<?php echo wp_create_nonce('rental_nonce'); ?>'
                    },
                    success: function(response) {
                        alert(response.data.message);
                        button.prop('disabled', false).text('<?php _e('Factura', 'wc-rental-system'); ?>');
                    },
                    error: function() {
                        alert('<?php _e('Error al procesar la solicitud', 'wc-rental-system'); ?>');
                        button.prop('disabled', false).text('<?php _e('Factura', 'wc-rental-system'); ?>');
                    }
                });
            });
        });
        <?php
        return ob_get_clean();
    }
}