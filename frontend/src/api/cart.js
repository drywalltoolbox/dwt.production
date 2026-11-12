/**
 * WooCommerce Store API cart operations.
 *
 * On the same-origin storefront, WooCommerce's cookie-backed
 * session is the cart authority so a full-document handoff to the native
 * `/checkout/` page sees the exact same cart. Cart-Token is retained only for a
 * genuinely cross-origin headless client where cookie session continuity cannot
 * be used.
 */
import { API_BASE_URL } from './client.js';

const runtimeOrigin = typeof window !== 'undefined' ? window.location.origin : '';
const configuredStorePath = ( process.env.REACT_APP_STORE_API_BASE || '/wp-json/wc/store/v1' ).replace( /\/+$/, '' );
const CART_TOKEN_STORAGE_KEY = 'dtb:store-api-cart-token:v1';

const STORE_API_BASE = `${ API_BASE_URL.replace( /\/+$/, '' ) }${ configuredStorePath }`;

function originOf( value ) {
	if ( !value ) return '';
	try {
		return new URL( value, runtimeOrigin || undefined ).origin;
	} catch {
		return '';
	}
}

const apiOrigin = originOf( API_BASE_URL );
const USE_CART_TOKEN_SESSION = Boolean( runtimeOrigin && apiOrigin && apiOrigin !== runtimeOrigin );

let storeNonce = '';
let cartToken = '';

function readPersistedCartToken() {
	if ( typeof window === 'undefined' || !USE_CART_TOKEN_SESSION ) return '';
	try {
		return String( window.localStorage.getItem( CART_TOKEN_STORAGE_KEY ) || '' );
	} catch {
		return '';
	}
}

function persistCartToken( token = '' ) {
	cartToken = USE_CART_TOKEN_SESSION ? String( token || '' ) : '';
	if ( typeof window === 'undefined' ) return;
	try {
		if ( cartToken ) {
			window.localStorage.setItem( CART_TOKEN_STORAGE_KEY, cartToken );
		} else {
			window.localStorage.removeItem( CART_TOKEN_STORAGE_KEY );
		}
	} catch {
		// Browser storage is optional. Same-origin checkout does not depend on it.
	}
}

cartToken = readPersistedCartToken();
if ( !USE_CART_TOKEN_SESSION ) persistCartToken( '' );

/** Return the current Cart-Token only for cross-origin headless compatibility. */
export function getCartToken() {
	return USE_CART_TOKEN_SESSION ? ( cartToken || readPersistedCartToken() ) : '';
}

function updateStoreSessionHeaders( response ) {
	const nonce = response.headers.get( 'Nonce' ) || response.headers.get( 'X-WC-Store-API-Nonce' );
	if ( nonce ) storeNonce = nonce;

	if ( USE_CART_TOKEN_SESSION ) {
		const token = response.headers.get( 'Cart-Token' );
		if ( token ) persistCartToken( token );
	}
}

function mutationSessionHeaders() {
	if ( USE_CART_TOKEN_SESSION && cartToken ) return { 'Cart-Token': cartToken };
	return storeNonce ? { Nonce: storeNonce } : {};
}

async function storeFetch( path, options = {}, sessionRetry = false ) {
	const url = `${ STORE_API_BASE }${ path }`;
	const response = await fetch( url, {
		credentials: 'include',
		cache: 'no-store',
		...options,
		headers: {
			'Content-Type': 'application/json',
			...mutationSessionHeaders(),
			...( options.headers || {} ),
		},
	} );

	updateStoreSessionHeaders( response );
	if ( response.status === 401 ) {
		if ( sessionRetry ) throw new Error( `Store API 401: ${ url }` );
		storeNonce = '';
		if ( USE_CART_TOKEN_SESSION ) persistCartToken( '' );
		await initCart();
		return storeFetch( path, options, true );
	}
	if ( !response.ok ) {
		let message = `Store API error ${ response.status }: ${ url }`;
		let body = null;
		try {
			body = await response.json();
			if ( body?.message ) message = body.message;
		} catch { /* Response may not contain JSON. */ }
		const error = new Error( message );
		error.status = response.status;
		error.code = body?.code || '';
		error.cart = body?.data?.cart || null;
		throw error;
	}
	if ( response.status === 204 ) return null;
	return response.json();
}

export async function storeApiRequest( path, options = {} ) {
	return storeFetch( path, options );
}

export async function initCart() {
	const url = `${ STORE_API_BASE }/cart`;
	const response = await fetch( url, {
		credentials: 'include',
		cache: 'no-store',
		headers: {
			'Content-Type': 'application/json',
			...( USE_CART_TOKEN_SESSION && cartToken ? { 'Cart-Token': cartToken } : {} ),
		},
	} );
	updateStoreSessionHeaders( response );
	if ( !response.ok ) throw new Error( `Store API error ${ response.status }: ${ url }` );
	if ( response.status === 204 ) return null;
	return response.json();
}

export async function getCart() {
	return storeFetch( '/cart' );
}

export async function addToCart( productId, qty = 1, variation = [], extensions = {} ) {
	return storeFetch( '/cart/add-item', {
		method: 'POST',
		body: JSON.stringify( { id: productId, quantity: qty, variation, extensions } ),
	} );
}

export async function updateCartItem( key, qty ) {
	const quantity = Math.max( 1, Math.floor( Number( qty ) || 1 ) );
	const payload = await storeFetch( '/cart/update-item', {
		method: 'POST',
		body: JSON.stringify( { key, quantity } ),
	} );
	return payload?.items ? payload : getCart();
}

export async function removeCartItem( key ) {
	const payload = await storeFetch( '/cart/remove-item', {
		method: 'POST',
		body: JSON.stringify( { key } ),
	} );
	return payload?.items ? payload : getCart();
}

export async function applyCoupon( code ) {
	return storeFetch( '/cart/coupons', {
		method: 'POST',
		body: JSON.stringify( { code } ),
	} );
}

export async function removeCoupon( code ) {
	return storeFetch( `/cart/coupons/${ encodeURIComponent( code ) }`, { method: 'DELETE' } );
}

export async function updateCartCustomer( customerData ) {
	return storeFetch( '/cart/update-customer', {
		method: 'POST',
		body: JSON.stringify( customerData ),
	} );
}

export async function selectShippingRate( rateId, packageId = 0 ) {
	return storeFetch( '/cart/select-shipping-rate', {
		method: 'POST',
		body: JSON.stringify( { rate_id: rateId, package_id: packageId } ),
	} );
}

export async function clearStoreCart() {
	await storeFetch( '/cart/items/', { method: 'DELETE' } );
	return getCart();
}
