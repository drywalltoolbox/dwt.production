/**
 * frontend/src/api/repairs.js
 *
 * Repair Services API client — wraps the dtb/v1/repairs/* endpoints.
 *
 * Public endpoints: submit, status, media upload via token.
 * Authenticated endpoints: customer repair history.
 *
 * Usage:
 *   import { submitRepair, getRepairStatus, getCustomerRepairs, REPAIR_STATUS_LABELS } from '@api/repairs';
 */

import { apiClient } from './client.js';
import { getToken } from '../auth/tokenStore.js';

// ─── Status labels (customer-facing) ─────────────────────────────────────────

/** Maps backend status strings to human-readable customer labels. */
export const REPAIR_STATUS_LABELS = {
  submitted:          'Submitted',
  reviewed:           'Under Review',
  awaiting_customer:  'Waiting on Customer',
  approved:           'Approved',
  quoted:             'Quote Sent',
  quote_accepted:     'Quote Accepted',
  quote_declined:     'Quote Declined',
  parts_allocated:    'Parts Allocated',
  in_progress:        'Repair In Progress',
  ready_to_ship:      'Ready to Ship',
  completed:          'Completed',
  closed:             'Closed',
  cancelled:          'Cancelled',
};

/** Maps status → approximate progress percentage for visual progress indicators. */
export const REPAIR_STATUS_PROGRESS = {
  submitted:          8,
  reviewed:           16,
  awaiting_customer:  20,
  approved:           28,
  quoted:             35,
  quote_accepted:     42,
  quote_declined:     100,
  parts_allocated:    55,
  in_progress:        70,
  ready_to_ship:      88,
  completed:          100,
  closed:             100,
  cancelled:          100,
};

/** Statuses after which polling/streaming should stop. */
export const TERMINAL_STATUSES = [
  'completed',
  'closed',
  'cancelled',
  'quote_declined',
];

// ─── Idempotency key helper ───────────────────────────────────────────────────

function generateIdempotencyKey() {
  if ( typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ) {
    return crypto.randomUUID();
  }
  // Fallback for environments without crypto.randomUUID
  return Array.from( { length: 32 }, () =>
    Math.floor( Math.random() * 16 ).toString( 16 )
  ).join( '' );
}

function getRuntimeFrontendBaseUrl() {
  if ( typeof window === 'undefined' ) return '';
  return window.location.origin;
}

// Keep this field-picking behavior aligned with
// wp/wp-content/mu-plugins/dtb-repair-service/Application/SubmitRepairRequest.php
// so both frontend submission paths normalize the same payload aliases.
function pickRepairField( payload, keys, fallback = '' ) {
  for ( const key of keys ) {
    if ( ! Object.prototype.hasOwnProperty.call( payload, key ) ) continue;
    const value = payload[ key ];
    if ( value === null || value === undefined ) continue;
    if ( typeof value === 'string' && value.trim() === '' ) continue;
    return value;
  }

  return fallback;
}

