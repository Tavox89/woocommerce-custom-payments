jQuery(document).ready(function($) {
    function initWCCP() {
        $(document).off('change', 'input[name="payment_method"]').on('change', 'input[name="payment_method"]', function() {
            var method = $(this).val();

            // Si el método seleccionado es nuestro gateway
            if (method === 'custom_payments_cvu') {

                // Verificamos si la API está activa
                if (wccp_params.use_api === 'yes') {
                    // API activa -> crear pedido provisional
                    $('#wccp_payment_info').slideDown().html('<p>' + wccp_params.texts.generating + '</p>');

                    $.ajax({
                        url: wccp_params.ajax_url,
                        type: 'POST',
                        data: { action: 'wccp_create_provisional_order' },
                        success: function(response) {
                            if (response.success) {
                                var cvu     = response.data.cvu;
                                var alias   = response.data.alias;
                                var days    = response.data.days;
                                var message = response.data.message;
                                var isBackup= response.data.is_backup;

                                var html = '<p><strong>' + wccp_params.texts.cvu_label + '</strong> ' + cvu + '<br>' +
                                           '<strong>' + wccp_params.texts.alias_label + '</strong> ' + alias + '<br>' +
                                           days + ' ' + wccp_params.texts.days_label + ' ' + wccp_params.texts.assigned + '</p>';

                                // Si se usó el respaldo
                                if (isBackup) {
                                    html += '<p style="color: red; font-weight: bold;">' + message + '</p>';
                                }

                                $('#wccp_payment_info').html(html);
                            } else {
                                $('#wccp_payment_info').html('<p>' + response.data + '</p>');
                            }
                        },
                        error: function() {
                            $('#wccp_payment_info').html('<p>' + wccp_params.texts.error_com + '</p>');
                        }
                    });

                } else {
                    // API desactivada -> no se hace AJAX
                    // Podemos mostrar un texto o nada
                    $('#wccp_payment_info').stop(true, true).slideDown().html(
                        '<p style="color: green; font-weight: bold;">' + 
                        'Usando CVU/Alias de respaldo.' + 
                        '</p>'
                    );
                }

            } else {
                // Si se cambia a otro método de pago, y existía un pedido provisional
                var infoDiv = $('#wccp_payment_info');
                if (infoDiv.is(':visible')) {
                    infoDiv.html('<p>' + wccp_params.texts.deleting + '</p>');
                }

                $.ajax({
                    url: wccp_params.ajax_url,
                    type: 'POST',
                    data: { action: 'wccp_delete_provisional_order' },
                    complete: function() {
                        infoDiv.slideUp();
                    }
                });
            }
        });

        // Disparar cambio inicial
        $('input[name="payment_method"]:checked').trigger('change');
    }

    initWCCP();

    // Cuando se actualiza el checkout por cambios de dirección, cupones, etc.
    $(document.body).on('updated_checkout', function() {
        initWCCP();
    });
});
