/**
 * JavaScript para el frontend del sistema de alquileres
 * Archivo: assets/js/frontend.js
 */

(function($) {
    'use strict';

    // Objeto principal del sistema de alquileres
    var WCRentalSystem = {
        
        // Configuración
        config: {
            dateFormat: 'yy-mm-dd',
            minDate: 0,
            locale: 'es',
            currency: wc_rental_params.currency || '$',
            currencyPosition: wc_rental_params.currency_position || 'left'
        },
        
        // Datos del producto actual
        currentProduct: {
            id: null,
            variationId: null,
            basePrice: 0,
            minDays: 1,
            maxDays: 365,
            blockedDates: [],
            depositType: 'fixed',
            depositAmount: 0,
            gracePeriod: 0
        },
        
        // Fechas seleccionadas
        selectedDates: {
            start: null,
            end: null,
            days: 0
        },
        
        // Inicialización
        init: function() {
            this.bindEvents();
            this.initDatePickers();
            this.loadProductData();
            this.initAvailabilityCalendar();
            
            // Si hay variaciones, actualizar al cambiar
            if ($('.variations_form').length) {
                this.handleVariations();
            }
        },
        
        // Bind de eventos
        bindEvents: function() {
            var self = this;
            
            // Cambio de fechas
            $(document).on('change', '#rental_start_date, #rental_end_date', function() {
                self.calculateRentalPrice();
                self.checkAvailability();
            });
            
            // Botón añadir al carrito personalizado
            $(document).on('click', '.rental-add-to-cart', function(e) {
                e.preventDefault();
                self.addToCart($(this));
            });
            
            // Ver calendario de disponibilidad
            $(document).on('click', '.view-availability-calendar', function(e) {
                e.preventDefault();
                self.showAvailabilityModal();
            });
            
            // Limpiar fechas
            $(document).on('click', '.clear-dates', function(e) {
                e.preventDefault();
                self.clearDates();
            });
            
            // Aplicar descuento por duración
            $(document).on('change', '.rental-duration-discount', function() {
                self.calculateRentalPrice();
            });
        },
        
        // Inicializar selectores de fecha
        initDatePickers: function() {
            var self = this;
            
            if (!$('#rental_start_date').length) {
                return;
            }
            
            // Configurar datepicker para fecha de inicio
            $('#rental_start_date').datepicker({
                dateFormat: self.config.dateFormat,
                minDate: self.config.minDate,
                beforeShowDay: function(date) {
                    return self.isDateAvailable(date);
                },
                onSelect: function(dateText) {
                    self.selectedDates.start = dateText;
                    self.updateEndDatePicker();
                    self.calculateRentalPrice();
                }
            });
            
            // Configurar datepicker para fecha de fin
            $('#rental_end_date').datepicker({
                dateFormat: self.config.dateFormat,
                minDate: self.config.minDate,
                beforeShowDay: function(date) {
                    return self.isDateAvailable(date);
                },
                onSelect: function(dateText) {
                    self.selectedDates.end = dateText;
                    self.calculateRentalPrice();
                }
            });
            
            // Localización en español
            if (self.config.locale === 'es') {
                $.datepicker.regional['es'] = {
                    closeText: 'Cerrar',
                    prevText: '&#x3C;Ant',
                    nextText: 'Sig&#x3E;',
                    currentText: 'Hoy',
                    monthNames: ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                        'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'],
                    monthNamesShort: ['Ene','Feb','Mar','Abr','May','Jun',
                        'Jul','Ago','Sep','Oct','Nov','Dic'],
                    dayNames: ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'],
                    dayNamesShort: ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'],
                    dayNamesMin: ['Do','Lu','Ma','Mi','Ju','Vi','Sá'],
                    weekHeader: 'Sm',
                    dateFormat: 'dd/mm/yy',
                    firstDay: 1,
                    isRTL: false,
                    showMonthAfterYear: false,
                    yearSuffix: ''
                };
                $.datepicker.setDefaults($.datepicker.regional['es']);
            }
        },
        
        // Actualizar datepicker de fecha fin
        updateEndDatePicker: function() {
            if (!this.selectedDates.start) {
                return;
            }
            
            var startDate = new Date(this.selectedDates.start);
            var minEndDate = new Date(startDate);
            minEndDate.setDate(minEndDate.getDate() + this.currentProduct.minDays);
            
            var maxEndDate = new Date(startDate);
            maxEndDate.setDate(maxEndDate.getDate() + this.currentProduct.maxDays);
            
            $('#rental_end_date').datepicker('option', {
                minDate: minEndDate,
                maxDate: maxEndDate
            });
            
            // Habilitar el campo de fecha fin
            $('#rental_end_date').prop('disabled', false);
        },
        
        // Cargar datos del producto
        loadProductData: function() {
            var productData = $('.rental-product-data');
            
            if (!productData.length) {
                return;
            }
            
            this.currentProduct = {
                id: productData.data('product-id'),
                basePrice: parseFloat(productData.data('base-price')),
                minDays: parseInt(productData.data('min-days')) || 1,
                maxDays: parseInt(productData.data('max-days')) || 365,
                blockedDates: productData.data('blocked-dates') || [],
                depositType: productData.data('deposit-type') || 'fixed',
                depositAmount: parseFloat(productData.data('deposit-amount')) || 0,
                gracePeriod: parseInt(productData.data('grace-period')) || 0
            };
        },
        
        // Verificar si una fecha está disponible
        isDateAvailable: function(date) {
            var dateString = $.datepicker.formatDate('yy-mm-dd', date);
            
            // Verificar si está en las fechas bloqueadas
            if (this.currentProduct.blockedDates.indexOf(dateString) !== -1) {
                return [false, 'date-blocked', 'No disponible'];
            }
            
            // Verificar disponibilidad via AJAX si es necesario
            // Esto se puede optimizar con caché
            
            return [true, 'date-available', 'Disponible'];
        },
        
        // Calcular precio del alquiler
        calculateRentalPrice: function() {
            if (!this.selectedDates.start || !this.selectedDates.end) {
                this.updatePriceDisplay(0, 0, 0);
                return;
            }
            
            var startDate = new Date(this.selectedDates.start);
            var endDate = new Date(this.selectedDates.end);
            var days = Math.ceil((endDate - startDate) / (1000 * 60 * 60 * 24));
            
            if (days <= 0) {
                this.updatePriceDisplay(0, 0, 0);
                return;
            }
            
            this.selectedDates.days = days;
            
            // Calcular precio base
            var basePrice = this.currentProduct.basePrice;
            var rentalPrice = basePrice * days;
            
            // Aplicar descuentos por duración si existen
            var discount = this.getDiscountForDuration(days);
            if (discount > 0) {
                rentalPrice = rentalPrice * (1 - discount / 100);
            }
            
            // Calcular garantía
            var deposit = 0;
            if (this.currentProduct.depositType === 'percentage') {
                deposit = (rentalPrice * this.currentProduct.depositAmount) / 100;
            } else {
                deposit = this.currentProduct.depositAmount;
            }
            
            // Actualizar display
            this.updatePriceDisplay(rentalPrice, deposit, rentalPrice + deposit);
        },
        
        // Obtener descuento por duración
        getDiscountForDuration: function(days) {
            // Esto podría venir de la configuración del producto
            if (days >= 30) return 20;
            if (days >= 15) return 15;
            if (days >= 7) return 10;
            if (days >= 3) return 5;
            return 0;
        },
        
        // Actualizar display de precio
        updatePriceDisplay: function(rental, deposit, total) {
            var self = this;
            
            // Formatear precios
            var rentalFormatted = this.formatPrice(rental);
            var depositFormatted = this.formatPrice(deposit);
            var totalFormatted = this.formatPrice(total);
            
            // Actualizar elementos
            $('.rental-price-breakdown').html(
                '<div class="price-row">' +
                    '<span class="label">Alquiler (' + this.selectedDates.days + ' días):</span>' +
                    '<span class="value">' + rentalFormatted + '</span>' +
                '</div>' +
                (deposit > 0 ? 
                    '<div class="price-row">' +
                        '<span class="label">Garantía:</span>' +
                        '<span class="value">' + depositFormatted + '</span>' +
                    '</div>' : '') +
                '<div class="price-row total">' +
                    '<span class="label">Total:</span>' +
                    '<span class="value">' + totalFormatted + '</span>' +
                '</div>'
            );
            
            // Actualizar botón
            if (total > 0) {
                $('.rental-add-to-cart').text('Añadir al carrito - ' + totalFormatted);
                $('.rental-add-to-cart').prop('disabled', false);
            } else {
                $('.rental-add-to-cart').text('Selecciona las fechas');
                $('.rental-add-to-cart').prop('disabled', true);
            }
        },
        
        // Formatear precio
        formatPrice: function(price) {
            var formatted = price.toFixed(2);
            
            if (this.config.currencyPosition === 'left') {
                return this.config.currency + formatted;
            } else {
                return formatted + this.config.currency;
            }
        },
        
        // Verificar disponibilidad
        checkAvailability: function() {
            if (!this.selectedDates.start || !this.selectedDates.end) {
                return;
            }
            
            var self = this;
            var data = {
                action: 'check_rental_availability',
                product_id: this.currentProduct.id,
                variation_id: this.currentProduct.variationId || 0,
                start_date: this.selectedDates.start,
                end_date: this.selectedDates.end,
                nonce: wc_rental_params.nonce
            };
            
            // Mostrar loader
            $('.availability-status').html('<span class="checking">Verificando disponibilidad...</span>');
            
            $.ajax({
                url: wc_rental_params.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        if (response.data.available) {
                            $('.availability-status').html(
                                '<span class="available">✓ Disponible</span>'
                            );
                            $('.rental-add-to-cart').prop('disabled', false);
                        } else {
                            $('.availability-status').html(
                                '<span class="not-available">✗ No disponible</span>'
                            );
                            $('.rental-add-to-cart').prop('disabled', true);
                            
                            if (response.data.message) {
                                self.showNotification(response.data.message, 'error');
                            }
                        }
                    }
                },
                error: function() {
                    $('.availability-status').html(
                        '<span class="error">Error al verificar disponibilidad</span>'
                    );
                }
            });
        },
        
        // Añadir al carrito
        addToCart: function($button) {
            if (!this.selectedDates.start || !this.selectedDates.end) {
                this.showNotification('Por favor selecciona las fechas de alquiler', 'error');
                return;
            }
            
            var self = this;
            var data = {
                action: 'add_rental_to_cart',
                product_id: this.currentProduct.id,
                variation_id: this.currentProduct.variationId || 0,
                start_date: this.selectedDates.start,
                end_date: this.selectedDates.end,
                quantity: 1,
                nonce: wc_rental_params.nonce
            };
            
            // Deshabilitar botón y mostrar loader
            $button.prop('disabled', true);
            $button.addClass('loading');
            var originalText = $button.text();
            $button.text('Añadiendo...');
            
            $.ajax({
                url: wc_rental_params.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        // Actualizar fragmentos del carrito
                        if (response.fragments) {
                            $.each(response.fragments, function(key, value) {
                                $(key).replaceWith(value);
                            });
                        }
                        
                        // Mostrar notificación de éxito
                        self.showNotification('Producto añadido al carrito', 'success');
                        
                        // Limpiar fechas
                        self.clearDates();
                        
                        // Trigger evento de WooCommerce
                        $('body').trigger('added_to_cart', [response.fragments, response.cart_hash, $button]);
                    } else {
                        self.showNotification(response.data.message || 'Error al añadir al carrito', 'error');
                    }
                },
                error: function() {
                    self.showNotification('Error de conexión', 'error');
                },
                complete: function() {
                    $button.prop('disabled', false);
                    $button.removeClass('loading');
                    $button.text(originalText);
                }
            });
        },
        
        // Manejar variaciones
        handleVariations: function() {
            var self = this;
            
            $('.variations_form').on('found_variation', function(event, variation) {
                // Actualizar datos del producto con la variación
                self.currentProduct.variationId = variation.variation_id;
                self.currentProduct.basePrice = parseFloat(variation.display_price);
                
                // Si la variación tiene configuración específica
                if (variation.rental_min_days) {
                    self.currentProduct.minDays = parseInt(variation.rental_min_days);
                }
                if (variation.rental_max_days) {
                    self.currentProduct.maxDays = parseInt(variation.rental_max_days);
                }
                if (variation.rental_grace_period) {
                    self.currentProduct.gracePeriod = parseInt(variation.rental_grace_period);
                }
                
                // Recalcular precio si hay fechas seleccionadas
                if (self.selectedDates.start && self.selectedDates.end) {
                    self.calculateRentalPrice();
                    self.checkAvailability();
                }
            });
            
            $('.variations_form').on('reset_data', function() {
                // Resetear a los datos del producto principal
                self.loadProductData();
                self.clearDates();
            });
        },
        
        // Inicializar calendario de disponibilidad
        initAvailabilityCalendar: function() {
            if (!$('#availability-calendar').length) {
                return;
            }
            
            var self = this;
            
            $('#availability-calendar').fullCalendar({
                header: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'month,agendaWeek'
                },
                locale: 'es',
                events: function(start, end, timezone, callback) {
                    // Cargar eventos de disponibilidad
                    $.ajax({
                        url: wc_rental_params.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'get_product_availability',
                            product_id: self.currentProduct.id,
                            start: start.format(),
                            end: end.format(),
                            nonce: wc_rental_params.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                callback(response.data.events);
                            }
                        }
                    });
                },
                eventRender: function(event, element) {
                    if (event.available) {
                        element.addClass('available-date');
                    } else {
                        element.addClass('unavailable-date');
                    }
                },
                dayRender: function(date, cell) {
                    if (!self.isDateAvailable(date.toDate())[0]) {
                        cell.addClass('blocked-date');
                    }
                },
                selectable: true,
                selectHelper: true,
                select: function(start, end) {
                    // Establecer fechas seleccionadas
                    $('#rental_start_date').val(start.format('YYYY-MM-DD'));
                    $('#rental_end_date').val(end.subtract(1, 'days').format('YYYY-MM-DD'));
                    
                    self.selectedDates.start = start.format('YYYY-MM-DD');
                    self.selectedDates.end = end.format('YYYY-MM-DD');
                    
                    self.calculateRentalPrice();
                    self.checkAvailability();
                    
                    $('#availability-calendar').fullCalendar('unselect');
                }
            });
        },
        
        // Mostrar modal de disponibilidad
        showAvailabilityModal: function() {
            var self = this;
            
            // Crear modal si no existe
            if (!$('#availability-modal').length) {
                $('body').append(
                    '<div id="availability-modal" class="rental-modal">' +
                        '<div class="modal-content">' +
                            '<span class="close">&times;</span>' +
                            '<h2>Calendario de Disponibilidad</h2>' +
                            '<div id="modal-calendar"></div>' +
                        '</div>' +
                    '</div>'
                );
            }
            
            // Mostrar modal
            $('#availability-modal').fadeIn();
            
            // Inicializar calendario en el modal
            $('#modal-calendar').fullCalendar({
                // Misma configuración que el calendario principal
                defaultView: 'month',
                events: function(start, end, timezone, callback) {
                    $.ajax({
                        url: wc_rental_params.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'get_product_availability',
                            product_id: self.currentProduct.id,
                            start: start.format(),
                            end: end.format(),
                            nonce: wc_rental_params.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                callback(response.data.events);
                            }
                        }
                    });
                }
            });
            
            // Cerrar modal
            $('.close, #availability-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#availability-modal').fadeOut();
                }
            });
        },
        
        // Limpiar fechas
        clearDates: function() {
            $('#rental_start_date').val('');
            $('#rental_end_date').val('').prop('disabled', true);
            
            this.selectedDates = {
                start: null,
                end: null,
                days: 0
            };
            
            this.updatePriceDisplay(0, 0, 0);
            $('.availability-status').html('');
        },
        
        // Mostrar notificación
        showNotification: function(message, type) {
            var notificationClass = type === 'error' ? 'woocommerce-error' : 'woocommerce-message';
            
            var notification = $(
                '<div class="woocommerce-notices-wrapper">' +
                    '<div class="' + notificationClass + '">' +
                        message +
                        '<button type="button" class="notice-dismiss">×</button>' +
                    '</div>' +
                '</div>'
            );
            
            // Remover notificaciones anteriores
            $('.woocommerce-notices-wrapper').remove();
            
            // Añadir nueva notificación
            $('.rental-form-wrapper').before(notification);
            
            // Auto-ocultar después de 5 segundos
            setTimeout(function() {
                notification.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
            
            // Cerrar al hacer clic
            notification.find('.notice-dismiss').on('click', function() {
                notification.remove();
            });
        }
    };
    
    // Inicializar cuando el documento esté listo
    $(document).ready(function() {
        WCRentalSystem.init();
    });
    
    // Exponer el objeto para uso externo
    window.WCRentalSystem = WCRentalSystem;

})(jQuery);