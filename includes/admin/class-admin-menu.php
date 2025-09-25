<?php
/**
 * Clase para manejar el menú de administración del sistema de alquileres
 * Archivo: includes/admin/class-admin-menu.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Rental_Admin_Menu {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_filter('set-screen-option', array($this, 'set_screen_option'), 10, 3);
        add_action('admin_notices', array($this, 'admin_notices'));
    }
    
    /**
     * Añadir menú de administración
     */
    public function add_admin_menu() {
        // Menú principal
        add_menu_page(
            __('Alquileres', 'wc-rental-system'),
            __('Alquileres', 'wc-rental-system'),
            'manage_woocommerce',
            'wc-rentals',
            array($this, 'render_dashboard_page'),
            'dashicons-calendar-alt',
            56 // Posición después de WooCommerce
        );
        
        // Dashboard (reemplaza el primer submenú)
        add_submenu_page(
            'wc-rentals',
            __('Dashboard', 'wc-rental-system'),
            __('Dashboard', 'wc-rental-system'),
            'manage_woocommerce',
            'wc-rentals',
            array($this, 'render_dashboard_page')
        );
        
        // Todos los alquileres
        $rentals_page = add_submenu_page(
            'wc-rentals',
            __('Todos los Alquileres', 'wc-rental-system'),
            __('Todos los Alquileres', 'wc-rental-system'),
            'manage_woocommerce',
            'wc-rentals-list',
            array($this, 'render_rentals_list_page')
        );
        
        // Añadir opciones de pantalla para la lista
        add_action("load-$rentals_page", array($this, 'add_screen_options'));
        
        // Nuevo alquiler
        add_submenu_page(
            'wc-rentals',
            __('Nuevo Alquiler', 'wc-rental-system'),
            __('Nuevo Alquiler', 'wc-rental-system'),
            'manage_woocommerce',
            'wc-rentals-new',
            array($this, 'render_new_rental_page')
        );
        
        // Calendario
        add_submenu_page(
            'wc-rentals',
            __('Calendario', 'wc-rental-system'),
            __('Calendario', 'wc-rental-system'),
            'manage_woocommerce',
            'wc-rentals-calendar',
            array($this, 'render_calendar_page')
        );
        
        // Disponibilidad
        add_submenu_page(
            'wc-rentals',
            __('Disponibilidad', 'wc-rental-system'),
            __('Disponibilidad', 'wc-rental-system'),
            'manage_woocommerce',
            'wc-rentals-availability',
            array($this, 'render_availability_page')
        );
        
        // Reportes
        add_submenu_page(
            'wc-rentals',
            __('Reportes', 'wc-rental-system'),
            __('Reportes', 'wc-rental-system'),
            'manage_woocommerce',
            'wc-rentals-reports',
            array($this, 'render_reports_page')
        );
        
        // Configuración
        add_submenu_page(
            'wc-rentals',
            __('Configuración', 'wc-rental-system'),
            __('Configuración', 'wc-rental-system'),
            'manage_woocommerce',
            'wc-rentals-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Renderizar página del dashboard
     */
    public function render_dashboard_page() {
        global $wpdb;
        
        // Obtener estadísticas
        $stats = $this->get_dashboard_stats();
        ?>
        <div class="wrap wc-rentals-dashboard">
            <h1><?php _e('Dashboard de Alquileres', 'wc-rental-system'); ?></h1>
            
            <!-- Estadísticas rápidas -->
            <div class="rental-stats-grid">
                <div class="stat-box">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-calendar"></span>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $stats['active_rentals']; ?></h3>
                        <p><?php _e('Alquileres Activos', 'wc-rental-system'); ?></p>
                    </div>
                </div>
                
                <div class="stat-box">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-clock"></span>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $stats['pending_rentals']; ?></h3>
                        <p><?php _e('Pendientes', 'wc-rental-system'); ?></p>
                    </div>
                </div>
                
                <div class="stat-box">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-calendar-alt"></span>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $stats['today_pickups']; ?></h3>
                        <p><?php _e('Recogidas Hoy', 'wc-rental-system'); ?></p>
                    </div>
                </div>
                
                <div class="stat-box">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-backup"></span>
                    </div>
                    <div class="stat-content">
                        <h3><?php echo $stats['today_returns']; ?></h3>
                        <p><?php _e('Devoluciones Hoy', 'wc-rental-system'); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="rental-dashboard-content">
                <div class="rental-column">
                    <!-- Próximos alquileres -->
                    <div class="rental-widget">
                        <h2><?php _e('Próximos Alquileres', 'wc-rental-system'); ?></h2>
                        <?php $this->render_upcoming_rentals(); ?>
                    </div>
                    
                    <!-- Alquileres que vencen pronto -->
                    <div class="rental-widget">
                        <h2><?php _e('Próximas Devoluciones', 'wc-rental-system'); ?></h2>
                        <?php $this->render_upcoming_returns(); ?>
                    </div>
                </div>
                
                <div class="rental-column">
                    <!-- Calendario mini -->
                    <div class="rental-widget">
                        <h2><?php _e('Calendario', 'wc-rental-system'); ?></h2>
                        <div id="rental-mini-calendar"></div>
                    </div>
                    
                    <!-- Productos más alquilados -->
                    <div class="rental-widget">
                        <h2><?php _e('Productos Más Alquilados', 'wc-rental-system'); ?></h2>
                        <?php $this->render_top_products(); ?>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
            .rental-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }
            
            .stat-box {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 5px;
                padding: 20px;
                display: flex;
                align-items: center;
            }
            
            .stat-icon {
                font-size: 40px;
                margin-right: 15px;
                color: #0073aa;
            }
            
            .stat-content h3 {
                margin: 0;
                font-size: 28px;
                color: #333;
            }
            
            .stat-content p {
                margin: 5px 0 0;
                color: #666;
            }
            
            .rental-dashboard-content {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-top: 30px;
            }
            
            .rental-widget {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 5px;
                padding: 20px;
                margin-bottom: 20px;
            }
            
            .rental-widget h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            
            @media (max-width: 768px) {
                .rental-dashboard-content {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php
    }
    
    /**
     * Renderizar página de lista de alquileres
     */
    public function render_rentals_list_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e('Todos los Alquileres', 'wc-rental-system'); ?></h1>
            <a href="?page=wc-rentals-new" class="page-title-action"><?php _e('Añadir Nuevo', 'wc-rental-system'); ?></a>
            <hr class="wp-header-end">
            
            <?php
            // Incluir la clase de lista si existe
            if (file_exists(WC_RENTAL_PATH . 'includes/admin/class-rentals-list.php')) {
                require_once WC_RENTAL_PATH . 'includes/admin/class-rentals-list.php';
                
                $rentals_list = new WC_Rentals_List_Table();
                $rentals_list->prepare_items();
                
                // Formulario de búsqueda
                ?>
                <form method="get">
                    <input type="hidden" name="page" value="wc-rentals-list">
                    <?php
                    $rentals_list->search_box(__('Buscar Alquileres', 'wc-rental-system'), 'rental_search');
                    $rentals_list->display();
                    ?>
                </form>
                <?php
            } else {
                // Tabla temporal si la clase no existe aún
                $this->render_temporary_rentals_table();
            }
            ?>
        </div>
        <?php
    }
    
    /**
     * Renderizar página de nuevo alquiler
     */
    public function render_new_rental_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Nuevo Alquiler', 'wc-rental-system'); ?></h1>
            
            <form method="post" action="" id="new-rental-form">
                <?php wp_nonce_field('create_rental', 'rental_nonce'); ?>
                
                <div class="rental-form-grid">
                    <div class="rental-form-column">
                        <h2><?php _e('Información del Cliente', 'wc-rental-system'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th><label for="customer_id"><?php _e('Cliente', 'wc-rental-system'); ?></label></th>
                                <td>
                                    <select name="customer_id" id="customer_id" class="wc-customer-search" style="width: 100%;" required>
                                        <option value=""><?php _e('Buscar cliente...', 'wc-rental-system'); ?></option>
                                    </select>
                                    <p class="description"><?php _e('Buscar por nombre o email', 'wc-rental-system'); ?></p>
                                </td>
                            </tr>
                        </table>
                        
                        <h2><?php _e('Producto y Fechas', 'wc-rental-system'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th><label for="product_id"><?php _e('Producto', 'wc-rental-system'); ?></label></th>
                                <td>
                                    <select name="product_id" id="product_id" class="wc-product-search" style="width: 100%;" required>
                                        <option value=""><?php _e('Buscar producto...', 'wc-rental-system'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr id="variation_row" style="display: none;">
                                <th><label for="variation_id"><?php _e('Variación', 'wc-rental-system'); ?></label></th>
                                <td>
                                    <select name="variation_id" id="variation_id" style="width: 100%;">
                                        <option value=""><?php _e('Seleccionar variación...', 'wc-rental-system'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr>
                                <th><label for="start_date"><?php _e('Fecha de Inicio', 'wc-rental-system'); ?></label></th>
                                <td>
                                    <input type="text" name="start_date" id="start_date" class="rental-datepicker" required>
                                </td>
                            </tr>
                            
                            <tr>
                                <th><label for="end_date"><?php _e('Fecha de Fin', 'wc-rental-system'); ?></label></th>
                                <td>
                                    <input type="text" name="end_date" id="end_date" class="rental-datepicker" required>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="rental-form-column">
                        <h2><?php _e('Resumen del Alquiler', 'wc-rental-system'); ?></h2>
                        
                        <div id="rental-summary" class="rental-summary-box">
                            <p><?php _e('Selecciona un producto y fechas para ver el resumen', 'wc-rental-system'); ?></p>
                        </div>
                        
                        <h2><?php _e('Notas', 'wc-rental-system'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <td>
                                    <textarea name="notes" id="notes" rows="5" style="width: 100%;" 
                                        placeholder="<?php _e('Notas adicionales sobre el alquiler...', 'wc-rental-system'); ?>"></textarea>
                                </td>
                            </tr>
                        </table>
                        
                        <h2><?php _e('Estado del Alquiler', 'wc-rental-system'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th><label for="status"><?php _e('Estado', 'wc-rental-system'); ?></label></th>
                                <td>
                                    <select name="status" id="status">
                                        <option value="pending"><?php _e('Pendiente', 'wc-rental-system'); ?></option>
                                        <option value="confirmed"><?php _e('Confirmado', 'wc-rental-system'); ?></option>
                                        <option value="active"><?php _e('Activo', 'wc-rental-system'); ?></option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <p class="submit">
                    <button type="submit" name="create_rental" class="button button-primary">
                        <?php _e('Crear Alquiler', 'wc-rental-system'); ?>
                    </button>
                    <a href="?page=wc-rentals-list" class="button"><?php _e('Cancelar', 'wc-rental-system'); ?></a>
                </p>
            </form>
        </div>
        
        <style>
            .rental-form-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 30px;
                margin-top: 20px;
            }
            
            .rental-summary-box {
                background: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 5px;
                padding: 15px;
                margin-bottom: 20px;
            }
            
            .rental-summary-box.has-data {
                background: #fff;
                border-color: #0073aa;
            }
            
            .rental-summary-box .price-row {
                display: flex;
                justify-content: space-between;
                padding: 5px 0;
            }
            
            .rental-summary-box .total-row {
                border-top: 2px solid #ddd;
                margin-top: 10px;
                padding-top: 10px;
                font-weight: bold;
                font-size: 1.1em;
            }
            
            @media (max-width: 768px) {
                .rental-form-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php
    }
    
    /**
     * Renderizar página de calendario
     */
    public function render_calendar_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Calendario de Alquileres', 'wc-rental-system'); ?></h1>
            
            <div class="rental-calendar-toolbar">
                <div class="calendar-filters">
                    <select id="calendar-product-filter">
                        <option value=""><?php _e('Todos los productos', 'wc-rental-system'); ?></option>
                        <?php
                        // Obtener productos en alquiler
                        $args = array(
                            'post_type' => 'product',
                            'posts_per_page' => -1,
                            'meta_query' => array(
                                array(
                                    'key' => '_is_rentable',
                                    'value' => 'yes',
                                )
                            )
                        );
                        $products = get_posts($args);
                        
                        foreach ($products as $product) {
                            echo '<option value="' . $product->ID . '">' . $product->post_title . '</option>';
                        }
                        ?>
                    </select>
                    
                    <select id="calendar-status-filter">
                        <option value=""><?php _e('Todos los estados', 'wc-rental-system'); ?></option>
                        <option value="pending"><?php _e('Pendiente', 'wc-rental-system'); ?></option>
                        <option value="confirmed"><?php _e('Confirmado', 'wc-rental-system'); ?></option>
                        <option value="active"><?php _e('Activo', 'wc-rental-system'); ?></option>
                        <option value="completed"><?php _e('Completado', 'wc-rental-system'); ?></option>
                    </select>
                    
                    <button class="button" id="calendar-today-btn"><?php _e('Hoy', 'wc-rental-system'); ?></button>
                </div>
                
                <div class="calendar-views">
                    <button class="button" data-view="month"><?php _e('Mes', 'wc-rental-system'); ?></button>
                    <button class="button" data-view="week"><?php _e('Semana', 'wc-rental-system'); ?></button>
                    <button class="button" data-view="day"><?php _e('Día', 'wc-rental-system'); ?></button>
                </div>
            </div>
            
            <div id="rental-calendar"></div>
            
            <div class="calendar-legend">
                <h3><?php _e('Leyenda', 'wc-rental-system'); ?></h3>
                <div class="legend-items">
                    <span class="legend-item">
                        <span class="color-box" style="background: #f0ad4e;"></span>
                        <?php _e('Pendiente', 'wc-rental-system'); ?>
                    </span>
                    <span class="legend-item">
                        <span class="color-box" style="background: #5bc0de;"></span>
                        <?php _e('Confirmado', 'wc-rental-system'); ?>
                    </span>
                    <span class="legend-item">
                        <span class="color-box" style="background: #5cb85c;"></span>
                        <?php _e('Activo', 'wc-rental-system'); ?>
                    </span>
                    <span class="legend-item">
                        <span class="color-box" style="background: #999;"></span>
                        <?php _e('Completado', 'wc-rental-system'); ?>
                    </span>
                </div>
            </div>
        </div>
        
        <style>
            .rental-calendar-toolbar {
                display: flex;
                justify-content: space-between;
                margin: 20px 0;
                padding: 15px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 5px;
            }
            
            .calendar-filters select {
                margin-right: 10px;
            }
            
            #rental-calendar {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 5px;
                padding: 20px;
                min-height: 500px;
            }
            
            .calendar-legend {
                margin-top: 20px;
                padding: 15px;
                background: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 5px;
            }
            
            .legend-items {
                display: flex;
                gap: 20px;
            }
            
            .legend-item {
                display: flex;
                align-items: center;
                gap: 5px;
            }
            
            .color-box {
                width: 20px;
                height: 20px;
                border-radius: 3px;
            }
        </style>
        <?php
    }
    
    /**
     * Renderizar página de disponibilidad
     */
    public function render_availability_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Disponibilidad de Productos', 'wc-rental-system'); ?></h1>
            
            <div class="availability-container">
                <div class="availability-filters">
                    <h3><?php _e('Filtros', 'wc-rental-system'); ?></h3>
                    
                    <label><?php _e('Producto:', 'wc-rental-system'); ?></label>
                    <select id="availability-product" style="width: 100%;">
                        <option value=""><?php _e('Seleccionar producto...', 'wc-rental-system'); ?></option>
                    </select>
                    
                    <label><?php _e('Rango de fechas:', 'wc-rental-system'); ?></label>
                    <input type="text" id="availability-date-from" placeholder="<?php _e('Desde', 'wc-rental-system'); ?>">
                    <input type="text" id="availability-date-to" placeholder="<?php _e('Hasta', 'wc-rental-system'); ?>">
                    
                    <button class="button button-primary" id="check-availability">
                        <?php _e('Verificar Disponibilidad', 'wc-rental-system'); ?>
                    </button>
                </div>
                
                <div class="availability-results">
                    <h3><?php _e('Resultados', 'wc-rental-system'); ?></h3>
                    <div id="availability-grid">
                        <p><?php _e('Selecciona un producto y fechas para ver la disponibilidad', 'wc-rental-system'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Renderizar página de reportes
     */
    public function render_reports_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Reportes de Alquileres', 'wc-rental-system'); ?></h1>
            
            <div class="reports-tabs">
                <ul class="nav-tab-wrapper">
                    <li><a href="#revenue" class="nav-tab nav-tab-active"><?php _e('Ingresos', 'wc-rental-system'); ?></a></li>
                    <li><a href="#products" class="nav-tab"><?php _e('Productos', 'wc-rental-system'); ?></a></li>
                    <li><a href="#customers" class="nav-tab"><?php _e('Clientes', 'wc-rental-system'); ?></a></li>
                    <li><a href="#utilization" class="nav-tab"><?php _e('Utilización', 'wc-rental-system'); ?></a></li>
                </ul>
                
                <div class="tab-content" id="revenue">
                    <?php $this->render_revenue_report(); ?>
                </div>
                
                <div class="tab-content" id="products" style="display: none;">
                    <?php $this->render_products_report(); ?>
                </div>
                
                <div class="tab-content" id="customers" style="display: none;">
                    <?php $this->render_customers_report(); ?>
                </div>
                
                <div class="tab-content" id="utilization" style="display: none;">
                    <?php $this->render_utilization_report(); ?>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Renderizar página de configuración
     */
    public function render_settings_page() {
        // Guardar configuración si se envió el formulario
        if (isset($_POST['save_settings'])) {
            $this->save_settings();
        }
        
        $settings = get_option('wc_rental_settings', array());
        ?>
        <div class="wrap">
            <h1><?php _e('Configuración del Sistema de Alquileres', 'wc-rental-system'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('rental_settings', 'rental_settings_nonce'); ?>
                
                <h2><?php _e('Configuración General', 'wc-rental-system'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="rental_mode"><?php _e('Modo de Alquiler', 'wc-rental-system'); ?></label></th>
                        <td>
                            <select name="rental_mode" id="rental_mode">
                                <option value="days" <?php selected($settings['rental_mode'] ?? 'days', 'days'); ?>>
                                    <?php _e('Por días', 'wc-rental-system'); ?>
                                </option>
                                <option value="hours" <?php selected($settings['rental_mode'] ?? '', 'hours'); ?>>
                                    <?php _e('Por horas', 'wc-rental-system'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="currency_position"><?php _e('Posición del símbolo de moneda', 'wc-rental-system'); ?></label></th>
                        <td>
                            <select name="currency_position" id="currency_position">
                                <option value="before" <?php selected($settings['currency_position'] ?? 'before', 'before'); ?>>
                                    <?php _e('Antes del monto', 'wc-rental-system'); ?>
                                </option>
                                <option value="after" <?php selected($settings['currency_position'] ?? '', 'after'); ?>>
                                    <?php _e('Después del monto', 'wc-rental-system'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <h2><?php _e('Notificaciones por Email', 'wc-rental-system'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php _e('Emails activos', 'wc-rental-system'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="email_new_rental" value="1" 
                                    <?php checked($settings['email_new_rental'] ?? 1, 1); ?>>
                                <?php _e('Nuevo alquiler (admin)', 'wc-rental-system'); ?>
                            </label><br>
                            
                            <label>
                                <input type="checkbox" name="email_rental_confirmed" value="1"
                                    <?php checked($settings['email_rental_confirmed'] ?? 1, 1); ?>>
                                <?php _e('Alquiler confirmado (cliente)', 'wc-rental-system'); ?>
                            </label><br>
                            
                            <label>
                                <input type="checkbox" name="email_rental_reminder" value="1"
                                    <?php checked($settings['email_rental_reminder'] ?? 1, 1); ?>>
                                <?php _e('Recordatorio de devolución', 'wc-rental-system'); ?>
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="reminder_days"><?php _e('Días para recordatorio', 'wc-rental-system'); ?></label></th>
                        <td>
                            <input type="number" name="reminder_days" id="reminder_days" 
                                value="<?php echo $settings['reminder_days'] ?? 1; ?>" min="1">
                            <p class="description">
                                <?php _e('Días antes de la devolución para enviar recordatorio', 'wc-rental-system'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <h2><?php _e('Configuración de Pedidos', 'wc-rental-system'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label><?php _e('Estados de pedido para confirmar alquiler', 'wc-rental-system'); ?></label></th>
                        <td>
                            <?php
                            $order_statuses = wc_get_order_statuses();
                            $selected_statuses = $settings['confirm_statuses'] ?? array('processing', 'completed');
                            
                            foreach ($order_statuses as $status => $label) {
                                $status_key = str_replace('wc-', '', $status);
                                ?>
                                <label>
                                    <input type="checkbox" name="confirm_statuses[]" value="<?php echo $status_key; ?>"
                                        <?php checked(in_array($status_key, $selected_statuses), true); ?>>
                                    <?php echo $label; ?>
                                </label><br>
                                <?php
                            }
                            ?>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" name="save_settings" class="button button-primary">
                        <?php _e('Guardar Configuración', 'wc-rental-system'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * Obtener estadísticas del dashboard
     */
    private function get_dashboard_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        $today = current_time('Y-m-d');
        
        $stats = array(
            'active_rentals' => $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = 'active' AND start_date <= %s AND end_date >= %s", $today, $today)
            ),
            'pending_rentals' => $wpdb->get_var(
                "SELECT COUNT(*) FROM $table WHERE status = 'pending'"
            ),
            'today_pickups' => $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE start_date = %s", $today)
            ),
            'today_returns' => $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE end_date = %s", $today)
            ),
        );
        
        return $stats;
    }
    
    /**
     * Renderizar próximos alquileres
     */
    private function render_upcoming_rentals() {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        $rentals = $wpdb->get_results(
            "SELECT r.*, p.post_title as product_name, u.display_name as customer_name
             FROM $table r
             LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
             LEFT JOIN {$wpdb->users} u ON r.customer_id = u.ID
             WHERE r.start_date >= CURDATE()
             AND r.status IN ('pending', 'confirmed')
             ORDER BY r.start_date ASC
             LIMIT 5"
        );
        
        if ($rentals) {
            echo '<table class="wp-list-table widefat">';
            echo '<thead><tr>';
            echo '<th>' . __('Cliente', 'wc-rental-system') . '</th>';
            echo '<th>' . __('Producto', 'wc-rental-system') . '</th>';
            echo '<th>' . __('Fecha Inicio', 'wc-rental-system') . '</th>';
            echo '<th>' . __('Estado', 'wc-rental-system') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($rentals as $rental) {
                echo '<tr>';
                echo '<td>' . esc_html($rental->customer_name) . '</td>';
                echo '<td>' . esc_html($rental->product_name) . '</td>';
                echo '<td>' . date_i18n(get_option('date_format'), strtotime($rental->start_date)) . '</td>';
                echo '<td><span class="rental-status status-' . $rental->status . '">' . ucfirst($rental->status) . '</span></td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        } else {
            echo '<p>' . __('No hay alquileres próximos', 'wc-rental-system') . '</p>';
        }
    }
    
    /**
     * Renderizar próximas devoluciones
     */
    private function render_upcoming_returns() {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        $rentals = $wpdb->get_results(
            "SELECT r.*, p.post_title as product_name, u.display_name as customer_name
             FROM $table r
             LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
             LEFT JOIN {$wpdb->users} u ON r.customer_id = u.ID
             WHERE r.end_date >= CURDATE()
             AND r.end_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             AND r.status = 'active'
             ORDER BY r.end_date ASC
             LIMIT 5"
        );
        
        if ($rentals) {
            echo '<table class="wp-list-table widefat">';
            echo '<thead><tr>';
            echo '<th>' . __('Cliente', 'wc-rental-system') . '</th>';
            echo '<th>' . __('Producto', 'wc-rental-system') . '</th>';
            echo '<th>' . __('Fecha Devolución', 'wc-rental-system') . '</th>';
            echo '<th>' . __('Días', 'wc-rental-system') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($rentals as $rental) {
                $days_left = (strtotime($rental->end_date) - strtotime(current_time('Y-m-d'))) / 86400;
                echo '<tr>';
                echo '<td>' . esc_html($rental->customer_name) . '</td>';
                echo '<td>' . esc_html($rental->product_name) . '</td>';
                echo '<td>' . date_i18n(get_option('date_format'), strtotime($rental->end_date)) . '</td>';
                echo '<td>' . ($days_left == 0 ? __('Hoy', 'wc-rental-system') : sprintf(__('%d días', 'wc-rental-system'), $days_left)) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        } else {
            echo '<p>' . __('No hay devoluciones próximas', 'wc-rental-system') . '</p>';
        }
    }
    
    /**
     * Renderizar productos más alquilados
     */
    private function render_top_products() {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        $products = $wpdb->get_results(
            "SELECT p.post_title as product_name, COUNT(r.id) as rental_count,
                    SUM(r.rental_price) as total_revenue
             FROM $table r
             LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
             WHERE r.status IN ('active', 'completed')
             GROUP BY r.product_id
             ORDER BY rental_count DESC
             LIMIT 5"
        );
        
        if ($products) {
            echo '<table class="wp-list-table widefat">';
            echo '<thead><tr>';
            echo '<th>' . __('Producto', 'wc-rental-system') . '</th>';
            echo '<th>' . __('Alquileres', 'wc-rental-system') . '</th>';
            echo '<th>' . __('Ingresos', 'wc-rental-system') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($products as $product) {
                echo '<tr>';
                echo '<td>' . esc_html($product->product_name) . '</td>';
                echo '<td>' . $product->rental_count . '</td>';
                echo '<td>' . wc_price($product->total_revenue) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        } else {
            echo '<p>' . __('No hay datos disponibles', 'wc-rental-system') . '</p>';
        }
    }
    
    /**
     * Tabla temporal de alquileres
     */
    private function render_temporary_rentals_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        $rentals = $wpdb->get_results(
            "SELECT r.*, p.post_title as product_name, u.display_name as customer_name
             FROM $table r
             LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
             LEFT JOIN {$wpdb->users} u ON r.customer_id = u.ID
             ORDER BY r.created_at DESC
             LIMIT 20"
        );
        
        if ($rentals) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . __('ID', 'wc-rental-system') . '</th>';
            echo '<th>' . __('Cliente', 'wc-rental-system') . '</th>';
            echo '<th>' . __('Producto', 'wc-rental-system') . '</th>';
            echo '<th>' . __('Fechas', 'wc-rental-system') . '</th>';
            echo '<th>' . __('Precio', 'wc-rental-system') . '</th>';
            echo '<th>' . __('Estado', 'wc-rental-system') . '</th>';
            echo '<th>' . __('Acciones', 'wc-rental-system') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($rentals as $rental) {
                echo '<tr>';
                echo '<td>#' . $rental->id . '</td>';
                echo '<td>' . esc_html($rental->customer_name) . '</td>';
                echo '<td>' . esc_html($rental->product_name) . '</td>';
                echo '<td>' . date_i18n('d/m/Y', strtotime($rental->start_date)) . ' - ' . 
                     date_i18n('d/m/Y', strtotime($rental->end_date)) . '</td>';
                echo '<td>' . wc_price($rental->rental_price) . '</td>';
                echo '<td><span class="rental-status status-' . $rental->status . '">' . 
                     ucfirst($rental->status) . '</span></td>';
                echo '<td>
                        <a href="?page=wc-rentals-edit&id=' . $rental->id . '" class="button button-small">
                            ' . __('Editar', 'wc-rental-system') . '
                        </a>
                      </td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        } else {
            echo '<p>' . __('No hay alquileres registrados', 'wc-rental-system') . '</p>';
        }
    }
    
    /**
     * Renderizar reporte de ingresos
     */
    private function render_revenue_report() {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rentals';
        
        // Obtener datos del último mes
        $revenue_data = $wpdb->get_results(
            "SELECT DATE(created_at) as date, 
                    SUM(rental_price) as revenue,
                    COUNT(*) as rentals
             FROM $table
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             AND status IN ('active', 'completed')
             GROUP BY DATE(created_at)
             ORDER BY date ASC"
        );
        
        ?>
        <div class="revenue-report">
            <div class="report-filters">
                <select id="revenue-period">
                    <option value="7"><?php _e('Últimos 7 días', 'wc-rental-system'); ?></option>
                    <option value="30" selected><?php _e('Últimos 30 días', 'wc-rental-system'); ?></option>
                    <option value="90"><?php _e('Últimos 90 días', 'wc-rental-system'); ?></option>
                </select>
                
                <button class="button" id="export-revenue">
                    <?php _e('Exportar CSV', 'wc-rental-system'); ?>
                </button>
            </div>
            
            <div id="revenue-chart" style="height: 300px; margin: 20px 0;"></div>
            
            <table class="wp-list-table widefat">
                <thead>
                    <tr>
                        <th><?php _e('Fecha', 'wc-rental-system'); ?></th>
                        <th><?php _e('Alquileres', 'wc-rental-system'); ?></th>
                        <th><?php _e('Ingresos', 'wc-rental-system'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_revenue = 0;
                    $total_rentals = 0;
                    
                    foreach ($revenue_data as $day) {
                        $total_revenue += $day->revenue;
                        $total_rentals += $day->rentals;
                        
                        echo '<tr>';
                        echo '<td>' . date_i18n(get_option('date_format'), strtotime($day->date)) . '</td>';
                        echo '<td>' . $day->rentals . '</td>';
                        echo '<td>' . wc_price($day->revenue) . '</td>';
                        echo '</tr>';
                    }
                    ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th><?php _e('Total', 'wc-rental-system'); ?></th>
                        <th><?php echo $total_rentals; ?></th>
                        <th><?php echo wc_price($total_revenue); ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php
    }
    
    /**
     * Guardar configuración
     */
    private function save_settings() {
        if (!wp_verify_nonce($_POST['rental_settings_nonce'], 'rental_settings')) {
            return;
        }
        
        $settings = array(
            'rental_mode' => sanitize_text_field($_POST['rental_mode']),
            'currency_position' => sanitize_text_field($_POST['currency_position']),
            'email_new_rental' => isset($_POST['email_new_rental']) ? 1 : 0,
            'email_rental_confirmed' => isset($_POST['email_rental_confirmed']) ? 1 : 0,
            'email_rental_reminder' => isset($_POST['email_rental_reminder']) ? 1 : 0,
            'reminder_days' => intval($_POST['reminder_days']),
            'confirm_statuses' => isset($_POST['confirm_statuses']) ? $_POST['confirm_statuses'] : array(),
        );
        
        update_option('wc_rental_settings', $settings);
        
        add_settings_error(
            'rental_settings',
            'settings_updated',
            __('Configuración guardada correctamente', 'wc-rental-system'),
            'updated'
        );
    }
    
    /**
     * Añadir opciones de pantalla
     */
    public function add_screen_options() {
        $option = 'per_page';
        $args = array(
            'label' => __('Alquileres por página', 'wc-rental-system'),
            'default' => 20,
            'option' => 'rentals_per_page'
        );
        
        add_screen_option($option, $args);
    }
    
    /**
     * Guardar opciones de pantalla
     */
    public function set_screen_option($status, $option, $value) {
        if ('rentals_per_page' === $option) {
            return $value;
        }
        return $status;
    }
    
    /**
     * Mostrar avisos de administración
     */
    public function admin_notices() {
        settings_errors('rental_settings');
    }
    
    /**
     * Cargar scripts de administración específicos
     */
    public function enqueue_admin_scripts($hook) {
        // Solo cargar en páginas del plugin
        if (strpos($hook, 'wc-rentals') === false) {
            return;
        }
        
        // Para el selector de clientes
        wp_enqueue_script('selectWoo');
        wp_enqueue_style('select2');
        
        // Scripts específicos para el calendario
        if (strpos($hook, 'calendar') !== false) {
            wp_enqueue_script('fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js', array(), '5.11.3');
            wp_enqueue_style('fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css', array(), '5.11.3');
        }
        
        // Gráficos para reportes
        if (strpos($hook, 'reports') !== false) {
            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', array(), '3.9.1');
        }
    }
    
    /**
     * Renderizar reporte de productos
     */
    private function render_products_report() {
        echo '<p>' . __('Reporte de productos en desarrollo...', 'wc-rental-system') . '</p>';
    }
    
    /**
     * Renderizar reporte de clientes
     */
    private function render_customers_report() {
        echo '<p>' . __('Reporte de clientes en desarrollo...', 'wc-rental-system') . '</p>';
    }
    
    /**
     * Renderizar reporte de utilización
     */
    private function render_utilization_report() {
        echo '<p>' . __('Reporte de utilización en desarrollo...', 'wc-rental-system') . '</p>';
    }
}