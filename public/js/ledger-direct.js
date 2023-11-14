(function ($) {
    'use strict';

    $(function () {
        const checkPaymentButton = $('#check-payment-button');
        checkPaymentButton.on("click", function () {
            location.reload();
        });
    });

})(jQuery);