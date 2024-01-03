(function ($) {
    'use strict';

    function xrpToDrops(xrpToConvert) {
        const DROPS_PER_XRP = 1000000.0;
        const MAX_FRACTION_LENGTH = 6;
        const BASE_TEN = 10;
        const SANITY_CHECK = /^-?[0-9.]+$/u;

        const xrp = BigNumber(xrpToConvert).toString(BASE_TEN);

        // check that the value is valid and actually a number
        if (typeof xrpToConvert === 'string' && xrp === 'NaN') {
            throw new Error(
                `xrpToDrops: invalid value '${xrpToConvert}', should be a BigNumber or string-encoded number.`,
            )
        }

        /*
         * This should never happen; the value has already been
         * validated above. This just ensures BigNumber did not do
         * something unexpected.
         */
        if (!SANITY_CHECK.exec(xrp)) {
            throw new Error(
                `xrpToDrops: failed sanity check - value '${xrp}', does not match (^-?[0-9.]+$).`,
            );
        }

        const components = xrp.split('.')
        if (components.length > 2) {
            throw new Error(
                `xrpToDrops: failed sanity check - value '${xrp}' has too many decimal points.`,
            );
        }

        const fraction = components[1] || '0'
        if (fraction.length > MAX_FRACTION_LENGTH) {
            throw new Error(
                `xrpToDrops: value '${xrp}' has too many decimal places.`,
            );
        }

        return new BigNumber(xrp).times(DROPS_PER_XRP).integerValue(BigNumber.ROUND_FLOOR).toString(BASE_TEN);
    }

    /**
     * Entry point for the plugin.
     */
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
            checkPayment();
        });

        setTimeout(setupWallets, 1000);

    });

    function copyToClipboard(element, event) {
        navigator.clipboard.writeText(element.attr("data-value"))
    }

    function checkPayment() {
        location.reload();
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

    function setupWallets() {
        setupGemWallet();
        setupCrossmark();
    }

    function setupGemWallet() {
        const gemWalletButton = $('#gem-wallet-button');
        GemWalletApi.isInstalled().then((response) => {
            if (response.result.isInstalled) {
                gemWalletButton.removeClass('wallet-disabled');
                gemWalletButton.addClass('wallet-active');
                gemWalletButton.click('click', () => {
                    GemWalletApi.sendPayment(preparePaymentPayload()).then((response) => {
                        console.log(response.result?.hash)
                        checkPayment();
                    });
                });
            }
        });
    }

    function setupCrossmark() {
        if (window.xrpl.isCrossmark) {
            const CrossmarkSDK = window.xrpl.crossmark;
            const crossmarkWalletButton = $('#crossmark-wallet-button');
            crossmarkWalletButton.removeClass('wallet-disabled');
            crossmarkWalletButton.addClass('wallet-active');
            crossmarkWalletButton.click('click', () => {
                const paymentData = preparePaymentPayload();
                const transaction = {
                    TransactionType: 'Payment',
                    Account: CrossmarkSDK.sign({TransactionType: 'SignIn'}),
                    Destination: paymentData.destination,
                    DestinationTag: paymentData.destinationTag,
                    Amount: paymentData.amount
                }
                const response = CrossmarkSDK.signAndSubmit(transaction);
                console.log(response);
            });
        }
    }

    function preparePaymentPayload() {
        // XRP Payment
        try {
            const xrpAmount = $('#xrp-amount');
            const destinationAccount = $('#destination-account');
            const destinationTag = $('#destination-tag');

            const xrpPaymentData = {
                amount: parseFloat(xrpAmount.val()).toFixed(6),
                destination: destinationAccount.data('value'),
                destinationTag: parseInt(destinationTag.data('value'))
            }

            return {
                amount: xrpToDrops(xrpPaymentData.amount), // converted to drops
                destination: xrpPaymentData.destination,
                destinationTag: xrpPaymentData.destinationTag
            }
        } catch (error) {
            console.log(error)
        }

        // Token Payment
        try {
            const tokenAmount = $('#token-amount')
            const issuer = $('#issuer')
            const currency = $('#currency')

            return {
                amount: {
                    currency: currency.val(),
                    issuer: issuer.val(),
                    value: tokenAmount.val()
                },
                destination: destinationAccount.val(),
                destinationTag: parseInt(destinationTag.val())
            }
        } catch (error) {
            console.log(error)
        }
    }

})(jQuery);