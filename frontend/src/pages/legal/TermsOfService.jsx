import LegalDocument from './LegalDocument.jsx';

// Customer-facing ecommerce and repair terms. Customer promises must remain
// consistent with the live WooCommerce checkout, published store policies,
// repair intake authorization, and non-waivable consumer protections.
const SECTIONS = [
  {
    heading: '1. Agreement, operator, and scope',
    paragraphs: [
      'These Terms of Service (“Terms”) govern use of the Drywall Toolbox website at drywalltoolbox.com and related shopping, account, parts discovery, repair, return, and support features (“Services”). “Drywall Toolbox,” “DTB,” “we,” “our,” and “us” refer to the business operating the website. You can contact us at dtb@drywalltoolbox.com or through our Contact page.',
      'By using the Services or submitting an order or service request, you agree to these Terms to the extent permitted by law. If you do not agree, do not submit a purchase or service request. Different terms may apply to a specific quoted repair or product if clearly presented and accepted as part of that transaction.',
      'Our Shipping Policy, Return Policy, and any accepted repair estimate or work authorization provide additional transaction-specific details. If these Terms conflict with an applicable mandatory consumer right, that right controls. Transaction-specific terms control their subject matter to the extent lawfully agreed and not inconsistent with mandatory rights.',
    ],
    links: [
      { label: 'Store policies', to: '/policies' },
      { label: 'Contact our team', to: '/contact' },
    ],
  },
  {
    heading: '2. Who may use the website',
    paragraphs: [
      'You may use the website for lawful browsing, product research, purchasing, and service requests. You must be legally able to enter the transaction you submit, or act with proper authorization on behalf of a person or business. If you purchase for an employer or other organization, you represent that you are authorized to do so.',
      'You agree to provide accurate, complete, and current information when placing orders, registering accounts, requesting repairs, or contacting support. You must not impersonate another person, submit payment information you are not authorized to use, or provide misleading shipping or authorization information.',
    ],
  },
  {
    heading: '3. Accounts, guest checkout, and security',
    paragraphs: [
      'Where available, you may place an order as a guest or create a customer account. An account may provide order history, saved information, repair tracking, return requests, and support updates. Account capabilities may change as the Services develop.',
      'Keep account credentials and access links confidential. Notify us promptly if you suspect unauthorized activity. We may restrict access or require verification to protect customers, prevent misuse, comply with law, or respond to a security incident. We will not treat account suspension as cancellation of payment, refund, or delivery obligations already owed to you.',
    ],
  },
  {
    heading: '4. Product information, brands, and compatibility',
    paragraphs: [
      'We aim to provide accurate product photographs, descriptions, technical specifications, model references, part identifiers, availability, and compatibility information. Product packaging, appearance, dimensions, specifications, and manufacturer revisions may change. Illustrations, exploded-view schematics, and suggested compatible parts support identification; they are not a substitute for checking your actual tool model, serial or version, fitment, and manufacturer documentation.',
      'Manufacturer names, trademarks, logos, and schematics belong to their respective owners. Unless expressly identified, a product listing or compatibility reference does not imply that the manufacturer sponsors or endorses Drywall Toolbox.',
      'If product fitment, safety, or specifications are critical, contact us before ordering. Use tools, replacement parts, and repair instructions according to applicable safety requirements and manufacturer instructions. Do not rely solely on automated search or chat answers to make safety-sensitive decisions.',
    ],
  },
  {
    heading: '5. Pricing, promotions, taxes, and availability',
    paragraphs: [
      'Displayed product prices and promotions may change before you place an order. Prices are shown in the currency indicated at checkout, and applicable shipping charges, taxes, and other required charges are calculated or disclosed during checkout. The final checkout summary controls the amount submitted for your order, subject to correction of an obvious error or other applicable legal requirements.',
      'Coupons, promotional offers, free-shipping thresholds, and discounts are subject to their posted conditions and eligibility rules. Offers may be limited by dates, products, customer eligibility, quantity, or availability and cannot be exchanged for cash unless stated or required by law.',
      'Catalog stock indicators and estimated availability do not guarantee that an item can be fulfilled. If an ordered item is unavailable, incorrectly listed, or affected by a material pricing error, we may contact you with available options or cancel the affected item and issue any refund due under applicable law.',
    ],
  },
  {
    heading: '6. Order submission, acceptance, and changes',
    paragraphs: [
      'Adding an item to a cart does not reserve inventory. Submitting checkout is a request to purchase the products shown in your order summary. An automated receipt or confirmation acknowledges that we received your request; it does not necessarily mean that every item has been accepted or shipped. We may verify payment, availability, shipment restrictions, or suspected fraud before fulfillment.',
      'We may refuse or cancel an order for a lawful reason, such as unavailable stock, payment failure, material listing errors, shipment restrictions, or suspected unauthorized activity. If we cancel an item for which payment was captured, we will process the refund due for that item through the original payment method or another method required by law.',
      'Requests to change or cancel an order must be made promptly. Once an item has entered processing, shipped, or been committed to a special order, changes may not be possible. The applicable cancellation and return rules remain available through our store policies.',
    ],
  },
  {
    heading: '7. Payment authorization and security',
    paragraphs: [
      'Checkout is operated through WooCommerce and the payment methods offered at the time of purchase. Payment information is collected and processed by supported payment providers, including Stripe where offered. By submitting payment, you authorize the applicable processor to process the amount disclosed at checkout, including applicable taxes and delivery charges.',
      'Some transactions may require payment verification or authentication. A failed, reversed, or disputed payment may delay or prevent fulfillment while we investigate and comply with applicable payment-network and consumer-protection rules. Do not provide complete card numbers or security codes through chat, email, or repair forms.',
      'Only the payment method actually presented at checkout is available for a given transaction. We do not accept payments through QuickBooks as a substitute for the storefront checkout flow.',
    ],
  },
  {
    heading: '8. Shipping, delivery, and tracking',
    paragraphs: [
      'Available delivery methods, charges, shipping restrictions, estimated dispatch dates, and applicable carrier options are provided on our site or during checkout. Delivery dates are estimates unless expressly guaranteed as part of the selected service. Delivery may be affected by availability, weather, carrier events, holidays, destination limitations, and other circumstances outside our reasonable control.',
      'You are responsible for entering a complete and deliverable address and responding to reasonable delivery or carrier requests. Certain oversized, high-value, or freight items may require additional arrangements or signature confirmation. Title, risk of loss, and responsibility for damaged or missing deliveries are determined by applicable law and the agreed transaction terms; nothing here waives mandatory consumer remedies.',
      'If a shipment is delayed, damaged, incomplete, or delivered incorrectly, contact us promptly, preserve packaging, and provide photographs or tracking information where available. See the Shipping Policy for the current service options, shipping thresholds, and instructions.',
    ],
    links: [
      { label: 'Shipping Policy', to: '/shipping-policy' },
      { label: 'Order and tracking support', to: '/contact' },
    ],
  },
  {
    heading: '9. Returns, cancellations, and refunds',
    paragraphs: [
      'Our published standard return policy currently provides a 45-day window from the invoice date for eligible unused merchandise, subject to the condition, packaging, exclusion, and authorization requirements described in that policy. That policy also addresses approved standard returns, damaged or incorrect orders, and product problems. Eligibility may differ for used tools, final-sale items, special orders, consumables, installed parts, or other excluded products.',
      'Use the Returns Portal to submit a request against the actual order and obtain approval, a Return ID, and item-specific shipping instructions where required. Do not ship items without authorization. Return shipping responsibility, inspections, and approved refund amounts are handled according to the Return Policy, the applicable order terms, and governing law.',
      'Refunds owed for accepted returns, cancellations, or order problems are processed through the applicable commerce and payment systems. The time for a financial institution to post a refund may differ from the date we initiate it. Nothing in our policies limits statutory cancellation, return, refund, or defective-goods remedies that cannot lawfully be waived.',
    ],
    links: [
      { label: 'Return Policy', to: '/return-policy' },
      { label: 'Open the Returns Portal', to: '/returns' },
    ],
  },
  {
    heading: '10. Manufacturer warranties and product issues',
    paragraphs: [
      'Manufacturer warranties, when applicable, are provided under the manufacturer’s published terms and may depend on the product, purchase date, proper use, registration, proof of purchase, and claim eligibility. Any separately stated Drywall Toolbox warranty or service guarantee applies only to the scope and duration expressly published for that item or repair.',
      'If a product is defective, damaged, missing parts, or not what you ordered, contact us with the order details and supporting information. We can review the issue and direct it to the appropriate return, repair, replacement, manufacturer warranty, or other legally required resolution.',
      'We do not make manufacturer warranty coverage broader than the manufacturer actually offers. These Terms do not disclaim or override any non-excludable implied warranty or other consumer right.',
    ],
    links: [{ label: 'Warranty and product support', to: '/contact' }],
  },
  {
    heading: '11. Repair requests and diagnostics',
    paragraphs: [
      'Our repair workflow may offer scheduled service packages, tune-ups, diagnostics, quotations, warranty evaluation, or other inspection and repair paths. Submitting a request does not by itself establish a final diagnosis or fixed repair price. Repair scope depends on tool inspection, parts availability, and the applicable service selection.',
      'The repair intake process lets you describe the tool and reported problem, provide photos, select service and shipping preferences, and choose the applicable approval path. Quote-first work requires your approval before that additional work begins. Where the selected service expressly permits a pre-approval limit, work may be performed within the authorization you provided. An estimate or authorization may specify diagnostic charges, labor, parts, taxes, shipping, and other disclosed fees.',
      'If you decline a quoted repair, applicable disclosed diagnostic, handling, and return-shipping charges may still be due. We will not represent an unapproved additional repair as authorized merely because you submitted a diagnostic request.',
    ],
    links: [
      { label: 'Repair services', to: '/repairs' },
      { label: 'Start a repair request', to: '/repairs/start' },
    ],
  },
  {
    heading: '12. Repair shipping, timing, and completion',
    paragraphs: [
      'Follow the packing and shipment instructions provided for your accepted repair request. Remove unnecessary accessories or personal items unless requested, package the tool securely, and retain any shipment tracking details. If you request return of replaced components, communicate that preference during intake when the option is offered.',
      'Diagnostic findings, service-time estimates, and return-delivery dates can change because of tool condition, additional authorization needs, replacement-part availability, shipping interruptions, or newly discovered damage. We will communicate material changes through the repair process. We do not guarantee a specific completion date unless that commitment is expressly confirmed for the service.',
      'Repair work is limited to the agreed scope, any authorized adjustments, and the applicable repair warranty. Issues caused by misuse, unrelated damage, or normal wear may require a separate review. Customer property will not be treated as abandoned, sold, or disposed of except in compliance with applicable law and any separately agreed lawful process.',
    ],
    links: [{ label: 'Track a repair', to: '/repairs/track' }],
  },
  {
    heading: '13. Customer submissions, reviews, and photographs',
    paragraphs: [
      'You are responsible for ensuring that information, photographs, reviews, messages, and other materials you provide are accurate to the best of your knowledge, lawful, and appropriate to the request. Do not submit content that infringes another person’s rights, contains malicious code, reveals information you are not authorized to disclose, or includes unnecessary sensitive information.',
      'You retain ownership of your submissions. By submitting material for an order, product review, support inquiry, or repair request, you permit us and necessary service providers to store, reproduce, display internally, and process that material as reasonably needed to provide the requested service, investigate an issue, maintain records, or display a review you have chosen to publish. This permission does not transfer ownership of your content or authorize unrelated advertising use.',
      'We may moderate or remove public reviews or user submissions that violate law or these Terms, while respecting applicable consumer review protections. Support and repair materials are not published as reviews merely because you uploaded them.',
    ],
  },
  {
    heading: '14. Acceptable website use',
    paragraphs: [
      'You must not interfere with website security, attempt unauthorized access to customer or administrative information, abuse checkout or promotions, misrepresent product return or repair facts, upload malware, conduct fraudulent transactions, or use the Services in violation of law.',
      'Do not use automated access in a manner that disrupts our systems, scrapes protected customer data, bypasses technical controls, or imposes unreasonable load. Ordinary browsing, lawful indexing, and other uses protected by applicable law are not prohibited solely because a tool is automated.',
      'We may investigate and restrict unlawful or harmful activity using measures reasonably proportionate to the risk, while preserving any payment, consumer, privacy, or other rights that cannot be excluded.',
    ],
  },
  {
    heading: '15. Website materials and third-party resources',
    paragraphs: [
      'Drywall Toolbox owns or licenses the website’s original text, design, software, graphics, and other materials. Product names, trademarks, media, and technical diagrams from manufacturers remain the property of their respective owners. You may view and use website information for personal or legitimate business purchasing and product research, but may not commercially redistribute protected site materials without authorization or another lawful basis.',
      'References or links to manufacturer information, shipping carriers, payment providers, schematics, and other third-party services are supplied for convenience. Their independent offerings, policies, and service availability are governed by the respective third parties.',
    ],
  },
  {
    heading: '16. Availability, errors, and website changes',
    paragraphs: [
      'We work to keep our website and customer tools available, but availability may be affected by maintenance, security events, network issues, service-provider outages, catalog changes, or technical errors. Features may be modified or discontinued, subject to obligations for existing orders and services.',
      'Website content and automated product or service assistance may contain omissions or mistakes. Confirm consequential fitment, price, repair authorization, delivery, or safety information through the actual checkout, written estimate, manufacturer instructions, or our support team.',
    ],
  },
  {
    heading: '17. Warranties and limitations of liability',
    paragraphs: [
      'Products and repair services are subject to the warranties, remedies, and commitments expressly provided for the relevant purchase or accepted service, together with rights that apply by law. To the fullest extent permitted by applicable law, we do not guarantee that website access, search results, third-party integrations, or informational tools will be uninterrupted, error-free, or suitable for a particular technical purpose.',
      'To the extent permitted by applicable law, Drywall Toolbox is not liable for indirect, incidental, special, or consequential losses arising solely from use of the website or from events outside our reasonable control, including lost business opportunities or profits, except where such exclusions are prohibited or a greater obligation applies by law.',
      'Nothing in these Terms excludes, restricts, or limits liability or remedies that may not lawfully be excluded, including applicable rights relating to defective goods, injury, fraud, willful misconduct, or other non-waivable obligations. This section does not replace the actual remedies available for an accepted order, authorized repair, or required refund.',
    ],
  },
  {
    heading: '18. Privacy and communications',
    paragraphs: [
      'Our Privacy Policy explains how we collect, use, share, retain, and protect personal information. By providing contact information for an order or repair request, you agree that we may use it for necessary communications about that transaction, including confirmation, updates, questions, shipping, and support.',
      'Promotional subscriptions and optional marketing preferences are managed separately. You can opt out of eligible marketing messages without affecting transactional notices.',
    ],
    links: [{ label: 'Read our Privacy Policy', to: '/privacy-policy/' }],
  },
  {
    heading: '19. Questions, disputes, and applicable law',
    paragraphs: [
      'If you have a concern about an order, refund, shipment, repair, or website feature, contact dtb@drywalltoolbox.com or use the Contact page. Include relevant order or repair identifiers, but never send payment-card security codes or passwords. We will review the matter and attempt to resolve it through the applicable support process.',
      'Any dispute is subject to applicable law and the jurisdictional rules that apply to the parties and transaction. These Terms do not require mandatory arbitration, impose a class-action waiver, or waive a statutory right to bring a claim in an appropriate forum.',
    ],
    links: [{ label: 'Contact customer support', to: '/contact' }],
  },
  {
    heading: '20. Revisions and remaining terms',
    paragraphs: [
      'We may update these Terms to reflect changes in the Services, business practices, or law. Updates will be shown by a revised date and, where required, additional notice. Unless lawfully agreed otherwise, the version applicable when you placed an order or accepted a repair authorization governs that completed transaction; we do not retroactively remove non-waivable rights.',
      'If part of these Terms is unenforceable, the remaining provisions continue to apply to the extent legally permissible. Failure to enforce a provision once does not necessarily waive it in the future. Contact us if you need a copy of the terms applicable to a previous purchase or service.',
    ],
  },
];

export default function TermsOfService() {
  return (
    <LegalDocument
      title="Terms of Service"
      description="Drywall Toolbox customer terms for purchases, checkout, shipping, returns, warranties, repairs, accounts, and website use."
      canonical="/terms-of-service"
      eyebrow="Customer terms"
      introduction="The terms that govern shopping with Drywall Toolbox, ordering tools and parts, requesting repairs, and using our website."
      lastUpdated="October 23, 2026"
      sections={SECTIONS}
    />
  );
}
