<?php
/**
 * Plugin Name: Sistema de Alquileres WooCommerce
 * Description: Sistema completo de alquileres para productos de WooCommerce
 * Version: 1.0.0
 * Author: Bjnik Jhoset Cruz Tirado
 * Text Domain: wc-rental-system
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes del plugin
define('WC_RENTAL_VERSION', '1.0.0');
define('WC_RENTAL_PATH', plugin_dir_path(__FILE__));
define('WC_RENTAL_URL', plugin_dir_url(__FILE__));

/**
 * Clase principal del plugin
 */
class WC_Rental_System {
    
    private static $instance = null;
    
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
        // Verificar que WooCommerce esté activo
        add_action('plugins_loaded', array($this, 'check_woocommerce'));
        
        // Hooks de activación y desactivación
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Inicializar el plugin
        add_action('init', array($this, 'init'));
    }
    
    /**
     * Verificar que WooCommerce esté activo
     */
    public function check_woocommerce() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                ?>
                <div class="notice notice-error">
                    <p><?php _e('Sistema de Alquileres requiere WooCommerce para funcionar.', 'wc-rental-system'); ?></p>
                </div>
                <?php
            });
            return false;
        }
        return true;
    }
    
    /**
     * Activación del plugin - Crear tablas
     */
    public function activate() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tabla de reservas/alquileres
        $table_rentals = $wpdb->prefix . 'wc_rentals';
        $sql_rentals = "CREATE TABLE IF NOT EXISTS $table_rentals (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            order_item_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            variation_id bigint(20) unsigned DEFAULT 0,
            customer_id bigint(20) unsigned NOT NULL,
            start_date date NOT NULL,
            end_date date NOT NULL,
            return_date date DEFAULT NULL,
            rental_days int(11) NOT NULL,
            rental_price decimal(10,2) NOT NULL,
            deposit_amount decimal(10,2) DEFAULT 0,
            grace_period_days int(11) DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            notes text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY product_id (product_id),
            KEY variation_id (variation_id),
            KEY customer_id (customer_id),
            KEY start_date (start_date),
            KEY end_date (end_date),
            KEY status (status)
        ) $charset_collate;";
        
        // Tabla de disponibilidad/bloqueos
        $table_availability = $wpdb->prefix . 'wc_rental_availability';
        $sql_availability = "CREATE TABLE IF NOT EXISTS $table_availability (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            variation_id bigint(20) unsigned DEFAULT 0,
            blocked_date date NOT NULL,
            rental_id bigint(20) unsigned DEFAULT NULL,
            reason varchar(50) DEFAULT 'rental',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_block (product_id, variation_id, blocked_date),
            KEY product_id (product_id),
            KEY variation_id (variation_id),
            KEY blocked_date (blocked_date),
            KEY rental_id (rental_id)
        ) $charset_collate;";
        
        // Tabla de configuración de productos para alquiler
        $table_rental_products = $wpdb->prefix . 'wc_rental_product_settings';
        $sql_rental_products = "CREATE TABLE IF NOT EXISTS $table_rental_products (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            variation_id bigint(20) unsigned DEFAULT 0,
            is_rentable tinyint(1) DEFAULT 1,
            min_rental_days int(11) DEFAULT 1,
            max_rental_days int(11) DEFAULT 30,
            deposit_percentage decimal(5,2) DEFAULT 0,
            deposit_fixed decimal(10,2) DEFAULT 0,
            grace_period_days int(11) DEFAULT 1,
            buffer_days_before int(11) DEFAULT 0,
            buffer_days_after int(11) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_product (product_id, variation_id),
            KEY product_id (product_id),
            KEY variation_id (variation_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_rentals);
        dbDelta($sql_availability);
        dbDelta($sql_rental_products);
        
        // Añadir capacidades de usuario
        $this->add_capabilities();
        
        // Crear página de "Mi cuenta > Mis alquileres"
        $this->create_account_endpoint();
        
        // Limpiar rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Desactivación del plugin
     */
    public function deactivate() {
        // Limpiar rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Añadir capacidades de usuario
     */
    private function add_capabilities() {
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('manage_rentals');
            $role->add_cap('view_rentals');
            $role->add_cap('edit_rentals');
            $role->add_cap('delete_rentals');
        }
        
        $role = get_role('shop_manager');
        if ($role) {
            $role->add_cap('manage_rentals');
            $role->add_cap('view_rentals');
            $role->add_cap('edit_rentals');
        }
    }
    
    /**
     * Crear endpoint en Mi Cuenta
     */
    private function create_account_endpoint() {
        add_rewrite_endpoint('mis-alquileres', EP_ROOT | EP_PAGES);
    }
    
    /**
     * Inicialización del plugin
     */
    public function init() {
        // Cargar archivos de idioma
        load_plugin_textdomain('wc-rental-system', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        
        // Incluir archivos necesarios
        $this->includes();
        
        // Inicializar hooks
        $this->init_hooks();
    }
    
    /**
     * Incluir archivos del plugin
     */
    private function includes() {
        // Incluir clases principales
        if (is_admin()) {
            require_once WC_RENTAL_PATH . 'includes/admin/class-admin-menu.php';
            require_once WC_RENTAL_PATH . 'includes/admin/class-product-settings.php';
            require_once WC_RENTAL_PATH . 'includes/admin/class-rentals-list.php';
        }
        
        // Clases generales
        require_once WC_RENTAL_PATH . 'includes/class-rental-manager.php';
        require_once WC_RENTAL_PATH . 'includes/class-availability-checker.php';
        require_once WC_RENTAL_PATH . 'includes/class-price-calculator.php';
        require_once WC_RENTAL_PATH . 'includes/class-rental-cart.php';
        require_once WC_RENTAL_PATH . 'includes/class-rental-order.php';
        
        // Frontend
        require_once WC_RENTAL_PATH . 'includes/frontend/class-product-page.php';
        require_once WC_RENTAL_PATH . 'includes/frontend/class-my-account.php';
        require_once WC_RENTAL_PATH . 'includes/frontend/class-shop-filters.php';
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // Admin hooks
        if (is_admin()) {
            new WC_Rental_Admin_Menu();
            new WC_Rental_Product_Settings();
            new WC_Rental_Rentals_List();
        }
        
        // Frontend hooks
        new WC_Rental_Product_Page();
        new WC_Rental_My_Account();
        new WC_Rental_Shop_Filters();
        
        // Cart y Order hooks
        new WC_Rental_Cart();
        new WC_Rental_Order();
        
        // Ajax handlers
        add_action('wp_ajax_check_rental_availability', array($this, 'ajax_check_availability'));
        add_action('wp_ajax_nopriv_check_rental_availability', array($this, 'ajax_check_availability'));
        add_action('wp_ajax_calculate_rental_price', array($this, 'ajax_calculate_price'));
        add_action('wp_ajax_nopriv_calculate_rental_price', array($this, 'ajax_calculate_price'));
        
        // Estilos y scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    /**
     * Ajax: Verificar disponibilidad
     */
    public function ajax_check_availability() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'rental_nonce')) {
            wp_die('Error de seguridad');
        }
        
        $product_id = intval($_POST['product_id']);
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);
        
        $availability_checker = new WC_Rental_Availability_Checker();
        $is_available = $availability_checker->check_availability($product_id, $variation_id, $start_date, $end_date);
        
        wp_send_json(array(
            'available' => $is_available,
            'message' => $is_available ? __('Disponible', 'wc-rental-system') : __('No disponible para estas fechas', 'wc-rental-system')
        ));
    }
    
    /**
     * Ajax: Calcular precio
     */
    public function ajax_calculate_price() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'rental_nonce')) {
            wp_die('Error de seguridad');
        }
        
        $product_id = intval($_POST['product_id']);
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);
        
        $calculator = new WC_Rental_Price_Calculator();
        $price_data = $calculator->calculate($product_id, $variation_id, $start_date, $end_date);
        
        wp_send_json($price_data);
    }
    
    /**
     * Cargar assets del frontend
     */
    public function enqueue_frontend_assets() {
        if (is_product() || is_shop() || is_product_category()) {
            // jQuery UI para datepicker
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
            
            // Scripts propios
            wp_enqueue_script(
                'wc-rental-frontend',
                WC_RENTAL_URL . 'assets/js/frontend.js',
                array('jquery', 'jquery-ui-datepicker'),
                WC_RENTAL_VERSION,
                true
            );
            
            // Estilos propios
            wp_enqueue_style(
                'wc-rental-frontend',
                WC_RENTAL_URL . 'assets/css/frontend.css',
                array(),
                WC_RENTAL_VERSION
            );
            
            // Localización
            wp_localize_script('wc-rental-frontend', 'wc_rental', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('rental_nonce'),
                'i18n' => array(
                    'select_dates' => __('Selecciona las fechas', 'wc-rental-system'),
                    'calculating' => __('Calculando...', 'wc-rental-system'),
                    'checking' => __('Verificando disponibilidad...', 'wc-rental-system')
                )
            ));
        }
    }
    
    /**
     * Cargar assets del admin
     */
    public function enqueue_admin_assets($hook) {
        // Solo cargar en páginas relevantes
        if (strpos($hook, 'wc-rentals') !== false || $hook == 'post.php' || $hook == 'post-new.php') {
            // jQuery UI
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
            
            // Scripts propios
            wp_enqueue_script(
                'wc-rental-admin',
                WC_RENTAL_URL . 'assets/js/admin.js',
                array('jquery', 'jquery-ui-datepicker'),
                WC_RENTAL_VERSION,
                true
            );
            
            // Estilos propios
            wp_enqueue_style(
                'wc-rental-admin',
                WC_RENTAL_URL . 'assets/css/admin.css',
                array(),
                WC_RENTAL_VERSION
            );
        }
    }
}

// Inicializar el plugin
WC_Rental_System::get_instance();