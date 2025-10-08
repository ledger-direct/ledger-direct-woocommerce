import { sprintf, __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';
import React, { useState, useEffect } from 'react';

const settings = getSetting( 'ledger-direct_data', {} );

const defaultLabel = __(
    'LedgerDirect Payments',
    'woo-gutenberg-products-block'
);

const label = decodeEntities( settings.title ) || defaultLabel;

/**
 * Content component
 */
const Content = (props) => {
    const [selected, setSelected] = useState('xrp');

    const { eventRegistration, emitResponse } = props;
    const { onPaymentSetup } = eventRegistration;
    useEffect( () => {
        const unsubscribe = onPaymentSetup( async () => {
            return {
                type: emitResponse.responseTypes.SUCCESS,
                meta: {
                    paymentMethodData: {
                        ledger_direct_payment_type: selected
                    },
                },
            };
        } );
        // Unsubscribes when this component is unmounted.
        return () => {
            unsubscribe();
        };
    }, [
        emitResponse.responseTypes.ERROR,
        emitResponse.responseTypes.SUCCESS,
        onPaymentSetup,
        selected
    ] );

    return (
        <div id="ledger-direct-payment-methods-block">
            <h4>{__('Choose payment method', 'ledger-direct')}</h4>
            <label>
                <input
                    type="radio"
                    name="ledger_direct_payment_type"
                    value="xrp"
                    checked={selected === 'xrp'}
                    onChange={() => setSelected('xrp')}
                />
                {__('Pay with XRP', 'ledger-direct')}
            </label>
            <br />
            <label>
                <input
                    type="radio"
                    name="ledger_direct_payment_type"
                    value="rlusd"
                    checked={selected === 'rlusd'}
                    onChange={() => setSelected('rlusd')}
                />
                {__('Pay with RLUSD', 'ledger-direct')}
            </label>
        </div>
    );
};

/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = ( props ) => {
    const { PaymentMethodLabel } = props.components;
    return <PaymentMethodLabel text={ label } />;
};

/**
 * Ledger Direct payment method config object.
 */
const LedgerDirect = {
    name: "ledger-direct",
    label: <Label />,
    content: <Content />,
    edit: <Content />,
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports,
    },
    getPaymentMethodData: () => ({
        ledger_direct_payment_type: 'rlusd',
    }),
};

registerPaymentMethod( LedgerDirect );