import BackButton from '../components/shared/BackButton.jsx';
/**
 * frontend/src/pages/ReturnPortal.jsx
 *
 * Customer returns workflow. WooCommerce remains authoritative for orders and
 * line items; DTB Returns owns verification, eligibility, request persistence,
 * workflow status, and public tracking.
 */

import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertCircle,
  ArrowRight,
  Check,
  CheckCircle,
  ClipboardCheck,
  FileSearch,
  Loader,
  Package,
  PackageCheck,
  RefreshCcw,
  RotateCcw,
  Search,
  ShieldCheck,
  Truck,
  Wrench,
} from 'lucide-react';
import SEOHead from '../components/shared/SEOHead';
import { apiClient } from '../api/client';
import '../styles/returns-portal.css';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const REQUEST_TYPES = [
  {
    id: 'standard_return',
    title: 'Standard return',
    description: 'Changed your mind, ordered the wrong item, or no longer need it.',
    Icon: RotateCcw,
  },
  {
    id: 'order_problem',
    title: 'Damaged or wrong order',
    description: 'The shipment arrived damaged, incorrect, or not as described.',
    Icon: Package,
  },
  {
    id: 'product_problem',
    title: 'Product problem',
    description: 'The item is defective or is not working as expected.',
    Icon: Wrench,
  },
];

const REASONS = {
  standard_return: [
    ['changed_mind', 'Changed my mind / no longer needed'],
    ['ordered_by_mistake', 'Ordered by mistake'],
    ['better_price_found', 'Better price found elsewhere'],
    ['other', 'Other'],
  ],
  order_problem: [
    ['arrived_damaged', 'Arrived damaged'],
    ['wrong_item_received', 'Wrong item received'],
    ['item_not_as_described', 'Item not as described'],
    ['other', 'Other'],
  ],
  product_problem: [
    ['defective_not_working', 'Defective / not working'],
    ['item_not_as_described', 'Item not as described'],
    ['other', 'Other'],
  ],
};

const PROCESS_STEPS = [
  ['Request review', 'We verify the selected items and return reason.', FileSearch],
  ['Approval', 'Approved requests receive a Return ID and shipping instructions.', ClipboardCheck],
  ['Return shipment', 'Pack only approved items and follow the provided instructions.', Truck],
  ['Inspection', 'Returned merchandise is checked against the approved request.', PackageCheck],
  ['Resolution', 'Refund, exchange, replacement, or another approved resolution is completed.', CheckCircle],
];

function createIdempotencyKey() {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return `return:${crypto.randomUUID()}`;
  }
  return `return:${Date.now().toString(36)}:${Math.random().toString(36).slice(2)}:${Math.random().toString(36).slice(2)}`;
}

function Notice({ children }) {
  return (
    <div className="returns-notice" role="alert" aria-live="polite">
      <AlertCircle size={18} aria-hidden="true" />
      <span>{children}</span>
    </div>
  );
}

function Steps({ step }) {
  const steps = [
    [1, 'Find order'],
    [2, 'Return details'],
    [3, 'Submitted'],
  ];

  return (
    <ol className="returns-progress" aria-label="Return request progress">
      {steps.map(([number, label], index) => (
        <li key={number} className={step >= number ? 'is-active' : ''}>
          <span className="returns-progress__dot" aria-current={step === number ? 'step' : undefined}>
            {step > number ? <Check size={14} aria-hidden="true" /> : number}
          </span>
          <span>{label}</span>
          {index < steps.length - 1 ? <span className="returns-progress__line" aria-hidden="true" /> : null}
        </li>
      ))}
    </ol>
  );
}