function normalizeRepairSubmitPayload( payload = {} ) {
  const shippingRatePrice = pickRepairField( payload, [ 'shipping_rate_price', 'shippingRatePrice' ], 0 );
  const shippingRatePriceNum = Number( shippingRatePrice );
  const preapprovalLimit = pickRepairField( payload, [ 'preapproval_limit', 'preapprovalLimit' ], '' );
  const preapprovalLimitNum = Number( preapprovalLimit );

  return {
    idempotency_key: pickRepairField( payload, [ 'idempotency_key', 'idempotencyKey' ], generateIdempotencyKey() ),
    frontend_base_url: pickRepairField( payload, [ 'frontend_base_url', 'frontendBaseUrl' ], getRuntimeFrontendBaseUrl() ),
    customer_name: pickRepairField( payload, [ 'customer_name', 'full_name', 'fullName' ] ),
    customer_email: pickRepairField( payload, [ 'customer_email', 'email' ] ),
    customer_phone: pickRepairField( payload, [ 'customer_phone', 'phone' ] ),
    company: pickRepairField( payload, [ 'company' ] ),
    item_type: pickRepairField( payload, [ 'item_type', 'tool_category', 'toolCategory', 'item_brand', 'tool_brand', 'toolBrand' ], 'Repair Service' ),
    item_brand: pickRepairField( payload, [ 'item_brand', 'tool_brand', 'toolBrand' ] ),
    item_model: pickRepairField( payload, [ 'item_model', 'tool_model', 'toolModel' ] ),
    serial_number: pickRepairField( payload, [ 'serial_number', 'tool_serial', 'serialNumber' ] ),
    tool_age: pickRepairField( payload, [ 'tool_age', 'toolAge' ] ),
    service_tier: pickRepairField( payload, [ 'service_tier', 'serviceType', 'pricingTierId' ] ),
    package_id: pickRepairField( payload, [ 'package_id', 'packageId', 'pricingTierId' ] ),
    approval_mode: pickRepairField( payload, [ 'approval_mode', 'approvalMode' ], 'quote_required' ),
    preapproval_limit: Number.isFinite( preapprovalLimitNum ) ? preapprovalLimitNum : '',
    warranty_requested: pickRepairField( payload, [ 'warranty_requested', 'warrantyRequested' ], 'no' ),
    purchase_date: pickRepairField( payload, [ 'purchase_date', 'purchaseDate' ] ),
    old_parts_return: pickRepairField( payload, [ 'old_parts_return', 'oldPartsReturn' ], 'discard' ),
    inbound_shipping_method: pickRepairField( payload, [ 'inbound_shipping_method', 'inboundShippingMethod' ], 'customer_pays_carrier' ),
    return_shipping_preference: pickRepairField( payload, [ 'return_shipping_preference', 'returnShippingPreference' ], 'standard' ),
    priority: pickRepairField( payload, [ 'priority' ] ),
    issue_start: pickRepairField( payload, [ 'issue_start', 'issueStart' ] ),
    description: pickRepairField( payload, [ 'description', 'issue', 'issueDescription' ] ),
    contact_preference: pickRepairField( payload, [ 'contact_preference', 'contactPreference' ], 'email' ),
    address: pickRepairField( payload, [ 'address', 'address_1' ] ),
    city: pickRepairField( payload, [ 'city' ] ),
    state: pickRepairField( payload, [ 'state', 'province' ] ),
    zip: pickRepairField( payload, [ 'zip', 'postcode', 'postal_code' ] ),
    country: pickRepairField( payload, [ 'country' ], 'US' ),
    shipping_rate_id: pickRepairField( payload, [ 'shipping_rate_id', 'shippingRateId' ] ),
    shipping_rate_name: pickRepairField( payload, [ 'shipping_rate_name', 'shippingRateName' ] ),
    shipping_rate_price: Number.isFinite( shippingRatePriceNum ) ? shippingRatePriceNum : 0,
    source: pickRepairField( payload, [ 'source' ], 'frontend_repair_form' ),
  };
}

function unwrapRepairResponse( response ) {
  return response?.data ?? response;
}

// ─── API functions ────────────────────────────────────────────────────────────

/**
 * Retrieve the authenticated customer's repair history.
 *
 * @param {number} [page=1]
 * @param {number} [perPage=20]
 * @returns {Promise<{ repairs: Array, page: number, per_page: number, has_more: boolean, total?: number }>}
 */
export async function getCustomerRepairs( page = 1, perPage = 20 ) {
  const params = new URLSearchParams( { page, per_page: perPage } ).toString();
  return apiClient( `/wp-json/dtb/v1/repairs?${ params }` );
}

/**
 * Submit a new repair request.
 * An idempotency key is automatically generated and merged into the payload.
 *
 * @param {Object} payload  Repair submission fields (see spec for full shape)
 * @returns {Promise<{ repair_id: number, public_token: string, status: string, message: string }>}
 */
export async function submitRepair( payload ) {
  return unwrapRepairResponse( await apiClient( '/wp-json/dtb/v1/repairs/submit', {
    method: 'POST',
    body: JSON.stringify( normalizeRepairSubmitPayload( payload ) ),
  } ) );
}

/**
 * Fetch a repair's current status snapshot by repair ID + public token.
 *
 * @param {number|string} repairId
 * @param {string}        token     Public token returned at submission time
 * @returns {Promise<Object>}       Status snapshot (see spec for full shape)
 */
