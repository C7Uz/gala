<?php
/**
 * Clase para manejar los filtros de la tienda y listados de productos
 * Archivo: includes/frontend/class-shop-filters.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Rental_Shop_Filters {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Añadir filtros de búsqueda por fechas
        add_action('woocommerce_before_shop_loop', array($this, 'render_date_filters'), 15);
        
        // Añadir badge de alquiler en el loop de productos
        add_action('woocommerce_before_shop_loop_item_title', array($this, 'add_rental_badge'), 15);
        add_action('woocommerce_after_shop_loop_item_title', array($this, 'add_rental_price_info'), 5);
        
        // Modificar query de productos
        add_action('woocommerce_product_query', array($this, 'filter_products_by_availability'));
        
        // Añadir opciones de ordenamiento
        add_filter('woocommerce_catalog_orderby', array($this, 'add_rental_orderby_options'));
        add_filter('woocommerce_get_catalog_ordering_args', array($this, 'handle_rental_orderby'));
        
        // Widget de filtros de alquiler
        add_action('widgets_init', array($this, 'register_rental_filter_widget'));
        
        // Añadir clase CSS a productos en alquiler
        add_filter('woocommerce_post_class', array($this, 'add_rental_product_class'), 10, 2);
        
        // Shortcodes
        add_shortcode('rental_search', array($this, 'rental_search_shortcode'));
        add_shortcode('rental_calendar', array($this, 'rental_calendar_shortcode'));
        add_shortcode('featured_rentals', array($this, 'featured_rentals_shortcode'));
        
        // Ajax handlers
        add_action('wp_ajax_filter_rentals_by_date', array($this, 'ajax_filter_by_date'));
        add_action('wp_ajax_nopriv_filter_rentals_by_date', array($this, 'ajax_filter_by_date'));
        add_action('wp_ajax_get_availability_calendar', array($this, 'ajax_get_availability_calendar'));
        add_action('wp_ajax_nopriv_get_availability_calendar', array($this, 'ajax_get_availability_calendar'));
        
        // Modificar el título de la categoría si hay filtros activos
        add_filter('woocommerce_page_title', array($this, 'modify_shop_title'));
        
        // Scripts y estilos
        add_action('wp_enqueue_scripts', array($this, 'enqueue_shop_scripts'));
        
        // Añadir filtros a la URL
        add_filter('woocommerce_product_query_meta_query', array($this, 'filter_rentable_products'));
        
        // Información adicional en la tarjeta del producto
        add_action('woocommerce_after_shop_loop_item', array($this, 'add_quick_view_button'), 15);
        
        // Filtro de disponibilidad en sidebar
        add_action('woocommerce_sidebar', array($this, 'add_availability_filter_sidebar'), 5);
    }
    
    /**
     * Renderizar filtros de fecha en la tienda
     */
    public function render_date_filters() {
        // Solo mostrar si estamos filtrando productos en alquiler
        if (!$this->should_show_rental_filters()) {
            return;
        }
        
        $start_date = isset($_GET['rental_start']) ? sanitize_text_field($_GET['rental_start']) : '';
        $end_date = isset($_GET['rental_end']) ? sanitize_text_field($_GET['rental_end']) : '';
        $only_available = isset($_GET['only_available']) ? 'checked' : '';
        
        ?>
        <div class="rental-shop-filters">
            <div class="rental-filters-container">
                <h3><?php _e('Buscar Alquileres Disponibles', 'wc-rental-system'); ?></h3>
                
                <form method="get" class="rental-filter-form">
                    <?php
                    // Mantener otros parámetros de GET
                    foreach ($_GET as $key => $value) {
                        if (!in_array($key, array('rental_start', 'rental_end', 'only_available'))) {
                            if (is_array($value)) {
                                foreach ($value as $v) {
                                    echo '<input type="hidden" name="' . esc_attr($key) . '[]" value="' . esc_attr($v) . '">';
                                }
                            } else {
                                echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
                            }
                        }
                    }
                    ?>
                    
                    <div class="filter-row">
                        <div class="filter-field">
                            <label for="rental_start"><?php _e('Fecha Inicio', 'wc-rental-system'); ?></label>
                            <input type="text" 
                                   id="rental_start" 
                                   name="rental_start" 
                                   class="rental-datepicker" 
                                   value="<?php echo esc_attr($start_date); ?>"
                                   placeholder="<?php _e('Seleccionar fecha', 'wc-rental-system'); ?>">
                        </div>
                        
                        <div class="filter-field">
                            <label for="rental_end"><?php _e('Fecha Fin', 'wc-rental-system'); ?></label>
                            <input type="text" 
                                   id="rental_end" 
                                   name="rental_end" 
                                   class="rental-datepicker" 
                                   value="<?php echo esc_attr($end_date); ?>"
                                   placeholder="<?php _e('Seleccionar fecha', 'wc-rental-system'); ?>">
                        </div>
                        
                        <div class="filter-field checkbox-field">
                            <label>
                                <input type="checkbox" 
                                       name="only_available" 
                                       value="1" 
                                       <?php echo $only_available; ?>>
                                <?php _e('Solo mostrar disponibles', 'wc-rental-system'); ?>
                            </label>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="button filter-button">
                                <?php _e('Buscar', 'wc-rental-system'); ?>
                            </button>
                            
                            <?php if ($start_date || $end_date) : ?>
                                <a href="<?php echo esc_url(remove_query_arg(array('rental_start', 'rental_end', 'only_available'))); ?>" 
                                   class="button clear-button">
                                    <?php _e('Limpiar', 'wc-rental-system'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Filtros rápidos de período -->
                    <div class="quick-filters">
                        <span><?php _e('Búsqueda rápida:', 'wc-rental-system'); ?></span>
                        <button type="button" class="quick-filter-btn" data-days="1">
                            <?php _e('Este fin de semana', 'wc-rental-system'); ?>
                        </button>
                        <button type="button" class="quick-filter-btn" data-days="7">
                            <?php _e('Próxima semana', 'wc-rental-system'); ?>
                        </button>
                        <button type="button" class="quick-filter-btn" data-days="30">
                            <?php _e('Próximo mes', 'wc-rental-system'); ?>
                        </button>
                    </div>
                </form>
                
                <?php if ($start_date && $end_date) : ?>
                    <div class="active-filters">
                        <strong><?php _e('Filtros activos:', 'wc-rental-system'); ?></strong>
                        <?php 
                        echo sprintf(
                            __('Del %s al %s', 'wc-rental-system'),
                            date_i18n('d/m/Y', strtotime($start_date)),
                            date_i18n('d/m/Y', strtotime($end_date))
                        );
                        
                        $start = new DateTime($start_date);
                        $end = new DateTime($end_date);
                        $days = $start->diff($end)->days;
                        echo ' <em>(' . sprintf(__('%d días', 'wc-rental-system'), $days) . ')</em>';
                        ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
            .rental-shop-filters {
                background: #f8f9fa;
                padding: 20px;
                margin-bottom: 30px;
                border-radius: 5px;
                border: 1px solid #dee2e6;
            }
            
            .rental-filters-container h3 {
                margin-top: 0;
                margin-bottom: 20px;
                font-size: 18px;
                color: #333;
            }
            
            .rental-filter-form .filter-row {
                display: flex;
                gap: 15px;
                align-items: flex-end;
                flex-wrap: wrap;
            }
            
            .filter-field {
                flex: 1;
                min-width: 150px;
            }
            
            .filter-field label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
                font-size: 14px;
            }
            
            .filter-field input[type="text"] {
                width: 100%;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 3px;
            }
            
            .checkbox-field {
                display: flex;
                align-items: center;
                padding-top: 20px;
            }
            
            .checkbox-field label {
                display: flex;
                align-items: center;
                margin: 0;
                cursor: pointer;
            }
            
            .checkbox-field input[type="checkbox"] {
                margin-right: 8px;
            }
            
            .filter-actions {
                display: flex;
                gap: 10px;
                padding-top: 20px;
            }
            
            .filter-button {
                background: #0073aa !important;
                color: white !important;
                border: none !important;
                padding: 10px 20px !important;
            }
            
            .clear-button {
                background: #dc3545 !important;
                color: white !important;
                border: none !important;
                padding: 10px 20px !important;
            }
            
            .quick-filters {
                margin-top: 15px;
                padding-top: 15px;
                border-top: 1px solid #dee2e6;
            }
            
            .quick-filters span {
                margin-right: 10px;
                font-weight: 600;
            }
            
            .quick-filter-btn {
                background: white;
                border: 1px solid #ddd;
                padding: 5px 12px;
                margin-right: 8px;
                border-radius: 3px;
                cursor: pointer;
                transition: all 0.3s;
            }
            
            .quick-filter-btn:hover {
                background: #0073aa;
                color: white;
                border-color: #0073aa;
            }
            
            .active-filters {
                margin-top: 15px;
                padding: 10px;
                background: #d4edda;
                border: 1px solid #c3e6cb;
                border-radius: 3px;
                color: #155724;
            }
            
            @media (max-width: 768px) {
                .rental-filter-form .filter-row {
                    flex-direction: column;
                }
                
                .filter-field,
                .checkbox-field,
                .filter-actions {
                    width: 100%;
                    padding-top: 10px;
                }
            }
        </style>
        <?php
    }
    
    /**
     * Añadir badge de alquiler en productos
     */
    public function add_rental_badge() {
        global $product;
        
        if (!$product || !$this->is_rentable_product($product)) {
            return;
        }
        
        // Badge principal
        echo '<span class="rental-badge-loop">' . __('ALQUILER', 'wc-rental-system') . '</span>';
        
        // Si hay filtros de fecha activos, mostrar disponibilidad
        if (isset($_GET['rental_start']) && isset($_GET['rental_end'])) {
            $available = $this->check_product_availability(
                $product->get_id(),
                $_GET['rental_start'],
                $_GET['rental_end']
            );
            
            if ($available) {
                echo '<span class="availability-badge available">' . __('Disponible', 'wc-rental-system') . '</span>';
            } else {
                echo '<span class="availability-badge unavailable">' . __('No disponible', 'wc-rental-system') . '</span>';
            }
        }
        
        ?>
        <style>
            .rental-badge-loop {
                position: absolute;
                top: 10px;
                left: 10px;
                background: #ff9800;
                color: white;
                padding: 5px 10px;
                font-size: 11px;
                font-weight: bold;
                text-transform: uppercase;
                border-radius: 3px;
                z-index: 10;
            }
            
            .availability-badge {
                position: absolute;
                top: 10px;
                right: 10px;
                padding: 5px 10px;
                font-size: 11px;
                font-weight: bold;
                text-transform: uppercase;
                border-radius: 3px;
                z-index: 10;
            }
            
            .availability-badge.available {
                background: #4caf50;
                color: white;
            }
            
            .availability-badge.unavailable {
                background: #f44336;
                color: white;
            }
            
            .products .product {
                position: relative;
            }
        </style>
        <?php
    }
    
    /**
     * Añadir información de precio de alquiler
     */
    public function add_rental_price_info() {
        global $product;
        
        if (!$product || !$this->is_rentable_product($product)) {
            return;
        }
        
        $rental_settings = $this->get_product_rental_settings($product->get_id());
        $price_per_day = $product->get_price();
        
        ?>
        <div class="rental-price-info">
            <span class="rental-from">
                <?php echo sprintf(__('Desde %s/día', 'wc-rental-system'), wc_price($price_per_day)); ?>
            </span>
            <span class="rental-min-days">
                <?php echo sprintf(__('Mín. %d días', 'wc-rental-system'), $rental_settings['min_rental_days']); ?>
            </span>
            
            <?php if ($rental_settings['deposit_percentage'] > 0 || $rental_settings['deposit_fixed'] > 0) : ?>
                <span class="rental-deposit-info">
                    <?php
                    if ($rental_settings['deposit_percentage'] > 0) {
                        echo sprintf(__('+ %s%% garantía', 'wc-rental-system'), $rental_settings['deposit_percentage']);
                    } else {
                        echo sprintf(__('+ %s garantía', 'wc-rental-system'), wc_price($rental_settings['deposit_fixed']));
                    }
                    ?>
                </span>
            <?php endif; ?>
        </div>
        
        <style>
            .rental-price-info {
                margin: 5px 0;
                font-size: 13px;
            }
            
            .rental-price-info span {
                display: block;
                margin: 2px 0;
            }
            
            .rental-from {
                color: #0073aa;
                font-weight: bold;
            }
            
            .rental-min-days {
                color: #666;
                font-size: 12px;
            }
            
            .rental-deposit-info {
                color: #ff9800;
                font-size: 11px;
                font-style: italic;
            }
        </style>
        <?php
    }
    
    /**
     * Filtrar productos por disponibilidad
     */
    public function filter_products_by_availability($query) {
        if (!is_admin() && $query->is_main_query()) {
            // Si hay filtros de fecha activos
            if (isset($_GET['rental_start']) && isset($_GET['rental_end']) && isset($_GET['only_available'])) {
                $start_date = sanitize_text_field($_GET['rental_start']);
                $end_date = sanitize_text_field($_GET['rental_end']);
                
                // Obtener IDs de productos disponibles
                $available_ids = $this->get_available_product_ids($start_date, $end_date);
                
                if (!empty($available_ids)) {
                    $query->set('post__in', $available_ids);
                } else {
                    // No hay productos disponibles
                    $query->set('post__in', array(0));
                }
            }
            
            // Filtrar solo productos en alquiler si está en categoría específica
            if (isset($_GET['rental_only']) && $_GET['rental_only'] == '1') {
                $rental_ids = $this->get_rentable_product_ids();
                
                if (!empty($rental_ids)) {
                    $existing_ids = $query->get('post__in');
                    if (!empty($existing_ids)) {
                        // Intersección con IDs existentes
                        $rental_ids = array_intersect($rental_ids, $existing_ids);
                    }
                    $query->set('post__in', $rental_ids);
                }
            }
        }
    }
    
    /**
     * Añadir opciones de ordenamiento
     */
    public function add_rental_orderby_options($options) {
        $options['rental_price_day'] = __('Precio por día (menor a mayor)', 'wc-rental-system');
        $options['rental_price_day_desc'] = __('Precio por día (mayor a menor)', 'wc-rental-system');
        $options['rental_min_days'] = __('Días mínimos de alquiler', 'wc-rental-system');
        $options['rental_popular'] = __('Más alquilados', 'wc-rental-system');
        
        return $options;
    }
    
    /**
     * Manejar ordenamiento personalizado
     */
    public function handle_rental_orderby($args) {
        $orderby = isset($_GET['orderby']) ? wc_clean($_GET['orderby']) : '';
        
        switch ($orderby) {
            case 'rental_price_day':
                $args['orderby'] = 'meta_value_num';
                $args['meta_key'] = '_price';
                $args['order'] = 'ASC';
                break;
                
            case 'rental_price_day_desc':
                $args['orderby'] = 'meta_value_num';
                $args['meta_key'] = '_price';
                $args['order'] = 'DESC';
                break;
                
            case 'rental_min_days':
                // Ordenar por días mínimos de alquiler
                global $wpdb;
                add_filter('posts_clauses', function($clauses) use ($wpdb) {
                    $clauses['join'] .= " LEFT JOIN {$wpdb->prefix}wc_rental_product_settings rps ON {$wpdb->posts}.ID = rps.product_id";
                    $clauses['orderby'] = "rps.min_rental_days ASC, {$wpdb->posts}.post_date DESC";
                    return $clauses;
                });
                break;
                
            case 'rental_popular':
                // Ordenar por número de alquileres
                global $wpdb;
                add_filter('posts_clauses', function($clauses) use ($wpdb) {
                    $clauses['join'] .= " LEFT JOIN (
                        SELECT product_id, COUNT(*) as rental_count 
                        FROM {$wpdb->prefix}wc_rentals 
                        WHERE status IN ('active', 'completed') 
                        GROUP BY product_id
                    ) rc ON {$wpdb->posts}.ID = rc.product_id";
                    $clauses['orderby'] = "COALESCE(rc.rental_count, 0) DESC, {$wpdb->posts}.post_date DESC";
                    return $clauses;
                });
                break;
        }
        
        return $args;
    }
    
    /**
     * Registrar widget de filtros
     */
    public function register_rental_filter_widget() {
        register_widget('WC_Rental_Filter_Widget');
    }
    
    /**
     * Añadir clase CSS a productos en alquiler
     */
    public function add_rental_product_class($classes, $product) {
        if ($this->is_rentable_product($product)) {
            $classes[] = 'rental-product';
            
            // Si hay fechas seleccionadas, verificar disponibilidad
            if (isset($_GET['rental_start']) && isset($_GET['rental_end'])) {
                $available = $this->check_product_availability(
                    $product->get_id(),
                    $_GET['rental_start'],
                    $_GET['rental_end']
                );
                
                $classes[] = $available ? 'rental-available' : 'rental-unavailable';
            }
        }
        
        return $classes;
    }
    
    /**
     * Shortcode: Buscador de alquileres
     */
    public function rental_search_shortcode($atts) {
        $atts = shortcode_atts(array(
            'title' => __('Buscar Alquileres', 'wc-rental-system'),
            'show_categories' => 'yes',
            'show_quick_filters' => 'yes',
        ), $atts);
        
        ob_start();
        ?>
        <div class="rental-search-widget">
            <h3><?php echo esc_html($atts['title']); ?></h3>
            
            <form class="rental-search-form" method="get" action="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>">
                <div class="search-fields">
                    <?php if ($atts['show_categories'] === 'yes') : ?>
                    <div class="search-field">
                        <label><?php _e('Categoría', 'wc-rental-system'); ?></label>
                        <?php
                        wp_dropdown_categories(array(
                            'taxonomy' => 'product_cat',
                            'name' => 'product_cat',
                            'show_option_all' => __('Todas las categorías', 'wc-rental-system'),
                            'hierarchical' => true,
                            'hide_empty' => true,
                        ));
                        ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="search-field">
                        <label><?php _e('Fecha inicio', 'wc-rental-system'); ?></label>
                        <input type="text" name="rental_start" class="rental-datepicker" 
                               placeholder="<?php _e('Seleccionar', 'wc-rental-system'); ?>">
                    </div>
                    
                    <div class="search-field">
                        <label><?php _e('Fecha fin', 'wc-rental-system'); ?></label>
                        <input type="text" name="rental_end" class="rental-datepicker" 
                               placeholder="<?php _e('Seleccionar', 'wc-rental-system'); ?>">
                    </div>
                    
                    <div class="search-field">
                        <label><?php _e('Precio máximo/día', 'wc-rental-system'); ?></label>
                        <input type="number" name="max_price" min="0" step="1" 
                               placeholder="<?php _e('Sin límite', 'wc-rental-system'); ?>">
                    </div>
                </div>
                
                <input type="hidden" name="rental_only" value="1">
                <input type="hidden" name="only_available" value="1">
                
                <button type="submit" class="button search-button">
                    <?php _e('Buscar Disponibilidad', 'wc-rental-system'); ?>
                </button>
                
                <?php if ($atts['show_quick_filters'] === 'yes') : ?>
                <div class="search-quick-filters">
                    <p><?php _e('Búsquedas populares:', 'wc-rental-system'); ?></p>
                    <a href="#" class="quick-search" data-category="vestidos" data-days="1">
                        <?php _e('Vestidos fin de semana', 'wc-rental-system'); ?>
                    </a>
                    <a href="#" class="quick-search" data-category="accesorios" data-days="7">
                        <?php _e('Accesorios una semana', 'wc-rental-system'); ?>
                    </a>
                    <a href="#" class="quick-search" data-category="joyas" data-days="3">
                        <?php _e('Joyas evento', 'wc-rental-system'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </form>
        </div>
        
        <style>
            .rental-search-widget {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 5px;
                margin-bottom: 30px;
            }
            
            .rental-search-widget h3 {
                margin-top: 0;
                margin-bottom: 20px;
            }
            
            .search-fields {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin-bottom: 20px;
            }
            
            .search-field label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
                font-size: 14px;
            }
            
            .search-field input,
            .search-field select {
                width: 100%;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 3px;
            }
            
            .search-button {
                width: 100%;
                background: #0073aa !important;
                color: white !important;
                padding: 12px !important;
                font-size: 16px !important;
            }
            
            .search-quick-filters {
                margin-top: 15px;
                padding-top: 15px;
                border-top: 1px solid #dee2e6;
            }
            
            .search-quick-filters p {
                margin: 0 0 10px;
                font-weight: 600;
            }
            
            .quick-search {
                display: inline-block;
                margin-right: 10px;
                margin-bottom: 5px;
                padding: 5px 10px;
                background: white;
                border: 1px solid #ddd;
                border-radius: 3px;
                text-decoration: none;
                color: #333;
                transition: all 0.3s;
            }
            
            .quick-search:hover {
                background: #0073aa;
                color: white;
                border-color: #0073aa;
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Shortcode: Calendario de disponibilidad
     */
    public function rental_calendar_shortcode($atts) {
        $atts = shortcode_atts(array(
            'product_id' => 0,
            'show_legend' => 'yes',
            'months' => 2,
        ), $atts);
        
        ob_start();
        ?>
        <div class="rental-calendar-widget" 
             data-product-id="<?php echo esc_attr($atts['product_id']); ?>"
             data-months="<?php echo esc_attr($atts['months']); ?>">
            
            <div id="rental-calendar-display"></div>
            
            <?php if ($atts['show_legend'] === 'yes') : ?>
            <div class="calendar-legend">
                <span class="legend-item">
                    <span class="color-box available"></span>
                    <?php _e('Disponible', 'wc-rental-system'); ?>
                </span>
                <span class="legend-item">
                    <span class="color-box unavailable"></span>
                    <?php _e('No disponible', 'wc-rental-system'); ?>
                </span>
                <span class="legend-item">
                    <span class="color-box partial"></span>
                    <?php _e('Parcialmente disponible', 'wc-rental-system'); ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
        
        <style>
            .rental-calendar-widget {
                padding: 20px;
                background: white;
                border: 1px solid #ddd;
                border-radius: 5px;
            }
            
            #rental-calendar-display {
                margin-bottom: 20px;
            }
            
            .calendar-legend {
                display: flex;
                justify-content: center;
                gap: 20px;
                padding-top: 15px;
                border-top: 1px solid #eee;
            }
            
            .legend-item {
                display: flex;
                align-items: center;
                gap: 5px;
                font-size: 13px;
            }
            
            .color-box {
                width: 16px;
                height: 16px;
                border-radius: 3px;
            }
            
            .color-box.available {
                background: #4caf50;
            }
            
            .color-box.unavailable {
                background: #f44336;
            }
            
            .color-box.partial {
                background: #ff9800;
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Shortcode: Alquileres destacados
     */
    public function featured_rentals_shortcode($atts) {
        $atts = shortcode_atts(array(
            'limit' => 4,
            'columns' => 4,
            'orderby' => 'popular',
            'title' => __('Alquileres Destacados', 'wc-rental-system'),
        ), $atts);
        
        // Query de productos
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => intval($atts['limit']),
            'meta_query' => array(
                array(
                    'key' => '_is_rentable',
                    'value' => 'yes',
                    'compare' => '='
                )
            )
        );
        
        // Ordenamiento
        switch ($atts['orderby']) {
            case 'popular':
                $args['meta_key'] = 'total_sales';
                $args['orderby'] = 'meta_value_num';
                break;
            case 'recent':
                $args['orderby'] = 'date';
                $args['order'] = 'DESC';
                break;
            case 'price':
                $args['meta_key'] = '_price';
                $args['orderby'] = 'meta_value_num';
                $args['order'] = 'ASC';
                break;
        }
        
        $products = new WP_Query($args);
        
        ob_start();
        
        if ($products->have_posts()) :
        ?>
        <div class="featured-rentals-section">
            <?php if ($atts['title']) : ?>
                <h2><?php echo esc_html($atts['title']); ?></h2>
            <?php endif; ?>
            
            <div class="woocommerce">
                <ul class="products columns-<?php echo esc_attr($atts['columns']); ?>">
                    <?php
                    while ($products->have_posts()) : $products->the_post();
                        wc_get_template_part('content', 'product');
                    endwhile;
                    ?>
                </ul>
            </div>
        </div>
        <?php
        endif;
        
        wp_reset_postdata();
        
        return ob_get_clean();
    }
    
    /**
     * Modificar título de la tienda
     */
    public function modify_shop_title($title) {
        if (isset($_GET['rental_start']) && isset($_GET['rental_end'])) {
            $start = date_i18n('d/m/Y', strtotime($_GET['rental_start']));
            $end = date_i18n('d/m/Y', strtotime($_GET['rental_end']));
            
            $title .= sprintf(' - ' . __('Disponibles del %s al %s', 'wc-rental-system'), $start, $end);
        } elseif (isset($_GET['rental_only']) && $_GET['rental_only'] == '1') {
            $title .= ' - ' . __('Productos en Alquiler', 'wc-rental-system');
        }
        
        return $title;
    }
    
    /**
     * Añadir botón de vista rápida
     */
    public function add_quick_view_button() {
        global $product;
        
        if (!$this->is_rentable_product($product)) {
            return;
        }
        
        ?>
        <button class="button rental-quick-view" 
                data-product-id="<?php echo esc_attr($product->get_id()); ?>">
            <?php _e('Vista Rápida', 'wc-rental-system'); ?>
        </button>
        <?php
    }
    
    /**
     * Añadir filtro de disponibilidad en sidebar
     */
    public function add_availability_filter_sidebar() {
        if (!is_shop() && !is_product_category()) {
            return;
        }
        
        ?>
        <div class="widget rental-availability-widget">
            <h3 class="widget-title"><?php _e('Filtrar por Disponibilidad', 'wc-rental-system'); ?></h3>
            
            <div class="availability-filter-content">
                <form method="get" class="availability-form">
                    <?php
                    // Mantener otros parámetros
                    foreach ($_GET as $key => $value) {
                        if (!in_array($key, array('rental_start', 'rental_end', 'only_available'))) {
                            echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
                        }
                    }
                    ?>
                    
                    <div class="date-field">
                        <label><?php _e('Desde', 'wc-rental-system'); ?></label>
                        <input type="text" name="rental_start" class="rental-datepicker-sidebar" 
                               value="<?php echo isset($_GET['rental_start']) ? esc_attr($_GET['rental_start']) : ''; ?>">
                    </div>
                    
                    <div class="date-field">
                        <label><?php _e('Hasta', 'wc-rental-system'); ?></label>
                        <input type="text" name="rental_end" class="rental-datepicker-sidebar" 
                               value="<?php echo isset($_GET['rental_end']) ? esc_attr($_GET['rental_end']) : ''; ?>">
                    </div>
                    
                    <div class="checkbox-field">
                        <label>
                            <input type="checkbox" name="only_available" value="1" 
                                   <?php checked(isset($_GET['only_available'])); ?>>
                            <?php _e('Solo disponibles', 'wc-rental-system'); ?>
                        </label>
                    </div>
                    
                    <button type="submit" class="button">
                        <?php _e('Aplicar Filtro', 'wc-rental-system'); ?>
                    </button>
                    
                    <?php if (isset($_GET['rental_start']) || isset($_GET['rental_end'])) : ?>
                        <a href="<?php echo esc_url(remove_query_arg(array('rental_start', 'rental_end', 'only_available'))); ?>" 
                           class="clear-filter">
                            <?php _e('Limpiar', 'wc-rental-system'); ?>
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        
        <style>
            .rental-availability-widget {
                margin-bottom: 30px;
            }
            
            .availability-filter-content {
                padding: 15px;
                background: #f8f9fa;
                border-radius: 3px;
            }
            
            .availability-filter-content .date-field {
                margin-bottom: 15px;
            }
            
            .availability-filter-content label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
                font-size: 13px;
            }
            
            .availability-filter-content input[type="text"] {
                width: 100%;
                padding: 6px;
                border: 1px solid #ddd;
                border-radius: 3px;
            }
            
            .availability-filter-content .checkbox-field {
                margin: 15px 0;
            }
            
            .availability-filter-content .checkbox-field label {
                display: flex;
                align-items: center;
                cursor: pointer;
            }
            
            .availability-filter-content .checkbox-field input {
                margin-right: 5px;
            }
            
            .availability-filter-content .button {
                width: 100%;
                background: #0073aa;
                color: white;
                border: none;
                padding: 8px;
            }
            
            .availability-filter-content .clear-filter {
                display: block;
                text-align: center;
                margin-top: 10px;
                color: #dc3545;
                text-decoration: none;
                font-size: 13px;
            }
        </style>
        <?php
    }
    
    /**
     * Ajax: Filtrar por fecha
     */
    public function ajax_filter_by_date() {
        check_ajax_referer('rental_nonce', 'nonce');
        
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);
        $category = isset($_POST['category']) ? intval($_POST['category']) : 0;
        
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
        
        if ($category) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'terms' => $category,
                )
            );
        }
        
        $products = get_posts($args);
        $available_products = array();
        
        foreach ($products as $product_post) {
            if ($this->check_product_availability($product_post->ID, $start_date, $end_date)) {
                $product = wc_get_product($product_post->ID);
                $available_products[] = array(
                    'id' => $product->get_id(),
                    'name' => $product->get_name(),
                    'price' => $product->get_price(),
                    'image' => wp_get_attachment_url($product->get_image_id()),
                    'link' => get_permalink($product->get_id()),
                );
            }
        }
        
        wp_send_json_success(array(
            'products' => $available_products,
            'count' => count($available_products)
        ));
    }
    
    /**
     * Ajax: Obtener calendario de disponibilidad
     */
    public function ajax_get_availability_calendar() {
        check_ajax_referer('rental_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $month = isset($_POST['month']) ? intval($_POST['month']) : date('n');
        $year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');
        
        // Obtener fechas bloqueadas del mes
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_availability';
        
        $blocked_dates = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT blocked_date 
             FROM $table 
             WHERE product_id = %d 
             AND MONTH(blocked_date) = %d 
             AND YEAR(blocked_date) = %d",
            $product_id,
            $month,
            $year
        ));
        
        wp_send_json_success(array(
            'blocked_dates' => $blocked_dates,
            'month' => $month,
            'year' => $year
        ));
    }
    
    /**
     * Verificar si debemos mostrar filtros de alquiler
     */
    private function should_show_rental_filters() {
        // Mostrar en tienda principal
        if (is_shop()) {
            return true;
        }
        
        // Mostrar en categorías que tienen productos en alquiler
        if (is_product_category()) {
            $term = get_queried_object();
            
            // Verificar si la categoría tiene productos en alquiler
            $args = array(
                'post_type' => 'product',
                'posts_per_page' => 1,
                'tax_query' => array(
                    array(
                        'taxonomy' => 'product_cat',
                        'terms' => $term->term_id,
                    )
                ),
                'meta_query' => array(
                    array(
                        'key' => '_is_rentable',
                        'value' => 'yes',
                    )
                )
            );
            
            $query = new WP_Query($args);
            return $query->have_posts();
        }
        
        // Mostrar si hay parámetro rental_only
        if (isset($_GET['rental_only'])) {
            return true;
        }
        
        return false;
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
     * Obtener configuración de alquiler
     */
    private function get_product_rental_settings($product_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_product_settings';
        
        $settings = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE product_id = %d AND variation_id = 0",
            $product_id
        ), ARRAY_A);
        
        if (!$settings) {
            $settings = array(
                'min_rental_days' => 1,
                'max_rental_days' => 30,
                'deposit_percentage' => 0,
                'deposit_fixed' => 0,
            );
        }
        
        return $settings;
    }
    
    /**
     * Verificar disponibilidad de un producto
     */
    private function check_product_availability($product_id, $start_date, $end_date) {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_availability';
        
        $blocked_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table 
             WHERE product_id = %d 
             AND blocked_date BETWEEN %s AND %s",
            $product_id,
            $start_date,
            $end_date
        ));
        
        return $blocked_count == 0;
    }
    
    /**
     * Obtener IDs de productos disponibles
     */
    private function get_available_product_ids($start_date, $end_date) {
        global $wpdb;
        
        // Primero obtener todos los productos alquilables
        $rental_table = $wpdb->prefix . 'wc_rental_product_settings';
        $availability_table = $wpdb->prefix . 'wc_rental_availability';
        
        // Productos alquilables que NO tienen bloqueos en las fechas seleccionadas
        $query = "SELECT DISTINCT rps.product_id 
                  FROM $rental_table rps
                  WHERE rps.is_rentable = 1
                  AND rps.product_id NOT IN (
                      SELECT DISTINCT product_id 
                      FROM $availability_table
                      WHERE blocked_date BETWEEN %s AND %s
                  )";
        
        $available_ids = $wpdb->get_col($wpdb->prepare($query, $start_date, $end_date));
        
        return $available_ids;
    }
    
    /**
     * Obtener IDs de productos alquilables
     */
    private function get_rentable_product_ids() {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_rental_product_settings';
        
        return $wpdb->get_col(
            "SELECT DISTINCT product_id FROM $table WHERE is_rentable = 1"
        );
    }
    
    /**
     * Filtrar solo productos alquilables
     */
    public function filter_rentable_products($meta_query) {
        if (isset($_GET['rental_only']) && $_GET['rental_only'] == '1') {
            $meta_query[] = array(
                'key' => '_is_rentable',
                'value' => 'yes',
                'compare' => '='
            );
        }
        
        return $meta_query;
    }
    
    /**
     * Cargar scripts para la tienda
     */
    public function enqueue_shop_scripts() {
        if (!is_shop() && !is_product_category() && !is_product_tag()) {
            return;
        }
        
        // jQuery UI para datepicker
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
        
        // Script inline
        wp_add_inline_script('jquery-ui-datepicker', $this->get_shop_inline_script(), 'after');
    }
    
    /**
     * Obtener script inline para la tienda
     */
    private function get_shop_inline_script() {
        ob_start();
        ?>
        jQuery(document).ready(function($) {
            // Inicializar datepickers
            $('.rental-datepicker, .rental-datepicker-sidebar').datepicker({
                dateFormat: 'yy-mm-dd',
                minDate: 0,
                onSelect: function(selectedDate) {
                    var option = $(this).hasClass('start-date') ? 'minDate' : 'maxDate';
                    var instance = $(this).data('datepicker');
                    var date = $.datepicker.parseDate(instance.settings.dateFormat || $.datepicker._defaults.dateFormat, selectedDate, instance.settings);
                    
                    if ($(this).attr('id') === 'rental_start') {
                        $('#rental_end').datepicker('option', 'minDate', date);
                    }
                }
            });
            
            // Filtros rápidos
            $('.quick-filter-btn').on('click', function(e) {
                e.preventDefault();
                
                var days = $(this).data('days');
                var today = new Date();
                var endDate = new Date();
                
                if (days === 1) { // Fin de semana
                    var dayOfWeek = today.getDay();
                    var daysUntilFriday = (5 - dayOfWeek + 7) % 7 || 7;
                    today = new Date(today.getTime() + daysUntilFriday * 24 * 60 * 60 * 1000);
                    endDate = new Date(today.getTime() + 2 * 24 * 60 * 60 * 1000);
                } else {
                    endDate.setDate(today.getDate() + days);
                }
                
                $('#rental_start').datepicker('setDate', today);
                $('#rental_end').datepicker('setDate', endDate);
            });
            
            // Vista rápida
            $('.rental-quick-view').on('click', function(e) {
                e.preventDefault();
                
                var productId = $(this).data('product-id');
                
                // Aquí implementarías un modal con información del producto
                alert('Vista rápida del producto #' + productId);
            });
            
            // Búsquedas rápidas
            $('.quick-search').on('click', function(e) {
                e.preventDefault();
                
                var category = $(this).data('category');
                var days = $(this).data('days');
                
                // Establecer fechas
                var today = new Date();
                var endDate = new Date();
                endDate.setDate(today.getDate() + days);
                
                // Redirigir con parámetros
                var url = '<?php echo get_permalink(wc_get_page_id('shop')); ?>';
                url += '?rental_start=' + formatDate(today);
                url += '&rental_end=' + formatDate(endDate);
                url += '&rental_only=1';
                url += '&only_available=1';
                
                if (category) {
                    url += '&product_cat=' + category;
                }
                
                window.location.href = url;
            });
            
            // Función para formatear fecha
            function formatDate(date) {
                var year = date.getFullYear();
                var month = ('0' + (date.getMonth() + 1)).slice(-2);
                var day = ('0' + date.getDate()).slice(-2);
                return year + '-' + month + '-' + day;
            }
        });
        <?php
        return ob_get_clean();
    }
}

