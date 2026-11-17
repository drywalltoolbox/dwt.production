# Coming-soon launch announcement email

`dtb-marketing/ComingSoon/LaunchEmail.php` owns the launch announcement renderer and the administrator preview at **Settings → Launch Email Preview**. The proposed campaign subject is **We’re live: meet the new Drywall Toolbox**. The preheader is **Professional tools, replacement parts, interactive schematics, and repair services—together in one place.**

## Design and rendering

The launch email opts into the shared shell's full-bleed frame: no outer side gutters, card border, radius, or shadow, while retaining the 620px desktop email measure. The hero, four-icon navigation band, closing context, and shared footer fill the same edge-to-edge canvas. The closing section uses the dark navy email palette and a high-contrast badge/headline hierarchy; its cobalt accent uses the storefront primary token `#2255EE` as a background image with a solid-color fallback so Gmail dark mode is less likely to shift it toward lavender. The hero artwork carries the conditional account offer while the separately owned signup coupon campaign is active; that campaign remains responsible for eligibility and coupon issuance.

Four clickable navigation tiles use two-line labels and retain their routes: **Shop Tools** (`/`), **Replacement Parts** (`/parts`), **Interactive Schematics** (`/schematics`), and **Repair Services** (`/repairs/packages`). Their raster icons are `package.png`, `parts.png`, `schematics.png`, and `repairs.png` from the active email icon library. The closing panel retains the approved headline and body copy. The shared shell continues to supply the brand logo, social links, account/shop/contact navigation, and copyright.

## Synthetic preview and test delivery

Open **Settings → Launch Email Preview** to inspect the current renderer without loading subscriber data. The page offers a nonce-protected, administrator-only **Send test preview** action. An administrator-only REST action at `POST /wp-json/dtb/v1/admin/marketing/launch-email/test-preview` invokes the same delivery helper for authorized integrations. Both paths submit only to `elliottthomasmiller@gmail.com`, use the current renderer and shell, and mark the subject `[TEST PREVIEW]`; neither accepts a recipient parameter, reads the coming-soon audience, schedules work, or sends a campaign. A WordPress `wp_mail()` success confirms handoff to the configured mail transport, not inbox delivery or client rendering.

The test preview intentionally has no recipient-specific unsubscribe URL. A future campaign sender must still define an eligible recipient snapshot, unsubscribe suppression and links, durable send identity, duplicate suppression, bounded delivery/retry behavior, and audit/recovery before sending to subscribers. Keep that audience separate from the WooCommerce account signup coupon campaign. Do not send campaign email to live customers through this test action.

Verify image availability, category routes, and the separate offer state again before a real campaign. Review the delivered test in Gmail mobile/web and other target clients; the admin iframe alone is not proof of real-client rendering.
