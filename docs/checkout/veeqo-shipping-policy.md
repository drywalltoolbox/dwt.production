# DTB — Verified Veeqo checkout shipping contract

Owner: `dtb-commerce` shipping policy + `dtb-integrations/Veeqo` carrier quote adapter.
Runtime: WooCommerce native Checkout Block and Store API; DTB does **not** price shipping in React.

## Confirmed incident and root cause

Before this change the Veeqo Rate Shopping request sent only total product weight
as a dimensionless single parcel. It therefore permitted physically impossible
shipping choices, including document-only postage, USPS Cubic/Flat Rate, and
small-package pricing for professional full toolsets. Its cheapest-four selection
also hid paid expedited methods whenever cheap ground choices dominated.

The previous synthetic Express/Overnight rates ($10/$30 over the standard
merchant policy) were never carrier quotes. They must not appear as guaranteed
delivery options.

## Checkout decisions

- WooCommerce remains shipping/totals/order authority. Only the server shipping
  method may add rates to native Store API cart/checkout.
- Free Standard Shipping at a domestic merchandise subtotal of $50+ is a
  **DTB-funded merchant policy**, not a carrier quote. It is retained by this
  change, including for larger sets, unless business policy changes separately.
- Below $50, the existing standard weight-banded *merchant rate* remains.
  These prices are not a representation of carrier costs.
- Veeqo provides optional paid carrier quotes **only for a merchant-verified
  single outbound carton**. No guessed weights or dimensions are sent.
- A verified carton must have all four dimensions (length/width/height in inches,
  weight in pounds), one physical cart line at quantity 1, and satisfy the
  bounded parcel envelope of 150 lb, 108 in longest side and 165 in
  length-plus-girth. All others use merchant Standard only until a separately
  verified multi-parcel/freight policy is implemented.
- Provider quotes are screened for incompatible mail/document categories and
  carrier-specific special packaging. A cheapest eligible Standard, Express,
  and Overnight option can be chosen per tier. When free Standard applies, only
  paid Express/Overnight upgrades are displayed.
- Quotes and delivery wording use actual Veeqo carrier names and service names;
  no fictional transit-time guarantees. Quote IDs may expire and must never
  be reused as purchase/shipment authorization.
- Unknown API/provider responses fail closed to DTB merchant Standard. No
  shipment bookings, purchases, refunds or fulfillment edits occur at checkout.
- International rates currently retain the legacy local policy; they are **not
  carrier-verified** and must be addressed in a separately approved policy
  rollout. No assumption of international provider availability.

## Operator setup: physical packaging

WooCommerce > Products > Edit a simple product > Product data > Shipping:

1. Measure the **ready-to-ship carton** including padding, handle extensions,
   bundles, cartons and other packaging; never assume product dimensions equal
   the shipping box.
2. Enter `Packed weight (lb)`, `Carton length (in)`, `Carton width (in)`,
   and `Carton height (in)`. All four must be positive.
3. Confirm the SKU ships as **exactly one parcel**. Check
   `Carrier rating approved` and save. For multi-box sets leave unchecked.
4. Test non-payment Store API cart shipping results for this SKU and destination.
   Validate quoted carrier/service against physically eligible packaging and
   compare quoted cost with shipping operations; do not purchase a label as a test.
5. Revisit whenever packaging changes. Variation-level cartons require their
   own explicit future support, not parent-product inference.

The checkbox writes `_dtb_shipping_box_verified`; the physical carton values
write `_dtb_shipping_box_weight_lb`, `_dtb_shipping_box_length_in`,
`_dtb_shipping_box_width_in`, `_dtb_shipping_box_height_in`. These values are
shipping packaging data, independent of the Woo product/variation dimensions.

## Observed product-data gaps (2026-10-23)

Live Woo/Veeqo read-only inspections:

| SKU | Woo weight | Woo dimensions | Shipping carton approved? |
| --- | ---: | --- | --- |
| TTPPS | 55 lb | 54 x 21 x 16 in | No dedicated verified carton data observed |
| TTSFS-2 | 59.45 lb | incomplete | No dedicated verified carton data observed |
| TTCFS | 18.25 lb | 50 x 12 x 8 in | No dedicated verified carton data observed |
| PT-FIN | 32 lb | incomplete | No dedicated verified carton data observed |
| AH1B | missing | incomplete | No dedicated verified carton data observed |

These are **product fields**, not verified carton specifications. Do not
automatically approve them. The provider carrier-configuration read indicates
`connected=false` / `isConfigured=false` for USPS/UPS/FedEx, despite listed
services. Confirm actual carrier-account connectivity and eligibility in Veeqo
before enabling commercial rate selection.

## Release and operational verification

1. Source branch only; **do not copy PHP from rendered chat into live SiteGround**.
2. PHP 8.2 lint for both shipping files and the offline contract test must pass.
3. Test complete normal kit, missing carton, two-carton kit, multiple quantity,
   mailer-only part, oversize item, exact $50 threshold, and address changes.
4. Test in isolated non-payment environment; verify Checkout Block shipping
   selection, totals, tax and selected shipping line through WooCommerce and
   provider reconciliation, without charging, booking, or creating orders.
5. Verify Veeqo warehouse origin and carrier service connection. Shipping
   authority remains server side and independent of WooPayments enablement.
6. Deploy only with guarded backups, then read back PHP source hash and monitor
   SiteGround/WP PHP fatal log; rollback immediately on any fatal.
7. Changing the shipping bootstrap version invalidates stale Woo package-rate
   caches. Check checkout selection after recalculation; never force a
   promotional free rate over a deliberate paid customer choice.

## Future work (not silently implemented)

Implement verified multi-parcel consolidation, kit/component-specific packing,
oversize freight/LTL estimates, international pricing, and delivery-date
promises only after the fulfillment team validates actual carton data, carrier
capabilities, pickup cutoffs and economics. Do not invent "small-box shipping"
for such orders.
