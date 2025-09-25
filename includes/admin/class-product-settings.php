<?php
/**
 * Clase para manejar la configuración de productos de alquiler en el admin
 * Archivo: includes/admin/class-product-settings.php
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Rental_Product_Settings {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Añadir tab de alquiler en productos
        add_filter('woocommerce_product_data_tabs', array($this, 'add_rental_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'add_rental_panel'));
        
        // Guardar configuración de alquiler
        add_action('woocommerce_process_product_meta', array($this, 'save_rental_settings'));
        
        // Añadir campos en variaciones
        add_action('woocommerce_product_after_variable_attributes', array($this, 'add_variation_rental_fields'), 10, 3);
        add_action('woocommerce_save_product_variation', array($this, 'save_variation_rental_fields'), 10, 2);
        
        // Añadir columna en listado de productos
        add_filter('manage_product_posts_columns', array($this, 'add_rental_column'));
        add_action('manage_product_posts_custom_column', array($this, 'render_rental_column'), 10, 2);
        
        // Añadir filtro rápido para productos en alquiler
        add_action('restrict_manage_posts', array($this, 'add_rental_filter'));
        add_filter('parse_query', array($this, 'filter_rental_products'));
    }
    
    /**
     * Añadir tab de configuración de alquiler
     */
    public function add_rental_tab($tabs) {
        $tabs['rental'] = array(
            'label'    => __('Configuración de Alquiler', 'wc-rental-system'),
            'target'   => 'rental_product_data',
            'class'    => array('show_if_simple', 'show_if_variable'),
            'priority' => 21,
        );
        return $tabs;
    }
    
    /**
     * Añadir panel de configuración de alquiler
     */
    public function add_rental_panel() {
        global $post;
        
        // Obtener configuración actual
        $product_id = $post->ID;
        $rental_settings = $this->get_rental_settings($product_id);
        ?>
        
        <div id="rental_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <h3 style="padding-left: 10px;"><?php _e('Configuración General de Alquiler', 'wc-rental-system'); ?></h3>
                
                <?php
                // Producto disponible para alquiler
                woocommerce_wp_checkbox(array(
                    'id'          => '_is_rentable',
                    'label'       => __('Producto en alquiler', 'wc-rental-system'),
                    'description' => __('Marcar si este producto está disponible para alquiler', 'wc-rental-system'),
                    'value'       => $rental_settings['is_rentable'] ? 'yes' : 'no',
                ));
                
                // Días mínimos de alquiler
                woocommerce_wp_text_input(array(
                    'id'          => '_min_rental_days',
                    'label'       => __('Días mínimos de alquiler', 'wc-rental-system'),
                    'type'        => 'number',
                    'custom_attributes' => array(
                        'min'  => '1',
                        'step' => '1',
                    ),
                    'value'       => $rental_settings['min_rental_days'],
                    'description' => __('Número mínimo de días que se puede alquilar', 'wc-rental-system'),
                ));
                
                // Días máximos de alquiler
                woocommerce_wp_text_input(array(
                    'id'          => '_max_rental_days',
                    'label'       => __('Días máximos de alquiler', 'wc-rental-system'),
                    'type'        => 'number',
                    'custom_attributes' => array(
                        'min'  => '1',
                        'step' => '1',
                    ),
                    'value'       => $rental_settings['max_rental_days'],
                    'description' => __('Número máximo de días que se puede alquilar', 'wc-rental-system'),
                ));
                ?>
            </div>
            
            <div class="options_group">
                <h3 style="padding-left: 10px;"><?php _e('Configuración de Garantía/Depósito', 'wc-rental-system'); ?></h3>
                
                <?php
                // Tipo de depósito
                woocommerce_wp_select(array(
                    'id'          => '_deposit_type',
                    'label'       => __('Tipo de garantía', 'wc-rental-system'),
                    'options'     => array(
                        'none'       => __('Sin garantía', 'wc-rental-system'),
                        'percentage' => __('Porcentaje del precio', 'wc-rental-system'),
                        'fixed'      => __('Monto fijo', 'wc-rental-system'),
                    ),
                    'value'       => $rental_settings['deposit_type'],
                    'description' => __('Tipo de garantía requerida para el alquiler', 'wc-rental-system'),
                ));
                
                // Porcentaje de depósito
                woocommerce_wp_text_input(array(
                    'id'          => '_deposit_percentage',
                    'label'       => __('Porcentaje de garantía (%)', 'wc-rental-system'),
                    'type'        => 'number',
                    'custom_attributes' => array(
                        'min'  => '0',
                        'max'  => '100',
                        'step' => '0.01',
                    ),
                    'value'       => $rental_settings['deposit_percentage'],
                    'description' => __('Porcentaje del precio total como garantía', 'wc-rental-system'),
                    'wrapper_class' => 'show_if_deposit_percentage',
                ));
                
                // Monto fijo de depósito
                woocommerce_wp_text_input(array(
                    'id'          => '_deposit_fixed',
                    'label'       => __('Monto fijo de garantía', 'wc-rental-system') . ' (' . get_woocommerce_currency_symbol() . ')',
                    'type'        => 'number',
                    'custom_attributes' => array(
                        'min'  => '0',
                        'step' => '0.01',
                    ),
                    'value'       => $rental_settings['deposit_fixed'],
                    'description' => __('Monto fijo como garantía', 'wc-rental-system'),
                    'wrapper_class' => 'show_if_deposit_fixed',
                ));
                ?>
            </div>
            
            <div class="options_group">
                <h3 style="padding-left: 10px;"><?php _e('Períodos de Gracia y Buffer', 'wc-rental-system'); ?></h3>
                
                <?php
                // Período de gracia (limpieza)
                woocommerce_wp_text_input(array(
                    'id'          => '_grace_period_days',
                    'label'       => __('Período de gracia (días)', 'wc-rental-system'),
                    'type'        => 'number',
                    'custom_attributes' => array(
                        'min'  => '0',
                        'step' => '1',
                    ),
                    'value'       => $rental_settings['grace_period_days'],
                    'description' => __('Días de gracia después de la devolución para limpieza/mantenimiento', 'wc-rental-system'),
                ));
                
                // Días de buffer antes
                woocommerce_wp_text_input(array(
                    'id'          => '_buffer_days_before',
                    'label'       => __('Días de preparación', 'wc-rental-system'),
                    'type'        => 'number',
                    'custom_attributes' => array(
                        'min'  => '0',
                        'step' => '1',
                    ),
                    'value'       => $rental_settings['buffer_days_before'],
                    'description' => __('Días necesarios antes del alquiler para preparar el producto', 'wc-rental-system'),
                ));
                
                // Días de buffer después
                woocommerce_wp_text_input(array(
                    'id'          => '_buffer_days_after',
                    'label'       => __('Días post-devolución', 'wc-rental-system'),
                    'type'        => 'number',
                    'custom_attributes' => array(
                        'min'  => '0',
                        'step' => '1',
                    ),
                    'value'       => $rental_settings['buffer_days_after'],
                    'description' => __('Días bloqueados después de la devolución (incluye período de gracia)', 'wc-rental-system'),
                ));
                ?>
            </div>
            
            <div class="options_group">
                <h3 style="padding-left: 10px;"><?php _e('Calendario de Disponibilidad', 'wc-rental-system'); ?></h3>
                
                <?php
                // Bloquear días específicos
                woocommerce_wp_textarea_input(array(
                    'id'          => '_blocked_dates',
                    'label'       => __('Fechas bloqueadas', 'wc-rental-system'),
                    'placeholder' => __('DD/MM/AAAA, DD/MM/AAAA', 'wc-rental-system'),
                    'value'       => $rental_settings['blocked_dates'],
                    'description' => __('Fechas específicas no disponibles para alquiler (separadas por comas)', 'wc-rental-system'),
                ));
                
                // Días de la semana disponibles
                ?>
                <p class="form-field">
                    <label><?php _e('Días disponibles', 'wc-rental-system'); ?></label>
                    <span class="rental-weekdays">
                        <?php
                        $weekdays = array(
                            'monday'    => __('Lunes', 'wc-rental-system'),
                            'tuesday'   => __('Martes', 'wc-rental-system'),
                            'wednesday' => __('Miércoles', 'wc-rental-system'),
                            'thursday'  => __('Jueves', 'wc-rental-system'),
                            'friday'    => __('Viernes', 'wc-rental-system'),
                            'saturday'  => __('Sábado', 'wc-rental-system'),
                            'sunday'    => __('Domingo', 'wc-rental-system'),
                        );
                        
                        $available_days = !empty($rental_settings['available_days']) ? $rental_settings['available_days'] : array_keys($weekdays);
                        
                        foreach ($weekdays as $day => $label) {
                            $checked = in_array($day, $available_days) ? 'checked' : '';
                            echo '<label style="display: inline-block; margin-right: 10px;">';
                            echo '<input type="checkbox" name="_available_days[]" value="' . esc_attr($day) . '" ' . $checked . '> ';
                            echo esc_html($label);
                            echo '</label>';
                        }
                        ?>
                    </span>
                    <span class="description"><?php _e('Días de la semana en que se puede iniciar un alquiler', 'wc-rental-system'); ?></span>
                </p>
                <?php
                ?>
            </div>
            
            <!-- Script para mostrar/ocultar campos según el tipo de depósito -->
            <script type="text/javascript">
                jQuery(document).ready(function($) {
                    function toggleDepositFields() {
                        var depositType = $('#_deposit_type').val();
                        $('.show_if_deposit_percentage').hide();
                        $('.show_if_deposit_fixed').hide();
                        
                        if (depositType === 'percentage') {
                            $('.show_if_deposit_percentage').show();
                        } else if (depositType === 'fixed') {
                            $('.show_if_deposit_fixed').show();
                        }
                    }
                    
                    $('#_deposit_type').change(toggleDepositFields);
                    toggleDepositFields();
                });
            </script>
        </div>
        <?php
    }
    
    /**
     * Guardar configuración de alquiler del producto
     */
    public function save_rental_settings($post_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wc_rental_product_settings';
        
        // Preparar datos
        $data = array(
            'product_id'          => $post_id,
            'variation_id'        => 0,
            'is_rentable'         => isset($_POST['_is_rentable']) && $_POST['_is_rentable'] === 'yes' ? 1 : 0,
            'min_rental_days'     => isset($_POST['_min_rental_days']) ? intval($_POST['_min_rental_days']) : 1,
            'max_rental_days'     => isset($_POST['_max_rental_days']) ? intval($_POST['_max_rental_days']) : 30,
            'grace_period_days'   => isset($_POST['_grace_period_days']) ? intval($_POST['_grace_period_days']) : 1,
            'buffer_days_before'  => isset($_POST['_buffer_days_before']) ? intval($_POST['_buffer_days_before']) : 0,
            'buffer_days_after'   => isset($_POST['_buffer_days_after']) ? intval($_POST['_buffer_days_after']) : 1,
        );
        
        // Manejar depósito según el tipo
        $deposit_type = isset($_POST['_deposit_type']) ? sanitize_text_field($_POST['_deposit_type']) : 'none';
        
        if ($deposit_type === 'percentage') {
            $data['deposit_percentage'] = isset($_POST['_deposit_percentage']) ? floatval($_POST['_deposit_percentage']) : 0;
            $data['deposit_fixed'] = 0;
        } elseif ($deposit_type === 'fixed') {
            $data['deposit_percentage'] = 0;
            $data['deposit_fixed'] = isset($_POST['_deposit_fixed']) ? floatval($_POST['_deposit_fixed']) : 0;
        } else {
            $data['deposit_percentage'] = 0;
            $data['deposit_fixed'] = 0;
        }
        
        // Verificar si ya existe un registro
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE product_id = %d AND variation_id = 0",
            $post_id
        ));
        
        if ($existing) {
            // Actualizar
            $wpdb->update($table, $data, array('id' => $existing->id));
        } else {
            // Insertar
            $wpdb->insert($table, $data);
        }
        
        // Guardar meta adicional
        update_post_meta($post_id, '_deposit_type', $deposit_type);
        update_post_meta($post_id, '_blocked_dates', sanitize_textarea_field($_POST['_blocked_dates'] ?? ''));
        update_post_meta($post_id, '_available_days', $_POST['_available_days'] ?? array());
        
        // Si es producto en alquiler, añadir etiqueta
        if ($data['is_rentable']) {
            wp_set_object_terms($post_id, 'producto-en-alquiler', 'product_tag', true);
        } else {
            wp_remove_object_terms($post_id, 'producto-en-alquiler', 'product_tag');
        }
    }
    
    /**
     * Añadir campos de alquiler en variaciones
     */
    public function add_variation_rental_fields($loop, $variation_data, $variation) {
        $variation_id = $variation->ID;
        $rental_settings = $this->get_rental_settings(0, $variation_id);
        ?>
        
        <div class="rental-variation-settings">
            <h4><?php _e('Configuración de Alquiler para esta Variación', 'wc-rental-system'); ?></h4>
            
            <?php
            // Override período de gracia para esta variación
            woocommerce_wp_text_input(array(
                'id'          => '_variation_grace_period[' . $loop . ']',
                'label'       => __('Período de gracia (días)', 'wc-rental-system'),
                'type'        => 'number',
                'custom_attributes' => array(
                    'min'  => '0',
                    'step' => '1',
                ),
                'value'       => get_post_meta($variation_id, '_variation_grace_period', true),
                'description' => __('Dejar vacío para usar configuración del producto padre', 'wc-rental-system'),
                'wrapper_class' => 'form-row form-row-first',
            ));
            
            // Override días mínimos para esta variación
            woocommerce_wp_text_input(array(
                'id'          => '_variation_min_rental_days[' . $loop . ']',
                'label'       => __('Días mínimos', 'wc-rental-system'),
                'type'        => 'number',
                'custom_attributes' => array(
                    'min'  => '1',
                    'step' => '1',
                ),
                'value'       => get_post_meta($variation_id, '_variation_min_rental_days', true),
                'description' => __('Dejar vacío para usar configuración del producto padre', 'wc-rental-system'),
                'wrapper_class' => 'form-row form-row-last',
            ));
            
            // Depósito específico para esta variación
            woocommerce_wp_text_input(array(
                'id'          => '_variation_deposit_fixed[' . $loop . ']',
                'label'       => __('Garantía fija', 'wc-rental-system') . ' (' . get_woocommerce_currency_symbol() . ')',
                'type'        => 'number',
                'custom_attributes' => array(
                    'min'  => '0',
                    'step' => '0.01',
                ),
                'value'       => get_post_meta($variation_id, '_variation_deposit_fixed', true),
                'description' => __('Dejar vacío para usar configuración del producto padre', 'wc-rental-system'),
                'wrapper_class' => 'form-row form-row-full',
            ));
            ?>
        </div>
        
        <?php
    }
    
    /**
     * Guardar campos de alquiler de variaciones
     */
    public function save_variation_rental_fields($variation_id, $loop) {
        global $wpdb;
        
        // Guardar meta de la variación
        $fields = array(
            '_variation_grace_period',
            '_variation_min_rental_days',
            '_variation_deposit_fixed',
        );
        
        foreach ($fields as $field) {
            $field_name = $field . '[' . $loop . ']';
            if (isset($_POST[$field_name])) {
                $value = sanitize_text_field($_POST[$field_name]);
                if (!empty($value)) {
                    update_post_meta($variation_id, $field, $value);
                } else {
                    delete_post_meta($variation_id, $field);
                }
            }
        }
        
        // Si hay configuración específica, guardar en la tabla
        if (!empty($_POST['_variation_grace_period'][$loop]) || 
            !empty($_POST['_variation_min_rental_days'][$loop]) || 
            !empty($_POST['_variation_deposit_fixed'][$loop])) {
            
            $table = $wpdb->prefix . 'wc_rental_product_settings';
            $parent_id = wp_get_post_parent_id($variation_id);
            
            // Obtener configuración del padre
            $parent_settings = $this->get_rental_settings($parent_id);
            
            $data = array(
                'product_id'          => $parent_id,
                'variation_id'        => $variation_id,
                'is_rentable'         => $parent_settings['is_rentable'],
                'min_rental_days'     => !empty($_POST['_variation_min_rental_days'][$loop]) ? 
                                        intval($_POST['_variation_min_rental_days'][$loop]) : 
                                        $parent_settings['min_rental_days'],
                'max_rental_days'     => $parent_settings['max_rental_days'],
                'grace_period_days'   => !empty($_POST['_variation_grace_period'][$loop]) ? 
                                        intval($_POST['_variation_grace_period'][$loop]) : 
                                        $parent_settings['grace_period_days'],
                'deposit_fixed'       => !empty($_POST['_variation_deposit_fixed'][$loop]) ? 
                                        floatval($_POST['_variation_deposit_fixed'][$loop]) : 
                                        $parent_settings['deposit_fixed'],
                'deposit_percentage'  => $parent_settings['deposit_percentage'],
                'buffer_days_before'  => $parent_settings['buffer_days_before'],
                'buffer_days_after'   => $parent_settings['buffer_days_after'],
            );
            
            // Verificar si ya existe
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $table WHERE product_id = %d AND variation_id = %d",
                $parent_id,
                $variation_id
            ));
            
            if ($existing) {
                $wpdb->update($table, $data, array('id' => $existing->id));
            } else {
                $wpdb->insert($table, $data);
            }
        }
    }
    
    /**
     * Obtener configuración de alquiler
     */
    public function get_rental_settings($product_id, $variation_id = 0) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'wc_rental_product_settings';
        
        // Buscar configuración
        $settings = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE product_id = %d AND variation_id = %d",
            $product_id,
            $variation_id
        ), ARRAY_A);
        
        // Si no existe, devolver valores por defecto
        if (!$settings) {
            $settings = array(
                'is_rentable'         => 0,
                'min_rental_days'     => 1,
                'max_rental_days'     => 30,
                'deposit_percentage'  => 0,
                'deposit_fixed'       => 0,
                'grace_period_days'   => 1,
                'buffer_days_before'  => 0,
                'buffer_days_after'   => 1,
            );
        }
        
        // Añadir meta adicional
        $settings['deposit_type'] = get_post_meta($product_id, '_deposit_type', true) ?: 'none';
        $settings['blocked_dates'] = get_post_meta($product_id, '_blocked_dates', true) ?: '';
        $settings['available_days'] = get_post_meta($product_id, '_available_days', true) ?: array();
        
        return $settings;
    }
    
    /**
     * Añadir columna de alquiler en listado de productos
     */
    public function add_rental_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            if ($key === 'product_tag') {
                $new_columns['is_rentable'] = __('Alquiler', 'wc-rental-system');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Renderizar columna de alquiler
     */
    public function render_rental_column($column, $post_id) {
        if ($column === 'is_rentable') {
            $settings = $this->get_rental_settings($post_id);
            
            if ($settings['is_rentable']) {
                echo '<span class="dashicons dashicons-yes" style="color: green;"></span>';
                echo '<br><small>';
                echo sprintf(__('Min: %d días', 'wc-rental-system'), $settings['min_rental_days']);
                echo '</small>';
            } else {
                echo '<span class="dashicons dashicons-minus" style="color: #999;"></span>';
            }
        }
    }
    
    /**
     * Añadir filtro de productos en alquiler
     */
    public function add_rental_filter() {
        global $typenow;
        
        if ($typenow === 'product') {
            $selected = isset($_GET['rental_filter']) ? $_GET['rental_filter'] : '';
            ?>
            <select name="rental_filter" id="rental_filter">
                <option value=""><?php _e('Todos los productos', 'wc-rental-system'); ?></option>
                <option value="rentable" <?php selected($selected, 'rentable'); ?>>
                    <?php _e('Solo productos en alquiler', 'wc-rental-system'); ?>
                </option>
                <option value="not_rentable" <?php selected($selected, 'not_rentable'); ?>>
                    <?php _e('Solo productos de venta', 'wc-rental-system'); ?>
                </option>
            </select>
            <?php
        }
    }
    
    /**
     * Filtrar productos por tipo de alquiler
     */
    public function filter_rental_products($query) {
        global $pagenow, $typenow, $wpdb;
        
        if ($pagenow === 'edit.php' && $typenow === 'product' && !empty($_GET['rental_filter'])) {
            $filter = $_GET['rental_filter'];
            $table = $wpdb->prefix . 'wc_rental_product_settings';
            
            if ($filter === 'rentable') {
                // Solo productos en alquiler
                $rental_ids = $wpdb->get_col(
                    "SELECT DISTINCT product_id FROM $table WHERE is_rentable = 1"
                );
                
                if (!empty($rental_ids)) {
                    $query->query_vars['post__in'] = $rental_ids;
                } else {
                    $query->query_vars['post__in'] = array(0); // No hay productos
                }
            } elseif ($filter === 'not_rentable') {
                // Excluir productos en alquiler
                $rental_ids = $wpdb->get_col(
                    "SELECT DISTINCT product_id FROM $table WHERE is_rentable = 1"
                );
                
                if (!empty($rental_ids)) {
                    $query->query_vars['post__not_in'] = $rental_ids;
                }
            }
        }
    }
}