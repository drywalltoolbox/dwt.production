import LegalDocument from './LegalDocument.jsx';

// Customer-facing disclosures. Keep these aligned with active service providers,
// the WordPress/WooCommerce data flows, and the operational retention schedule.
// Legal/business review is required before publishing or asserting new practices.
const SECTIONS = [
  {
    heading: '1. Who we are and when this policy applies',
    paragraphs: [
      'Drywall Toolbox (“Drywall Toolbox,” “DTB,” “we,” “us,” or “our”) operates drywalltoolbox.com, an online store and service platform for professional drywall tools, replacement parts, accessories, and tool repair services. For questions or privacy requests, contact dtb@drywalltoolbox.com or use our Contact page.',
      'This policy covers information handled when you browse our store, search for products or compatible parts, create an account, place an order, use our support or repair services, request a return, sign up for communications, or interact with our website. It does not replace the privacy policies of third-party websites or services that you choose to visit independently.',
      'Some features or integrations may be unavailable at a given time. The disclosures for an optional feature apply when you use that feature or when its associated service is enabled.',
    ],
    links: [{ label: 'Contact Drywall Toolbox', to: '/contact' }],
  },
  {
    heading: '2. Information you provide',
    paragraphs: [
      'Depending on your interaction with us, we collect your name, email address, telephone number, company name (if provided), billing and shipping addresses, account details, order and purchase information, and the contents of communications you send us.',
      'When you submit a repair, warranty, or return request, we may collect tool brand and model, part or serial identifiers, reported problems, chosen service path, authorization preferences, photographs, documents, shipping details, quotes, repair status, and support correspondence. If you write a review, send feedback, use site chat, or contact support, we process the information you choose to include.',
      'If you subscribe to updates or promotions, we collect your contact information and subscription preferences, together with any relevant consent or opt-out records. Please do not submit card security codes, full payment-card numbers, passwords, government identifiers, or sensitive personal information through ordinary forms, chat, photos, or support messages.',
    ],
  },
  {
    heading: '3. Transaction, account, and technical information',
    paragraphs: [
      'Our commerce systems generate information needed to process purchases and services, including cart contents, product identifiers, quantities, prices, discounts, tax and shipping charges, order and refund status, fulfillment and tracking events, and transaction references. Registered customers may also have saved addresses, account preferences, and histories of orders, returns, repairs, and support requests.',
      'When you access the website, our systems and hosting or security providers may process IP address, approximate location inferred from an IP address, device and browser characteristics, operating system, referral URL, page requests, timestamps, diagnostic errors, security signals, cookies, and session identifiers. The information collected depends on the features and services in use.',
      'Product search, part-selection tools, schematics, saved or recently viewed products, and installed web-app functions may use local device or browser storage to maintain a useful experience. Clearing that storage can remove preferences or locally cached content.',
    ],
  },
  {
    heading: '4. How information reaches us',
    paragraphs: [
      'We receive information directly from you through checkout, account registration, support and service forms, subscriptions, and communications. We also receive it automatically from your browser or device and from business systems involved in a transaction, such as payment confirmations, shipment and delivery updates, refund events, and service-provider fraud or security signals.',
      'If you use address-completion or other third-party-assisted input features, the provider may receive the information you enter into that feature. Information about your use of external websites is governed by those providers’ notices.',
    ],
  },
  {
    heading: '5. Why we use information',
    paragraphs: [
      'We use personal information to maintain shopping carts and accounts; authenticate customers; calculate and process checkout; accept or manage orders; process payments, refunds, shipping, and returns; provide repair estimates and authorized services; answer questions; maintain warranty and service records; and communicate important transaction updates.',
      'We also use information to prevent fraud and abuse, secure our systems, diagnose technical problems, reconcile inventory and accounting, satisfy recordkeeping obligations, improve product discovery and website functionality, and defend or enforce legal rights. With the appropriate permission or other lawful basis, we may send marketing communications and evaluate their performance.',
      'Where laws such as the GDPR or UK GDPR apply, our processing grounds may include performing a contract or taking steps you request before contracting, meeting legal obligations, our legitimate interests in operating and securing the business (balanced against individual rights), and your consent when required. You may withdraw consent for consent-based processing without affecting earlier lawful processing.',
    ],
  },
  {
    heading: '6. Orders, payment processing, and fraud prevention',
    paragraphs: [
      'WooCommerce maintains the authoritative storefront cart, customer, order, payment-status, and refund records. Checkout uses a payment provider, including Stripe where offered, to collect and authenticate payment details and process transactions. We receive information such as payment outcome, transaction reference, and limited payment-method details needed to manage an order or refund; payment providers handle payment credentials under their own security and privacy practices.',
      'Payment processors and fraud-prevention services may evaluate transaction signals and request additional authentication. An unsuccessful or flagged payment may require further review or prevent completion of an order. Contact us if you believe a transaction was incorrectly declined or marked suspicious.',
    ],
    links: [{ label: 'Stripe privacy information', href: 'https://stripe.com/privacy' }],
  },
  {
    heading: '7. Shipping, inventory, and business accounting',
    paragraphs: [
      'We use relevant customer contact, destination, purchased-item, and order information to coordinate stock, fulfillment, shipment, delivery, tracking, and delivery-related support. We may provide that information to carriers and logistics providers, including Veeqo when used for inventory and fulfillment.',
      'For bookkeeping, tax preparation, financial reporting, and reconciliation, approved business integrations may transfer necessary order, refund, and customer accounting information to QuickBooks Online (Intuit). This is a back-office accounting process, not a customer payment method or customer account service. Accounting synchronization does not make QuickBooks the authority for storefront orders or refunds.',
      'We disclose to each provider only the categories of information needed for the service being performed, subject to the provider’s applicable processing and contractual terms.',
    ],
    links: [
      { label: 'Intuit global privacy statement', href: 'https://www.intuit.com/privacy/statement/' },
      { label: 'Veeqo privacy information', href: 'https://www.veeqo.com/privacy-policy' },
    ],
  },
  {
    heading: '8. Repair services, returns, and support',
    paragraphs: [
      'Repair intake can include customer contact and shipment information, equipment identification, condition notes, uploaded photos, service selections, approval limits, diagnostic results, estimates, work history, and tracking updates. We use those details to evaluate and perform services, coordinate necessary approvals, and return equipment.',
      'For returns, warranty questions, and customer support, we process the relevant purchase, item, contact, communication, photograph, refund, and shipping information to validate requests and provide an appropriate resolution. A repair or warranty evaluation may require inspection by us or an authorized service or manufacturer representative.',
      'Information voluntarily entered into our website assistant or chat features may be used to generate responses, maintain conversation context, investigate service issues, and route support. Automated responses may be incomplete; do not rely on them as a substitute for a confirmed quotation, professional safety guidance, or an authorized order or repair decision.',
    ],
    links: [
      { label: 'Repair services', to: '/repairs' },
      { label: 'Returns portal', to: '/returns' },
    ],
  },
  {
    heading: '9. Cookies, sessions, and device storage',
    paragraphs: [
      'Essential cookies and related storage help WordPress and WooCommerce maintain site security, account sessions, cart state, and checkout continuity. Depending on configuration, WooCommerce cookies may include woocommerce_cart_hash, woocommerce_items_in_cart, and wp_woocommerce_session_*. Authentication and security tools may set additional cookies or tokens.',
      'Optional analytics, functional, advertising, or measurement tools may use cookies or similar identifiers when enabled. Payment, embedded content, mapping, and fraud-prevention providers may use their own technologies when their features are loaded. Actual cookie names, providers, and durations can change with our configuration.',
      'You may control cookies through browser settings and any privacy controls made available on our site. Blocking essential session technologies can interfere with login, carts, and checkout. Where applicable law requires consent before nonessential tracking, that tracking must be subject to the appropriate choice. A browser privacy signal or opt-out request will be handled to the extent required by applicable law.',
    ],
  },
  {
    heading: '10. Analytics, communications, and marketing choices',
    paragraphs: [
      'When analytics or measurement services are enabled, we may use page, device, referral, and interaction information to understand traffic and improve website performance. If advertising or remarketing services are enabled, they may process identifiers and interactions according to the settings and choices available for those services.',
      'We may send account, checkout, order, shipping, repair, return, and security messages as needed to provide the requested service. Marketing messages are separate; you can unsubscribe using the link in an eligible marketing email or by contacting us. Opting out of marketing does not stop necessary transactional messages.',
      'We do not interpret a request to delete an account as a request to erase information that must be retained to complete transactions, detect fraud, or comply with law. Where a law gives you a right to opt out of certain targeted advertising, sales, or sharing of personal information, contact us using the process described below.',
    ],
  },
  {
    heading: '11. When we disclose information',
    paragraphs: [
      'We disclose relevant information to vendors providing hosting, technical infrastructure, ecommerce, payment processing, fraud detection, inventory, logistics, shipping, accounting, customer support, communications, mapping/address assistance, and other services actually used in operating our store. Those providers may process information under our instructions or, for some services, as independent controllers under their own notices.',
      'Providers reflected in our platform architecture include SiteGround (hosting), WooCommerce/Automattic (commerce software and any enabled services), Stripe (payments where offered), Veeqo (inventory and fulfillment), Intuit QuickBooks (accounting when connected), and carriers. A provider named here is not necessarily used on every visit or transaction. Other processors may be introduced as our operations change, in which case we will update this notice as appropriate.',
      'We may also disclose information with your direction, for a business reorganization or asset transfer subject to applicable safeguards, to comply with lawful process, to investigate fraud, or to protect people, systems, and legal rights. We do not make a blanket claim that technical disclosures can never constitute a “sale” or “sharing” under laws that define those terms broadly.',
    ],
    links: [
      { label: 'SiteGround privacy policy', href: 'https://www.siteground.com/privacy.htm' },
      { label: 'Automattic privacy policy', href: 'https://automattic.com/privacy/' },
    ],
  },
  {
    heading: '12. Retention, backups, and deletion',
    paragraphs: [
      'We retain information for the period reasonably needed for the purpose for which it was collected and for applicable accounting, tax, fraud-prevention, warranty, contractual, legal, and dispute-resolution obligations. Account data may be kept while an account is active; order and refund records for required business records; repair and support documentation while needed for service, warranties, and claims; and security logs for legitimate diagnostic and protection needs.',
      'The exact duration can vary by record type, service provider, applicable retention law, and whether a dispute or legal hold exists. Transaction records may remain after an account is closed. Backup copies may remain until they expire or are rotated under backup procedures. When retention ends, information is deleted, anonymized, or otherwise disposed of through appropriate processes.',
      'Deleting a record from Drywall Toolbox does not necessarily delete independent records held by payment processors, carriers, or other third parties. We can help identify the appropriate contact for a provider where needed.',
    ],
  },
  {
    heading: '13. Your rights and how to make a request',
    paragraphs: [
      'Depending on where you live and the law that applies, you may have rights to know or access personal information, correct it, request deletion, obtain a copy in a portable format, object to or restrict certain uses, withdraw consent, or opt out of certain sales, sharing, targeted advertising, or qualifying profiling. Some laws also permit an appeal if a privacy request is denied.',
      'To make a request, email dtb@drywalltoolbox.com with “Privacy Request” in the subject or use our Contact page. Describe the right you wish to exercise and enough information for us to locate the relevant records. Do not send passwords, complete payment-card details, or other secrets. We may verify your identity or an authorized agent’s authority before releasing or changing information.',
      'We will respond within the period required by the law applicable to your request and explain relevant exceptions, including legal recordkeeping and fraud prevention. We will not unlawfully discriminate against you for exercising privacy rights. Where applicable, you may also contact your local data-protection authority.',
    ],
    links: [{ label: 'Submit a privacy request', to: '/contact' }],
  },
  {
    heading: '14. International processing',
    paragraphs: [
      'Drywall Toolbox and its service providers may process information in the United States or other countries in which they operate. Privacy laws and protections may differ by location. Where transfer restrictions apply, we rely on appropriate transfer mechanisms and safeguards required for the transfer, such as applicable adequacy arrangements or contractual protections.',
      'Specific provider locations and transfer mechanisms depend on the services actually enabled and the relevant agreements.',
    ],
  },
  {
    heading: '15. Security and incident response',
    paragraphs: [
      'We use measures designed to protect customer data, including access controls, secure network connections, restricted administrative privileges, and payment-provider security mechanisms. Authorized personnel and service providers may access information only as needed for their roles. No method of transmission or storage is completely secure.',
      'If we identify a security incident involving personal information, we investigate, contain, and address it and provide legally required notifications when applicable. Please report suspected unauthorized account access or security problems to dtb@drywalltoolbox.com.',
    ],
  },
  {
    heading: '16. Children, third-party links, and automated features',
    paragraphs: [
      'Our website sells professional tools and services and is not directed to children under 13. We do not knowingly collect personal information from children under 13 in violation of applicable law; contact us if you believe such information was submitted.',
      'External manufacturer, carrier, payment, and other websites operate under their own terms and privacy policies. Automated search, product recommendations, and chat assistance support discovery and communication; final purchase, payment, refund, and repair authorization decisions remain governed by the applicable transaction and service processes.',
    ],
  },
  {
    heading: '17. Updates and contact information',
    paragraphs: [
      'We may revise this policy to reflect changes in our services, providers, legal obligations, or privacy practices. We will update the revision date and provide additional notice or obtain consent when the law requires it.',
      'Privacy inquiries, requests, or concerns: Drywall Toolbox, dtb@drywalltoolbox.com, https://drywalltoolbox.com/contact. Business mailing or registered-address details will be provided where required by applicable law. For shipping, returns, and customer terms, consult our related policies and Terms of Service.',
    ],
    links: [
      { label: 'Terms of Service', to: '/terms-of-service/' },
      { label: 'Store policies', to: '/policies' },
    ],
  },
];

export default function PrivacyPolicy() {
  return (
    <LegalDocument
      title="Privacy Policy"
      description="How Drywall Toolbox collects, uses, shares, protects, and retains information for shopping, orders, accounts, repairs, returns, and support."
      canonical="/privacy-policy"
      eyebrow="Privacy & data protection"
      introduction="What information we handle, why we need it, which services help us operate, and how to exercise your privacy choices."
      lastUpdated="October 23, 2026"
      sections={SECTIONS}
    />
  );
}