function PolicySummary({ policy }) {
  const items = policy
    ? [
        [`${policy.window_days}-day window`, 'Standard return eligibility is measured from the order date.'],
        ['Return condition', policy.condition],
        ['Approval before shipping', policy.approval_required],
        ['Refund handling', policy.refund_method],
      ]
    : [
        ['Verified order lookup', 'Use the order number and checkout email so we can securely identify the purchase.'],
        ['Approval before shipping', 'Do not send merchandise back until DTB approves the request.'],
        ['Item-level review', 'Eligibility is evaluated against the actual WooCommerce order line.'],
        ['Inspection before resolution', 'Returned merchandise is inspected before the approved resolution is completed.'],
      ];

  return (
    <div className="returns-policy-strip" aria-label="Return policy summary">
      {items.map(([title, text]) => (
        <div key={title}>
          <strong>{title}</strong>
          <span>{text}</span>
        </div>
      ))}
    </div>
  );
}

function OrderItem({ item, quantity, onToggle, onQuantityChange }) {
  const selected = quantity > 0;
  const maxQuantity = Math.max(0, Number(item.returnable_quantity || 0));
  const standardEligible = item.standard_return_eligible !== false;
  const eligibilityLabel = !item.eligible_for_request
    ? 'Not available'
    : standardEligible
      ? 'Standard return eligible'
      : 'Problem review available';

  return (
    <article className={`returns-item ${selected ? 'is-selected' : ''} ${item.eligible_for_request ? '' : 'is-ineligible'}`}>
      <label className="returns-item__select">
        <input
          type="checkbox"
          checked={selected}
          disabled={!item.eligible_for_request}
          onChange={(event) => onToggle(item.item_id, event.target.checked ? 1 : 0)}
          aria-label={`Select ${item.name} for return`}
        />
        <span className="returns-item__checkbox" aria-hidden="true" />
      </label>

      <div className="returns-item__media" aria-hidden="true">
        {item.image_url ? <img src={item.image_url} alt="" loading="lazy" /> : <Package size={24} />}
      </div>

      <div className="returns-item__body">
        <div className="returns-item__title-row">
          <div>
            <h3>{item.name}</h3>
            {item.sku ? <p>SKU {item.sku}</p> : null}
          </div>
          <span className={`returns-eligibility ${item.eligible_for_request ? 'is-eligible' : 'is-ineligible'}`}>
            {eligibilityLabel}
          </span>
        </div>

        {item.eligible_for_request ? (
          <>
            <div className="returns-item__meta">
              <span>Purchased: {item.quantity}</span>
              <label>
                Return quantity
                <select
                  value={selected ? quantity : 0}
                  onChange={(event) => onQuantityChange(item.item_id, Number(event.target.value))}
                  disabled={!selected}
                >
                  <option value="0">0</option>
                  {Array.from({ length: maxQuantity }, (_, index) => index + 1).map((value) => (
                    <option key={value} value={value}>{value}</option>
                  ))}
                </select>
              </label>
            </div>
            {item.eligibility_note ? <p className="returns-item__reason">{item.eligibility_note}</p> : null}
          </>
        ) : (
          <p className="returns-item__reason">{item.eligibility_note || 'This order line is not available for another return request.'}</p>
        )}
      </div>
    </article>
  );
}

function ProcessTimeline() {
  return (
    <section className="returns-process" aria-labelledby="returns-process-title">
      <div className="returns-section-heading">
        <span className="returns-kicker">What happens next</span>
        <h2 id="returns-process-title">A visible return workflow from request to resolution.</h2>
      </div>
      <ol>
        {PROCESS_STEPS.map(([title, text, Icon], index) => (
          <li key={title}>
            <span className="returns-process__number">{String(index + 1).padStart(2, '0')}</span>
            <Icon size={20} aria-hidden="true" />
            <div>
              <strong>{title}</strong>
              <p>{text}</p>
            </div>
          </li>
        ))}
      </ol>
    </section>
  );
}