export async function getRepairStatus( repairId, token ) {
  const params = token ? `?token=${ encodeURIComponent( token ) }` : '';
  return unwrapRepairResponse(
    await apiClient( `/wp-json/dtb/v1/repairs/status/${ encodeURIComponent( repairId ) }${ params }` )
  );
}

/**
 * Accept a pending repair quote.
 *
 * @param {number|string} repairId
 * @param {string}        token
 * @returns {Promise<Object>}
 */
export async function acceptRepairQuote( repairId, token ) {
  return unwrapRepairResponse( await apiClient( `/wp-json/dtb/v1/repairs/${ encodeURIComponent( repairId ) }/quote`, {
    method: 'POST',
    body: JSON.stringify( {
      token,
      action: 'accept',
    } ),
  } ) );
}

/**
 * Decline a pending repair quote.
 *
 * @param {number|string} repairId
 * @param {string}        token
 * @returns {Promise<Object>}
 */
export async function declineRepairQuote( repairId, token ) {
  return unwrapRepairResponse( await apiClient( `/wp-json/dtb/v1/repairs/${ encodeURIComponent( repairId ) }/quote`, {
    method: 'POST',
    body: JSON.stringify( {
      token,
      action: 'decline',
    } ),
  } ) );
}

/**
 * Submit a customer note/comment to the repair timeline.
 *
 * @param {number|string} repairId
 * @param {string}        token
 * @param {string}        comment
 * @returns {Promise<Object>}
 */
export async function submitRepairComment( repairId, token, comment ) {
  return await apiClient( `/wp-json/dtb/v1/repairs/${ encodeURIComponent( repairId ) }/comment`, {
    method: 'POST',
    body: JSON.stringify( {
      token,
      comment,
    } ),
  } );
}

/**
 * Upload media files for an existing repair.
 * Uses raw fetch (no JSON Content-Type) to send FormData.
 * Attaches a Bearer token — prefers the public repair token; falls back to
 * the in-memory auth token if available.
 *
 * @param {number|string} repairId
 * @param {FormData}      formData   Files under the key "files[]" (or as appended)
 * @param {string}        [token]    Public repair token for unauthenticated uploads
 * @returns {Promise<{ attachment_ids: number[] }>}
 */
export async function uploadRepairMedia( repairId, formData, token ) {
  const bearerToken = token || getToken();
  const headers = {};
  if ( bearerToken ) {
    headers[ 'Authorization' ] = `Bearer ${ bearerToken }`;
  }

  let response;
  try {
    response = await fetch(
      `/wp-json/dtb/v1/repairs/${ encodeURIComponent( repairId ) }/media`,
      { method: 'POST', headers, body: formData, credentials: 'include' }
    );
  } catch {
    throw { code: 'network_error', message: 'Network request failed.', status: 0 };
  }

  if ( ! response.ok ) {
    let envelope = {};
    try { envelope = await response.json(); } catch { /**/ }
    throw {
      code:    envelope.code    || 'upload_error',
      message: envelope.message || `Upload failed with status ${ response.status }.`,
      status:  response.status,
    };
  }

  return response.json();
}

/**
 * Returns the full SSE stream URL for a repair (used directly by EventSource).
 *
 * @param {number|string} repairId
 * @param {string}        token
 * @returns {string}  Absolute URL
 */
export function getRepairEventStreamUrl( repairId, token ) {
  const base = ( process.env.REACT_APP_WP_BASE_URL || '' ).replace( /\/+$/, '' );
  const wpJson = base
    ? ( base.endsWith( '/wp-json' ) ? base : `${ base }/wp-json` )
    : ( typeof window !== 'undefined' ? `${ window.location.origin }/wp-json` : '' );
  const params = token ? `?token=${ encodeURIComponent( token ) }` : '';
  return `${ wpJson }/dtb/v1/repairs/${ encodeURIComponent( repairId ) }/events/stream${ params }`;
}

/**
 * Health check for the repairs service.
 *
 * @returns {Promise<{ status: string }>}
 */
export async function getRepairHealthcheck() {
  return apiClient( '/wp-json/dtb/v1/repairs/health' );
}
