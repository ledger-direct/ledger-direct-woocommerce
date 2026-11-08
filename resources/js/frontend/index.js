import { __ } from '@wordpress/i18n';
import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';
import React, { useState, useEffect } from 'react';

const settings = getSetting( 'ledger-direct_data', {} );

const defaultLabel = __( 'LedgerDirect Payments', 'ledger-direct' );

const label = decodeEntities( settings.title ) || defaultLabel;
const icons = settings.icons || {};
const Icon = ({ asset }) => icons[asset] ? <img className="ld-method-icon" src={icons[asset]} alt="" width="40" height="24" /> : null;

/**
 * Content component
 */
const Content = (props) => {
    // The payment types the shop offers, in the order the server lists them; the first is preselected.
    const types = settings.payment_types || ['xrp'];
    const labels = {
        xrp: __('Pay with XRP', 'ledger-direct'),
        rlusd: __('Pay with RLUSD', 'ledger-direct'),
        usdc: __('Pay with USDC', 'ledger-direct'),
        xah: __('Pay with XAH (Xahau)', 'ledger-direct'),
    };
    const [selected, setSelected] = useState(types[0]);

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

            {types.map((type) => (
                <label key={type} style={{ display: 'block' }}>
                    <input
                        type="radio"
                        name="ledger_direct_payment_type"
                        value={type}
                        checked={selected === type}
                        onChange={() => setSelected(type)}
                    />
                    <Icon asset={type} /> {labels[type] || type.toUpperCase()}
                </label>
            ))}
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
};

registerPaymentMethod( LedgerDirect );