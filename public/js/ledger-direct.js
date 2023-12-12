(function ($) {
    'use strict';

    $(function () {

        const destinationAccount = $('#destination-account');
        const destinationAccountCopy = destinationAccount.next().children().eq(0);
        const destinationAccountQrCode = destinationAccount.next().children().eq(1);
        const destinationTag = $('#destination-tag');
        const destinationTagCopy = destinationTag.next().children().eq(0);
        const destinationTagQrCode = destinationTag.next().children().eq(1);

        destinationAccountCopy.on('click', copyToClipboard.bind(this, destinationAccount));
        destinationTagCopy.on('click', copyToClipboard.bind(this, destinationTag));

        attachQrCodeTooltip(destinationAccountQrCode, destinationAccount.attr("data-value"));
        attachQrCodeTooltip(destinationTagQrCode, destinationTag.attr("data-value"));

        const checkPaymentButton = $('#check-payment-button');
        checkPaymentButton.on("click", function () {
            location.reload();
        });
    });

    function copyToClipboard(element, event) {
        navigator.clipboard.writeText(element.attr("data-value"))
    }

    function attachQrCodeTooltip(element, value) {
        element.tooltipster({
            theme: 'tooltipster-shadow',
            //contentAsHTML: true,
            content: $('<div id="qrcode" style="width: 256px; height: 260px;">' + value + '</div>'),
            trigger: 'click',
            maxwidth: 256,
            functionReady: function() {
                $('#qrcode').empty().qrcode({
                    width: 256,
                    height: 256,
                    text: value
                });
            }
        });
    }

})(jQuery);