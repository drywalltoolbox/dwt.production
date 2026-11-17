# Storefront shipping cutoff display

The React PDP reads `GET /wp-json/dtb/v1/storefront/shipping-cutoff` to render a live, informational countdown. The response contains the WordPress site time zone, current server time in UTC, the next 2:00 PM cutoff in UTC, and whether the current cutoff is still open for same-day dispatch.

The endpoint is public and read-only because this data contains no customer or order state. Responses must not be cached. The frontend synchronizes its display clock to the returned server time, ticks once per second, and requests a fresh cutoff when the current same-day window closes.

The endpoint is presentation context only. WooCommerce remains authoritative for cart, checkout, prices, shipping methods, shipping charges, and delivery estimates. Fulfillment and shipment state remain owned by the configured fulfillment system. The PDP must not present this countdown as a delivery promise or as confirmation that an order has been dispatched.

Current schedule: 2:00 PM in the configured WordPress site time zone, Monday through Friday. Revisit the weekday rule if the operating schedule changes.