export default function ReturnPortal() {
  const [step, setStep] = useState(1);
  const [orderNumber, setOrderNumber] = useState('');
  const [lookupEmail, setLookupEmail] = useState('');
  const [lookupLoading, setLookupLoading] = useState(false);
  const [lookupError, setLookupError] = useState('');
  const [selectedOrder, setSelectedOrder] = useState(null);
  const [selectedItems, setSelectedItems] = useState({});
  const [requestType, setRequestType] = useState('');
  const [returnReason, setReturnReason] = useState('');
  const [additionalNotes, setAdditionalNotes] = useState('');
  const [idempotencyKey, setIdempotencyKey] = useState(createIdempotencyKey);
  const [submitLoading, setSubmitLoading] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [returnTracking, setReturnTracking] = useState(null);

  const selectedItemPayload = useMemo(
    () => Object.entries(selectedItems)
      .filter(([, quantity]) => Number(quantity) > 0)
      .map(([itemId, quantity]) => ({ item_id: Number(itemId), quantity: Number(quantity) })),
    [selectedItems]
  );

  const selectedRequiresProblemPath = useMemo(() => {
    const selectedIds = new Set(selectedItemPayload.map((item) => item.item_id));
    return (selectedOrder?.items || []).some(
      (item) => selectedIds.has(Number(item.item_id)) && item.standard_return_eligible === false
    );
  }, [selectedItemPayload, selectedOrder]);

  const reasonOptions = requestType ? REASONS[requestType] || [] : [];
  const policy = selectedOrder?.policy || null;

  const handleLookup = async (event) => {
    event.preventDefault();
    setLookupError('');
    setSelectedOrder(null);
    setSelectedItems({});

    const order = orderNumber.trim();
    const email = lookupEmail.trim().toLowerCase();

    if (!order) {
      setLookupError('Enter your order number.');
      return;
    }
    if (!EMAIL_RE.test(email)) {
      setLookupError('Enter the checkout email used for this order.');
      return;
    }

    setLookupLoading(true);
    try {
      const response = await apiClient('/wp-json/dtb/v1/returns/lookup', {
        method: 'POST',
        body: JSON.stringify({ order_number: order, customer_email: email }),
      });
      const verifiedOrder = Array.isArray(response?.orders) ? response.orders[0] : null;
      if (!verifiedOrder?.lookup_token) {
        setLookupError('We could not verify that order. Check the order number and checkout email and try again.');
        return;
      }
      setSelectedOrder(verifiedOrder);
      setIdempotencyKey(createIdempotencyKey());
    } catch (error) {
      setLookupError(error?.message || 'We could not verify that order. Please try again.');
    } finally {
      setLookupLoading(false);
    }
  };

  const continueWithOrder = () => {
    if (!selectedOrder?.lookup_token) {
      setLookupError('Verify your order before continuing.');
      return;
    }
    if (selectedItemPayload.length === 0) {
      setLookupError('Select at least one available item and quantity to continue.');
      return;
    }
    setLookupError('');
    setRequestType('');
    setReturnReason('');
    setStep(2);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const handleItemToggle = (itemId, quantity) => {
    setSelectedItems((current) => ({ ...current, [itemId]: quantity }));
  };

  const handleQuantityChange = (itemId, quantity) => {
    setSelectedItems((current) => ({ ...current, [itemId]: quantity }));
  };

  const handleRequestTypeChange = (type) => {
    if (type === 'standard_return' && selectedRequiresProblemPath) return;
    setRequestType(type);
    setReturnReason('');
  };

  const handleSubmit = async (event) => {
    event.preventDefault();
    setSubmitError('');

    if (!selectedOrder?.lookup_token) {
      setSubmitError('Your order verification has expired. Find the order again.');
      return;
    }
    if (selectedItemPayload.length === 0) {
      setSubmitError('Select at least one available item to return.');
      return;
    }
    if (!requestType || !returnReason) {
      setSubmitError('Choose the return type and reason.');
      return;
    }
    if (requestType === 'standard_return' && selectedRequiresProblemPath) {
      setSubmitError('One or more selected items are outside standard-return eligibility. Choose an applicable order-problem or product-problem path.');
      return;
    }

    setSubmitLoading(true);
    try {
      const response = await apiClient('/wp-json/dtb/v1/returns/request/verified', {
        method: 'POST',
        body: JSON.stringify({
          lookup_token: selectedOrder.lookup_token,
          idempotency_key: idempotencyKey,
          request_type: requestType,
          reason: returnReason,
          notes: additionalNotes.trim(),
          items: selectedItemPayload,
        }),
      });

      setReturnTracking(
        response?.return_id && response?.public_token
          ? { id: response.return_id, token: response.public_token }
          : null
      );
      setStep(3);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (error) {
      setSubmitError(error?.message || 'Unable to submit your return request. Please try again.');
    } finally {
      setSubmitLoading(false);
    }
  };

  const resetAll = () => {
    setStep(1);
    setOrderNumber('');
    setLookupEmail('');
    setLookupLoading(false);
    setLookupError('');
    setSelectedOrder(null);
    setSelectedItems({});
    setRequestType('');
    setReturnReason('');
    setAdditionalNotes('');
    setIdempotencyKey(createIdempotencyKey());
    setSubmitLoading(false);
    setSubmitError('');
    setReturnTracking(null);
  };

  return (
    <div className="page-wrapper returns-portal">
      <SEOHead
        title="Returns & Exchanges"
        description="Verify your Drywall Toolbox order, select eligible items, and start a secure return request online."
        canonical="/returns"
      />

      <header className="returns-hero">
        <div className="returns-shell returns-hero__inner">
          <div>
            <span className="returns-eyebrow">Returns & exchanges</span>
            <h1>Start the right return without guessing what happens next.</h1>
            <p>
              Verify the purchase, choose the exact order lines you need help with, and send one structured request to DTB Returns. Do not ship anything until the request is approved.
            </p>
            <div className="returns-hero__actions">
              <a href="#start-return" className="btn btn-primary">Start a return <ArrowRight size={17} /></a>
              <Link to="/return-policy" className="returns-secondary-link">Read return policy</Link>
            </div>
          </div>
          <div className="returns-hero__trust" aria-label="Returns portal safeguards">
            <div><ShieldCheck size={20} /><span><strong>Verified order access</strong>Order number + checkout email</span></div>
            <div><ClipboardCheck size={20} /><span><strong>Approval before shipping</strong>Return ID and instructions first</span></div>
            <div><RefreshCcw size={20} /><span><strong>Retry-safe submission</strong>Duplicate request protection</span></div>
          </div>
        </div>
      </header>

      <main className="returns-main">
        <div className="returns-shell">
          <PolicySummary policy={policy} />

          <div className="returns-layout" id="start-return">
            <section className="returns-workflow-card" aria-labelledby="returns-workflow-title">
              <Steps step={step} />

              {step === 1 ? (
                <>
                  <div className="returns-section-heading">
                    <span className="returns-kicker">Find your order</span>
                    <h2 id="returns-workflow-title">Verify the purchase before selecting return items.</h2>
                    <p>For customer privacy, both the order number and checkout email must match the WooCommerce order.</p>
                  </div>

                  {lookupError ? <Notice>{lookupError}</Notice> : null}

                  <form className="returns-lookup-form" onSubmit={handleLookup} noValidate>
                    <label>
                      <span>Order number</span>
                      <input
                        value={orderNumber}
                        onChange={(event) => setOrderNumber(event.target.value)}
                        placeholder="Example: 12345"
                        autoComplete="off"
                        inputMode="numeric"
                      />
                    </label>
                    <label>
                      <span>Checkout email</span>
                      <input
                        type="email"
                        value={lookupEmail}
                        onChange={(event) => setLookupEmail(event.target.value)}
                        placeholder="you@example.com"
                        autoComplete="email"
                      />
                    </label>
                    <button className="btn btn-primary returns-lookup-submit" type="submit" disabled={lookupLoading}>
                      {lookupLoading ? <Loader className="returns-spin" size={17} /> : <Search size={17} />}
                      {lookupLoading ? 'Verifying order…' : 'Find my order'}
                    </button>
                  </form>

                  {selectedOrder ? (
                    <div className="returns-order-panel">
                      <div className="returns-order-panel__header">
                        <div>
                          <span>Verified order</span>
                          <h3>Order #{selectedOrder.order_number}</h3>
                          <p>{selectedOrder.date || 'Order date unavailable'} · {selectedOrder.status}</p>
                        </div>
                        <span className="returns-verified"><CheckCircle size={16} /> Verified</span>
                      </div>

                      <div className="returns-item-list">
                        {(selectedOrder.items || []).map((item) => (
                          <OrderItem
                            key={item.item_id}
                            item={item}
                            quantity={Number(selectedItems[item.item_id] || 0)}
                            onToggle={handleItemToggle}
                            onQuantityChange={handleQuantityChange}
                          />
                        ))}
                      </div>

                      <button type="button" className="btn btn-primary returns-continue" onClick={continueWithOrder}>
                        Continue with selected items <ArrowRight size={17} />
                      </button>
                    </div>
                  ) : null}
                </>
              ) : null}

              {step === 2 ? (
                <form onSubmit={handleSubmit} noValidate>
                  <BackButton onClick={() => setStep(1)} label="Back to order items" />

                  <div className="returns-section-heading">
                    <span className="returns-kicker">Return details</span>
                    <h2 id="returns-workflow-title">Tell us what kind of help this return needs.</h2>
                    <p>The category changes how the request is reviewed. Damaged, incorrect, and defective-item cases are not treated like ordinary buyer-remorse returns.</p>
                  </div>

                  {submitError ? <Notice>{submitError}</Notice> : null}
                  {selectedRequiresProblemPath ? (
                    <div className="returns-path-callout" role="note">
                      <AlertCircle size={19} aria-hidden="true" />
                      <div>
                        <strong>Standard return is not available for every selected item.</strong>
                        <p>One or more selected order lines are outside standard-return eligibility. If the item arrived damaged, was incorrect, or has a product problem, choose the applicable problem-review path below.</p>
                      </div>
                    </div>
                  ) : null}

                  <fieldset className="returns-type-fieldset">
                    <legend>What do you need help with?</legend>
                    <div className="returns-type-grid">
                      {REQUEST_TYPES.map(({ id, title, description, Icon }) => {
                        const disabled = id === 'standard_return' && selectedRequiresProblemPath;
                        return (
                          <label key={id} className={`returns-type-card ${requestType === id ? 'is-selected' : ''} ${disabled ? 'is-disabled' : ''}`}>
                            <input
                              type="radio"
                              name="request-type"
                              value={id}
                              checked={requestType === id}
                              disabled={disabled}
                              onChange={() => handleRequestTypeChange(id)}
                            />
                            <Icon size={21} aria-hidden="true" />
                            <span>
                              <strong>{title}</strong>
                              <small>{disabled ? 'Not available for the current item selection.' : description}</small>
                            </span>
                          </label>
                        );
                      })}
                    </div>
                  </fieldset>

                  <div className="returns-detail-fields">
                    <label>
                      <span>Return reason</span>
                      <select value={returnReason} onChange={(event) => setReturnReason(event.target.value)} disabled={!requestType}>
                        <option value="">{requestType ? 'Choose a reason' : 'Choose a return type first'}</option>
                        {reasonOptions.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                      </select>
                    </label>

                    <label>
                      <span>Additional details <em>optional</em></span>
                      <textarea
                        rows="5"
                        value={additionalNotes}
                        onChange={(event) => setAdditionalNotes(event.target.value)}
                        maxLength="2000"
                        placeholder="Add condition details, damage location, missing components, or anything our returns team should know."
                      />
                    </label>
                  </div>

                  {requestType === 'order_problem' || requestType === 'product_problem' ? (
                    <div className="returns-evidence-callout">
                      <FileSearch size={20} aria-hidden="true" />
                      <div>
                        <strong>Keep supporting photos available.</strong>
                        <p>For damage or product problems, keep clear photos of the item, shipping carton, label, and affected area. DTB may request them during review. The public portal does not upload customer evidence into the general WordPress media library.</p>
                      </div>
                    </div>
                  ) : null}

                  <div className="returns-submit-summary">
                    <strong>{selectedItemPayload.length} selected order {selectedItemPayload.length === 1 ? 'line' : 'lines'}</strong>
                    <span>Nothing should be shipped until the request is approved and return instructions are provided.</span>
                  </div>

                  <button className="btn btn-primary returns-submit" type="submit" disabled={submitLoading}>
                    {submitLoading ? <Loader className="returns-spin" size={17} /> : <CheckCircle size={17} />}
                    {submitLoading ? 'Submitting request…' : 'Submit return request'}
                  </button>
                </form>
              ) : null}

              {step === 3 ? (
                <div className="returns-success" id="returns-workflow-title">
                  <div className="returns-success__icon"><CheckCircle size={28} /></div>
                  <span className="returns-kicker">Request received</span>
                  <h2>Your return is now pending review.</h2>
                  <p>Do not ship the selected items yet. DTB will review the request and provide instructions when the return is approved.</p>
                  {returnTracking ? (
                    <>
                      <div className="returns-reference">Return #{returnTracking.id}</div>
                      <Link className="btn btn-primary returns-track" to={`/returns/status/${returnTracking.id}?token=${encodeURIComponent(returnTracking.token)}`}>
                        Track return status <ArrowRight size={17} />
                      </Link>
                    </>
                  ) : null}
                  <button type="button" className="returns-reset" onClick={resetAll}>Start another return</button>
                </div>
              ) : null}
            </section>

            <aside className="returns-sidebar" aria-label="Return guidance">
              <section>
                <span className="returns-kicker">Know before you start</span>
                <h2>One request, clear responsibilities.</h2>
                <ul>
                  <li><CheckCircle size={16} /><span><strong>Keep the item complete.</strong> Original packaging, accessories, documentation, and included components may be required.</span></li>
                  <li><CheckCircle size={16} /><span><strong>Do not ship early.</strong> Wait for an approved Return ID and the instructions tied to your request.</span></li>
                  <li><CheckCircle size={16} /><span><strong>Pack against movement.</strong> Protect approved merchandise from damage during return transit.</span></li>
                  <li><CheckCircle size={16} /><span><strong>Return only approved items.</strong> Extra or unrelated merchandise can delay inspection and resolution.</span></li>
                </ul>
                <Link to="/return-policy">Read the full return policy <ArrowRight size={14} /></Link>
              </section>

              <section className="returns-sidebar__problem">
                <AlertCircle size={20} />
                <div>
                  <strong>Damaged, wrong, or defective?</strong>
                  <p>Select the matching request type instead of treating it as a standard return. That keeps the issue classified correctly for review.</p>
                </div>
              </section>

              <section className="returns-sidebar__tracking">
                <RefreshCcw size={20} />
                <div>
                  <strong>Already started a return?</strong>
                  <p>Use the secure tracking link in your confirmation email. It is tied to your Return ID and access token.</p>
                </div>
              </section>
            </aside>
          </div>

          <ProcessTimeline />

          <section className="returns-before-ship" aria-labelledby="returns-before-ship-title">
            <div className="returns-section-heading">
              <span className="returns-kicker">Before you ship</span>
              <h2 id="returns-before-ship-title">Approval comes before packing labels or carrier handoff.</h2>
              <p>The approved request defines what can be sent back. Packing and transit responsibility begins only after DTB provides return instructions.</p>
            </div>
            <div className="returns-before-ship__grid">
              <div><span>01</span><strong>Confirm approval</strong><p>Verify the Return ID, approved items, and quantities.</p></div>
              <div><span>02</span><strong>Restore the package</strong><p>Include required accessories, documentation, and original components.</p></div>
              <div><span>03</span><strong>Protect the merchandise</strong><p>Cushion the shipment so tools and parts cannot move freely in transit.</p></div>
              <div><span>04</span><strong>Follow the instructions</strong><p>Use the carrier, label, Return ID placement, and destination supplied for the approved return.</p></div>
            </div>
          </section>
        </div>
      </main>
    </div>
  );
}
