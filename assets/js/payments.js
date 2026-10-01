/**
 * EVM token payment on the WooCommerce order-pay page.
 *
 * Talks to the wallet directly over EIP-1193 (discovered via EIP-6963), so no
 * web3 library is needed. The server verifies the transfer on-chain; this
 * script only sends it and reports the transaction hash.
 */
( function () {
	'use strict';

	const cfg = window.evpPayment;
	const button = document.getElementById( 'evm-payment-button' );
	const notice = document.getElementById( 'evm-payment-notice' );
	const picker = document.getElementById( 'evm-wallet-picker' );
	const select = document.getElementById( 'evm-wallet-select' );

	if ( ! cfg || ! button ) {
		return;
	}

	const t = cfg.i18n;
	const TRANSFER_SELECTOR = '0xa9059cbb'; // transfer(address,uint256)
	const POLL_INTERVAL = 10000;
	const MAX_POLLS = 30;

	// --- Wallet discovery (EIP-6963, with window.ethereum fallback) --------

	const providers = [];

	window.addEventListener( 'eip6963:announceProvider', ( event ) => {
		const detail = event.detail;
		if ( ! detail || ! detail.info || providers.some( ( p ) => p.info.uuid === detail.info.uuid ) ) {
			return;
		}
		providers.push( detail );
		renderPicker();
	} );
	window.dispatchEvent( new Event( 'eip6963:requestProvider' ) );

	function renderPicker() {
		if ( ! picker || ! select || providers.length < 2 ) {
			return;
		}
		const current = select.value;
		select.replaceChildren(
			...providers.map( ( p ) => {
				const option = document.createElement( 'option' );
				option.value = p.info.uuid;
				option.textContent = p.info.name;
				return option;
			} )
		);
		if ( current ) {
			select.value = current;
		}
		picker.hidden = false;
	}

	function getProvider() {
		if ( providers.length ) {
			const chosen = select && providers.find( ( p ) => p.info.uuid === select.value );
			return ( chosen || providers[ 0 ] ).provider;
		}
		return window.ethereum || null;
	}

	// --- Helpers -------------------------------------------------------------

	function showNotice( message, type ) {
		notice.textContent = message;
		notice.className = type === 'error' ? 'woocommerce-error' : 'woocommerce-info';
	}

	function setBusy( busy, label ) {
		button.disabled = busy;
		button.textContent = label || ( busy ? t.processing : t.payButton );
	}

	function pad32( hex ) {
		return hex.replace( /^0x/, '' ).toLowerCase().padStart( 64, '0' );
	}

	function sleep( ms ) {
		return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
	}

	async function post( action, data ) {
		const body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'order_id', cfg.orderId );
		body.append( 'order_key', cfg.orderKey );
		Object.keys( data || {} ).forEach( ( key ) => body.append( key, data[ key ] ) );

		const response = await fetch( cfg.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' } );
		let json = null;
		try {
			json = await response.json();
		} catch ( e ) {
			// Fall through to the generic error below.
		}
		if ( ! json || ! json.success ) {
			throw new Error( ( json && json.data && json.data.message ) || t.genericError );
		}
		return json.data || {};
	}

	async function isOnChain( provider ) {
		const current = await provider.request( { method: 'eth_chainId' } );
		return String( current ).toLowerCase() === cfg.chainId.toLowerCase();
	}

	// EIP-3085/3326: 4902 means the wallet doesn't know the chain. MetaMask Mobile wraps it in -32603.
	function isUnknownChainError( error ) {
		if ( ! error ) {
			return false;
		}
		const nested = error.data && ( error.data.originalError || error.data );
		return error.code === 4902 || ( nested && nested.code === 4902 ) || /4902/.test( String( error.message ) );
	}

	async function ensureChain( provider ) {
		if ( await isOnChain( provider ) ) {
			return;
		}
		try {
			await provider.request( { method: 'wallet_switchEthereumChain', params: [ { chainId: cfg.chainId } ] } );
		} catch ( error ) {
			if ( ! isUnknownChainError( error ) ) {
				throw error;
			}
			if ( ! cfg.network ) {
				throw new Error( t.addNetwork );
			}
			showNotice( t.addingNetwork );
			await provider.request( {
				method: 'wallet_addEthereumChain',
				params: [
					{
						chainId: cfg.chainId,
						chainName: cfg.network.chainName,
						rpcUrls: [ cfg.network.rpcUrl ],
						nativeCurrency: { name: cfg.network.nativeSymbol, symbol: cfg.network.nativeSymbol, decimals: 18 },
						blockExplorerUrls: cfg.network.explorerUrl ? [ cfg.network.explorerUrl ] : undefined,
					},
				],
			} );
			// Most wallets switch after adding; if not, ask once more.
			if ( ! ( await isOnChain( provider ) ) ) {
				await provider.request( { method: 'wallet_switchEthereumChain', params: [ { chainId: cfg.chainId } ] } );
			}
		}
	}

	// --- Payment flow ------------------------------------------------------

	async function pay() {
		const provider = getProvider();
		if ( ! provider ) {
			showNotice( t.noWallet, 'error' );
			return;
		}

		setBusy( true );
		try {
			const accounts = await provider.request( { method: 'eth_requestAccounts' } );
			const from = accounts && accounts[ 0 ];
			if ( ! from ) {
				throw new Error( t.genericError );
			}

			await ensureChain( provider );

			// Bind the wallet to the order before sending, so the transfer can only be claimed by this order.
			await post( 'evp_prepare_payment', { wallet: from } );

			showNotice( t.confirmWallet );
			const txHash = await provider.request( {
				method: 'eth_sendTransaction',
				params: [
					{
						from,
						to: cfg.token,
						value: '0x0',
						data: TRANSFER_SELECTOR + pad32( cfg.recipient ) + pad32( cfg.amountHex ),
					},
				],
			} );

			showNotice( t.waiting );
			await waitForConfirmation( txHash );
		} catch ( error ) {
			setBusy( false );
			const message = error && error.code === 4001 ? t.rejected : ( error && error.message ) || t.genericError;
			showNotice( message, 'error' );
			// eslint-disable-next-line no-console
			console.error( 'EVM payment error:', error );
		}
	}

	async function waitForConfirmation( txHash ) {
		for ( let i = 0; i < MAX_POLLS; i++ ) {
			const result = await post( 'evp_submit_transaction', { tx: txHash } );
			if ( result.status === 'confirmed' ) {
				setBusy( true, t.paidButton );
				notice.textContent = '';
				showSuccessModal( txHash, result.redirect || cfg.receivedUrl );
				return;
			}
			await sleep( POLL_INTERVAL );
		}

		// Still pending: the server keeps checking in the background.
		setBusy( true, t.processing );
		showNotice( t.stillWaiting );
		const link = document.createElement( 'a' );
		link.href = cfg.receivedUrl;
		link.textContent = ' ' + t.viewOrder;
		notice.appendChild( link );
	}

	function showSuccessModal( txHash, redirectUrl ) {
		const el = ( tag, className, text ) => {
			const node = document.createElement( tag );
			if ( className ) {
				node.className = className;
			}
			if ( text ) {
				node.textContent = text;
			}
			return node;
		};

		const modal = el( 'div', 'evm-payment-modal' );
		modal.setAttribute( 'role', 'dialog' );
		modal.setAttribute( 'aria-modal', 'true' );

		const content = el( 'div', 'evm-payment-modal-content' );
		const title = el( 'h2', 'evm-payment-modal-title', t.successTitle );
		title.id = 'evm-payment-modal-title';
		modal.setAttribute( 'aria-labelledby', title.id );

		const txBox = el( 'div', 'evm-payment-modal-txid' );
		txBox.append( el( 'strong', null, t.transactionId ), el( 'div', null, txHash ) );

		const close = el( 'button', 'evm-payment-modal-button evm-payment-modal-button-close', t.close );
		close.type = 'button';
		close.addEventListener( 'click', () => modal.remove() );

		const view = el( 'button', 'evm-payment-modal-button evm-payment-modal-button-view', t.viewOrder );
		view.type = 'button';
		view.addEventListener( 'click', () => {
			window.location.href = redirectUrl;
		} );

		content.append(
			el( 'div', 'evm-payment-modal-icon', '✅' ),
			title,
			el( 'p', 'evm-payment-modal-message', t.successMessage ),
			txBox,
			close,
			view
		);
		modal.appendChild( content );
		document.body.appendChild( modal );
		view.focus();
	}

	button.addEventListener( 'click', pay );
} )();