/**
 * Widget de filtros de alquiler
 */
class WC_Rental_Filter_Widget extends WP_Widget {
    
    public function __construct() {
        parent::__construct(
            'wc_rental_filter_widget',
            __('Filtros de Alquiler', 'wc-rental-system'),
            array('description' => __('Widget de filtros para productos en alquiler', 'wc-rental-system'))
        );
    }
    
    public function widget($args, $instance) {
        echo $args['before_widget'];
        
        if (!empty($instance['title'])) {
            echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
        }
        
        // Aquí el contenido del widget
        ?>
        <div class="rental-widget-content">
            <form method="get" action="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>">
                <div class="widget-field">
                    <label><?php _e('Fecha inicio', 'wc-rental-system'); ?></label>
                    <input type="text" name="rental_start" class="rental-widget-datepicker">
                </div>
                
                <div class="widget-field">
                    <label><?php _e('Fecha fin', 'wc-rental-system'); ?></label>
                    <input type="text" name="rental_end" class="rental-widget-datepicker">
                </div>
                
                <input type="hidden" name="rental_only" value="1">
                <button type="submit" class="button"><?php _e('Filtrar', 'wc-rental-system'); ?></button>
            </form>
        </div>
        <?php
        
        echo $args['after_widget'];
    }
    
    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : __('Filtrar Alquileres', 'wc-rental-system');
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php _e('Título:', 'wc-rental-system'); ?>
            </label>
            <input class="widefat" 
                   id="<?php echo esc_attr($this->get_field_id('title')); ?>" 
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>" 
                   type="text" 
                   value="<?php echo esc_attr($title); ?>">
        </p>
        <?php
    }
    
    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = (!empty($new_instance['title'])) ? sanitize_text_field($new_instance['title']) : '';
        return $instance;
    }
}