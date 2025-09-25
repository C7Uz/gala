/**
 * JavaScript para el admin del sistema de alquileres
 * Archivo: assets/js/admin.js
 */

(function($) {
    'use strict';

    // Objeto principal para el admin
    var WCRentalAdmin = {
        
        // Inicialización
        init: function() {
            this.bindEvents();
            this.initProductSettings();
            this.initReportsCharts();
            this.initBulkActions();
            this.initCalendarView();
        },
        
        // Bind de eventos generales
        bindEvents: function() {
            var self = this;
            
            // Toggle de configuración de alquiler
            $(document).on('change', '#_is_rental', function() {
                self.toggleRentalSettings($(this).is(':checked'));
            });
            
            // Tipo de garantía
            $(document).on('change', '#_rental_deposit_type', function() {
                self.updateDepositLabel($(this).val());
            });
            
            // Añadir fecha bloqueada
            $(document).on('click', '.add-blocked-date', function(e) {
                e.preventDefault();
                self.addBlockedDate();
            });
            
            // Eliminar fecha bloqueada
            $(document).on('click', '.remove-blocked-date', function(e) {
                e.preventDefault();
                $(this).closest('.blocked-date-row').remove();
            });
            
            // Exportar reportes
            $(document).on('click', '.export-rental-report', function(e) {
                e.preventDefault();
                self.exportReport($(this).data('type'));
            });
            
            // Filtros de lista de alquileres
            $(document).on('change', '#rental-status-filter, #rental-date-filter', function() {
                self.filterRentalList();
            });
            
            // Acciones masivas
            $(document).on('click', '#do-rental-bulk-action', function(e) {
                e.preventDefault();
                self.processBulkAction();
            });
        },
        
        // Inicializar configuración de productos
        initProductSettings: function() {
            // Si estamos en la página de producto
            if ($('#woocommerce-product-data').length) {
                // Verificar si es producto de alquiler al cargar
                if ($('#_is_rental').is(':checked')) {
                    this.toggleRentalSettings(true);
                }
                
                // Inicializar datepickers para fechas bloqueadas
                this.initBlockedDatesCalendar();
                
                // Configuración de variaciones
                this.handleVariationSettings();
            }
        },
        
        // Toggle de configuración de alquiler
        toggleRentalSettings: function(show) {
            if (show) {
                $('.rental_options').show();
                $('.show_if_rental').show();
                $('.hide_if_rental').hide();
                
                // Deshabilitar gestión de stock regular
                $('#_manage_stock').prop('checked', false).trigger('change');
            } else {
                $('.rental_options').hide();
                $('.show_if_rental').hide();
                $('.hide_if_rental').show();
            }
        },
        
        // Actualizar etiqueta de garantía
        updateDepositLabel: function(type) {
            if (type === 'percentage') {
                $('#deposit-amount-label').text('Porcentaje de garantía (%)');
                $('#_rental_deposit_amount').attr('placeholder', '20');
                $('#_rental_deposit_amount').attr('max', '100');
            } else {
                $('#deposit-amount-label').text('Monto de garantía fijo');
                $('#_rental_deposit_amount').attr('placeholder', '100.00');
                $('#_rental_deposit_amount').removeAttr('max');
            }
        },
        
        // Inicializar calendario de fechas bloqueadas
        initBlockedDatesCalendar: function() {
            $('.blocked-date-input').datepicker({
                dateFormat: 'yy-mm-dd',
                minDate: 0
            });
            
            // Selector de rango de fechas
            $('#block-date-range').on('click', function(e) {
                e.preventDefault();
                $('#date-range-modal').dialog({
                    modal: true,
                    width: 400,
                    buttons: {
                        "Bloquear Rango": function() {
                            var startDate = $('#range-start-date').val();
                            var endDate = $('#range-end-date').val();
                            
                            if (startDate && endDate) {
                                WCRentalAdmin.blockDateRange(startDate, endDate);
                                $(this).dialog("close");
                            }
                        },
                        "Cancelar": function() {
                            $(this).dialog("close");
                        }
                    }
                });
            });
        },
        
        // Añadir fecha bloqueada
        addBlockedDate: function() {
            var dateInput = $('#new-blocked-date');
            var date = dateInput.val();
            
            if (!date) {
                alert('Por favor selecciona una fecha');
                return;
            }
            
            // Verificar si la fecha ya está bloqueada
            var exists = false;
            $('.blocked-date-value').each(function() {
                if ($(this).val() === date) {
                    exists = true;
                    return false;
                }
            });
            
            if (exists) {
                alert('Esta fecha ya está bloqueada');
                return;
            }
            
            // Añadir nueva fila
            var row = '<div class="blocked-date-row">' +
                '<input type="hidden" name="_blocked_dates[]" class="blocked-date-value" value="' + date + '">' +
                '<span class="date-display">' + this.formatDate(date) + '</span>' +
                '<button type="button" class="button remove-blocked-date">Eliminar</button>' +
                '</div>';
            
            $('#blocked-dates-list').append(row);
            dateInput.val('');
        },
        
        // Bloquear rango de fechas
        blockDateRange: function(startDate, endDate) {
            var start = new Date(startDate);
            var end = new Date(endDate);
            var current = new Date(start);
            
            while (current <= end) {
                var dateString = this.formatDateISO(current);
                
                // Verificar si no existe
                var exists = false;
                $('.blocked-date-value').each(function() {
                    if ($(this).val() === dateString) {
                        exists = true;
                        return false;
                    }
                });
                
                if (!exists) {
                    var row = '<div class="blocked-date-row">' +
                        '<input type="hidden" name="_blocked_dates[]" class="blocked-date-value" value="' + dateString + '">' +
                        '<span class="date-display">' + this.formatDate(dateString) + '</span>' +
                        '<button type="button" class="button remove-blocked-date">Eliminar</button>' +
                        '</div>';
                    
                    $('#blocked-dates-list').append(row);
                }
                
                current.setDate(current.getDate() + 1);
            }
        },
        
        // Manejar configuración de variaciones
        handleVariationSettings: function() {
            // Al expandir una variación
            $('#variable_product_options').on('woocommerce_variations_loaded', function() {
                // Añadir campos de alquiler a cada variación
                $('.woocommerce_variation').each(function() {
                    var variationId = $(this).find('h3 .remove_variation').attr('rel');
                    
                    if (!$(this).find('.rental-variation-settings').length) {
                        WCRentalAdmin.addVariationRentalFields($(this), variationId);
                    }
                });
            });
        },
        
        // Añadir campos de alquiler a variación
        addVariationRentalFields: function($variation, variationId) {
            var fields = '<div class="rental-variation-settings">' +
                '<h4>Configuración de Alquiler (Variación)</h4>' +
                '<p class="form-row">' +
                    '<label>Período de gracia específico (días)</label>' +
                    '<input type="number" name="variable_rental_grace_period[' + variationId + ']" min="0" placeholder="Heredar">' +
                '</p>' +
                '<p class="form-row">' +
                    '<label>Días mínimos específicos</label>' +
                    '<input type="number" name="variable_rental_min_days[' + variationId + ']" min="1" placeholder="Heredar">' +
                '</p>' +
                '<p class="form-row">' +
                    '<label>Días máximos específicos</label>' +
                    '<input type="number" name="variable_rental_max_days[' + variationId + ']" min="1" placeholder="Heredar">' +
                '</p>' +
                '<p class="form-row">' +
                    '<label>Garantía específica</label>' +
                    '<input type="number" name="variable_rental_deposit[' + variationId + ']" min="0" step="0.01" placeholder="Heredar">' +
                '</p>' +
                '</div>';
            
            $variation.find('.woocommerce_variable_attributes').append(fields);
        },
        
        // Inicializar gráficos de reportes
        initReportsCharts: function() {
            // Si estamos en la página de reportes
            if ($('#rental-reports-dashboard').length) {
                this.loadDashboardData();
                this.initCharts();
            }
        },
        
        // Cargar datos del dashboard
        loadDashboardData: function() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'get_rental_dashboard_data',
                    nonce: wc_rental_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WCRentalAdmin.updateDashboard(response.data);
                    }
                }
            });
        },
        
        // Actualizar dashboard
        updateDashboard: function(data) {
            // Actualizar KPIs
            $('#active-rentals-count').text(data.active_rentals);
            $('#overdue-rentals-count').text(data.overdue_rentals);
            $('#total-revenue').text(data.total_revenue);
            $('#pending-deposits').text(data.pending_deposits);
            
            // Actualizar gráficos
            this.updateCharts(data.charts);
        },
        
        // Inicializar gráficos
        initCharts: function() {
            // Gráfico de alquileres por mes
            if ($('#rentals-by-month-chart').length) {
                this.rentalsChart = new Chart(document.getElementById('rentals-by-month-chart'), {
                    type: 'line',
                    data: {
                        labels: [],
                        datasets: [{
                            label: 'Alquileres',
                            data: [],
                            borderColor: 'rgb(75, 192, 192)',
                            tension: 0.1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'top',
                            },
                            title: {
                                display: true,
                                text: 'Alquileres por Mes'
                            }
                        }
                    }
                });
            }
            
            // Gráfico de productos más alquilados
            if ($('#top-products-chart').length) {
                this.productsChart = new Chart(document.getElementById('top-products-chart'), {
                    type: 'bar',
                    data: {
                        labels: [],
                        datasets: [{
                            label: 'Veces Alquilado',
                            data: [],
                            backgroundColor: 'rgba(54, 162, 235, 0.5)'
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'top',
                            },
                            title: {
                                display: true,
                                text: 'Productos Más Alquilados'
                            }
                        }
                    }
                });
            }
        },
        
        // Actualizar gráficos
        updateCharts: function(chartData) {
            if (this.rentalsChart && chartData.rentals_by_month) {
                this.rentalsChart.data.labels = chartData.rentals_by_month.labels;
                this.rentalsChart.data.datasets[0].data = chartData.rentals_by_month.data;
                this.rentalsChart.update();
            }
            
            if (this.productsChart && chartData.top_products) {
                this.productsChart.data.labels = chartData.top_products.labels;
                this.productsChart.data.datasets[0].data = chartData.top_products.data;
                this.productsChart.update();
            }
        },
        
        // Filtrar lista de alquileres
        filterRentalList: function() {
            var status = $('#rental-status-filter').val();
            var dateRange = $('#rental-date-filter').val();
            
            // Construir URL con parámetros
            var url = window.location.href.split('?')[0];
            var params = new URLSearchParams(window.location.search);
            
            if (status) {
                params.set('rental_status', status);
            } else {
                params.delete('rental_status');
            }
            
            if (dateRange) {
                params.set('date_range', dateRange);
            } else {
                params.delete('date_range');
            }
            
            // Recargar página con nuevos parámetros
            window.location.href = url + '?' + params.toString();
        },
        
        // Inicializar acciones masivas
        initBulkActions: function() {
            // Seleccionar todos
            $('#select-all-rentals').on('change', function() {
                $('.rental-checkbox').prop('checked', $(this).is(':checked'));
            });
        },
        
        // Procesar acción masiva
        processBulkAction: function() {
            var action = $('#bulk-action-selector').val();
            var selected = [];
            
            $('.rental-checkbox:checked').each(function() {
                selected.push($(this).val());
            });
            
            if (selected.length === 0) {
                alert('Por favor selecciona al menos un alquiler');
                return;
            }
            
            if (!action) {
                alert('Por favor selecciona una acción');
                return;
            }
            
            if (confirm('¿Confirmar acción para ' + selected.length + ' alquiler(es)?')) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'process_rental_bulk_action',
                        bulk_action: action,
                        rental_ids: selected,
                        nonce: wc_rental_admin.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    }
                });
            }
        },
        
        // Exportar reporte
        exportReport: function(type) {
            var params = {
                action: 'export_rental_report',
                report_type: type,
                nonce: wc_rental_admin.nonce
            };
            
            // Añadir filtros activos
            if ($('#rental-status-filter').length) {
                params.status = $('#rental-status-filter').val();
            }
            if ($('#rental-date-filter').length) {
                params.date_range = $('#rental-date-filter').val();
            }
            
            // Crear URL de descarga
            var url = ajaxurl + '?' + $.param(params);
            
            // Abrir en nueva ventana para descarga
            window.open(url, '_blank');
        },
        
        // Inicializar vista de calendario
        initCalendarView: function() {
            if (!$('#rental-calendar-view').length) {
                return;
            }
            
            $('#rental-calendar-view').fullCalendar({
                header: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'month,agendaWeek,listWeek'
                },
                locale: 'es',
                events: {
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_rental_calendar_events',
                        nonce: wc_rental_admin.nonce
                    },
                    error: function() {
                        alert('Error al cargar eventos del calendario');
                    }
                },
                eventClick: function(event) {
                    if (event.order_id) {
                        window.open('post.php?post=' + event.order_id + '&action=edit', '_blank');
                    }
                },
                eventRender: function(event, element) {
                    // Añadir tooltip con información adicional
                    element.attr('title', 
                        'Cliente: ' + event.customer_name + '\n' +
                        'Producto: ' + event.product_name + '\n' +
                        'Estado: ' + event.status
                    );
                    
                    // Color según estado
                    if (event.status === 'overdue') {
                        element.css('background-color', '#f44336');
                    } else if (event.status === 'returned') {
                        element.css('background-color', '#4CAF50');
                    }
                }
            });
        },
        
        // Utilidades
        formatDate: function(dateString) {
            var date = new Date(dateString);
            var options = { year: 'numeric', month: 'long', day: 'numeric' };
            return date.toLocaleDateString('es-ES', options);
        },
        
        formatDateISO: function(date) {
            var year = date.getFullYear();
            var month = String(date.getMonth() + 1).padStart(2, '0');
            var day = String(date.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        }
    };
    
    // Inicializar cuando el documento esté listo
    $(document).ready(function() {
        WCRentalAdmin.init();
    });
    
    // Exponer objeto para uso externo
    window.WCRentalAdmin = WCRentalAdmin;

})(jQuery);