/**
 * Canonical shipping constants for Drywall Toolbox.
 *
 * FREE_SHIP_THRESHOLD — minimum order subtotal (USD) for free Standard Ground
 *   shipping to the contiguous 48 US states. Alaska, Hawaii, and Canada are
 *   excluded and always charged at the actual carrier rate.
 *   Current launch policy: $50.
 *
 * ESTIMATED_SHIP_RATE — flat estimated shipping displayed in the cart summary
 *   before a real carrier quote is obtained at checkout.
 *
 * These constants are the single source of truth used by:
 *   - Cart.jsx          (order summary panel)
 *   - Checkout.jsx      (shipping rate display)
 *
 * The live WooCommerce shipping zone configuration and FAQ copy must also match
 * FREE_SHIP_THRESHOLD.
 */

export const FREE_SHIP_THRESHOLD  = 50;
export const ESTIMATED_SHIP_RATE  = 15;
