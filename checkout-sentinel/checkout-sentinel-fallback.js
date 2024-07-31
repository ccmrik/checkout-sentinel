jQuery(document).ready(function($) {
    $('form.checkout').on('checkout_place_order', function(event) {
        event.preventDefault();

        $.ajax({
            url: checkoutSentinelData.ajaxurl,
            type: 'POST',
            data: {
                action: 'checkout_sentinel_check'
            },
            success: function(response) {
                if (response.error) {
                    $('.woocommerce-error').remove();
                    $('form.checkout').prepend('<ul class="woocommerce-error"><li>' + response.message + '</li></ul>');
                    $('html, body').animate({
                        scrollTop: $('.woocommerce-error').offset().top - 100
                    }, 1000);
                } else {
                    $('form.checkout').off('checkout_place_order').submit();
                }
            }
        });

        return false;
    });
});