# Coming-soon subscriber launch email

The `dtb-marketing/ComingSoon/LaunchEmail.php` renderer owns the launch announcement content and the administrator-only synthetic preview at **Settings → Launch Email Preview**. Proposed subject: **We’re live: explore Drywall Toolbox**. Preheader: **The new standard in drywall tools is here. Explore tools, parts, and repair services.** The preview uses no customer records and cannot send email.

The template is a ready-to-review design only. It is not connected to subscriber retrieval, a send button, a scheduled task, Action Scheduler, or an automatic site-launch trigger. Do not send until the site is officially live and the launch send is explicitly approved.

Before implementing a one-time sender, establish a durable send identity and duplicate suppression, bounded delivery/retry behavior, and an auditable recipient snapshot drawn only from eligible coming-soon signups. Exclude unsubscribed addresses, preserve recipient-specific unsubscribe links, and keep this audience separate from the WooCommerce account signup coupon campaign. The current coming-soon option list has no launch-send state, and unsubscribe currently removes records from that list; the template alone does not solve campaign dispatch or suppression.

Preview the design in WordPress at **Settings → Launch Email Preview**. The launch content is rendered inside the shared branded email shell, which owns the logo header, typography, responsive frame, and standard footer. The 10% new-account offer is rendered only while the separate signup coupon campaign is active; configure that offer under **Settings → Signup Coupon Campaign**. That settings page does not configure or send the coming-soon subscriber batch.

The launch visual follows the supplied campaign reference with a high-impact live announcement, dark full-bleed image hero, cobalt CTA, offer ribbon, and four linked destinations for tools, replacement parts, schematics, and repair service. It uses the existing public welcome-hero artwork. Verify route and artwork availability again at launch.
