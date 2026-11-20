# Coming-soon launch announcement email

`dtb-marketing/ComingSoon/LaunchEmail.php` owns the launch announcement renderer and the administrator preview at **Settings → Launch Email Preview**. The proposed campaign subject is **We’re live: meet the new Drywall Toolbox**. The preheader is **Professional tools, replacement parts, interactive schematics, and repair services—together in one place.**

## Design and rendering

The launch email uses the shared shell's full-bleed 620px frame. The shell owns the brand masthead and footer; the campaign body owns the hero, launch story, primary site CTA, and four destination links.

The renderer has two hero modes. When the signup coupon campaign is active, it uses the campaign artwork containing the verified launch offer. When that campaign is inactive, it uses a responsive editorial hero with a live announcement, cobalt rule, and product image; on narrow screens the copy and image stack vertically. Campaign activity, eligibility, coupon identity, and issuance remain owned by the signup coupon campaign.

The story starts with the slim **Same trade.** label and primary-blue rule, then the primary-blue headline **A smarter way to shop it.** It retains the approved body copy and adds one clear **Explore Drywall Toolbox** CTA. Four linked destinations remain **Shop Tools** (`/`), **Replacement Parts** (`/parts`), **Interactive Schematics** (`/schematics`), and **Repair Services** (`/repairs/packages`).

The light palette is the email-safe fallback. A launch-scoped dark-mode rule switches the story and link band to navy, uses white title text and icons, and preserves the primary-blue CTA. The minimal outline icons are separate from the shared icon library: `launch-{package,parts,schematics,repairs}-{light,dark}.png`. Light mode uses blue icons; dark mode uses white icons. The shared shell continues to supply the brand logo, social links, account/shop/contact navigation, and copyright.

## Synthetic preview and test delivery

Open **Settings → Launch Email Preview** to inspect the current renderer without loading subscriber data. The page offers a nonce-protected, administrator-only **Send test preview** action. An administrator-only REST action at `POST /wp-json/dtb/v1/admin/marketing/launch-email/test-preview` invokes the same delivery helper for authorized integrations. Both paths submit only to `elliottthomasmiller@gmail.com`, use the current renderer and shell, and mark the subject `[TEST PREVIEW]`; neither accepts a recipient parameter, reads the coming-soon audience, schedules work, or sends a campaign. A WordPress `wp_mail()` success confirms handoff to the configured mail transport, not inbox delivery or client rendering.

The test preview intentionally has no recipient-specific unsubscribe URL. A future campaign sender must still define an eligible recipient snapshot, unsubscribe suppression and links, durable send identity, duplicate suppression, bounded delivery/retry behavior, and audit/recovery before sending to subscribers. Keep that audience separate from the WooCommerce account signup coupon campaign. Do not send campaign email to live customers through this test action.

Verify image availability, category routes, and the separate offer state again before a real campaign. Review the delivered test in Gmail mobile/web and other target clients; the admin iframe alone is not proof of real-client rendering.
