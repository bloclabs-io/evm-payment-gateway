/**
 * Registers the EVM token payment method with the WooCommerce Checkout block.
 * The actual wallet payment happens on the order-pay page after the order is placed.
 */
( function () {
	'use strict';

	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const settings = getSetting( 'evm_payment_data', {} );
	const title = decodeEntities( settings.title || '' );

	const Label = () =>
		createElement(
			'span',
			{ className: 'evm-payment-label' },
			settings.icon ? createElement( 'img', { src: settings.icon, alt: '', style: { marginRight: '8px', maxHeight: '24px' } } ) : null,
			title
		);

	const Content = () => createElement( 'div', null, decodeEntities( settings.description || '' ) );

	registerPaymentMethod( {
		name: 'evm_payment',
		label: createElement( Label ),
		ariaLabel: title,
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: () => true,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
