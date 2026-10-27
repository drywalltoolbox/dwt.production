/**
 * frontend/src/pages/OrderConfirmation.jsx
 *
 * Customer-facing order summary page: /order/:id
 */

import { useState, useEffect } from 'react';
import { useParams, useSearchParams, Link } from 'react-router-dom';
import { getOrder } from '../api/orders.js';
import {
  CheckCircle,
  Clock,
  Package,
  Truck,
  AlertCircle,
  Loader,
  CreditCard,
  Mail,
  MapPin,
  User,
} from 'lucide-react';
import SEOHead from '../components/shared/SEOHead';
import SmartBackButton from '../components/navigation/SmartBackButton.jsx';
import AnimatedOrderSuccess from '../components/order/AnimatedOrderSuccess.jsx';
import { useOrderItemImageFallbacks } from '../hooks/useOrderItemImageFallbacks.js';
import { getOrderItemKey, resolveOrderItemImage } from '../utils/orderItemImages.js';
import '../styles/order-pages.css';

const STATUS_CONFIG = {
  pending: { label: 'Pending Payment', Icon: Clock },
  processing: { label: 'Processing', Icon: Package },
  'on-hold': { label: 'Payment Under Review', Icon: Clock },
  completed: { label: 'Completed', Icon: CheckCircle },
  cancelled: { label: 'Cancelled', Icon: AlertCircle },
  refunded: { label: 'Refunded', Icon: AlertCircle },
  failed: { label: 'Payment Failed', Icon: AlertCircle },
  shipped: { label: 'Shipped', Icon: Truck },
  'repair-received': { label: 'Repair Received', Icon: Package },
  'repair-in-progress': { label: 'Repair In Progress', Icon: Package },
  'repair-awaiting-approval': { label: 'Awaiting Your Approval', Icon: Clock },
  'repair-approved': { label: 'Repair Approved', Icon: Package },
  'repair-complete': { label: 'Repair Complete', Icon: CheckCircle },
  'repair-shipped': { label: 'Tool Shipped Back', Icon: Truck },
};

function StatusBadge({ status }) {
  const cfg = STATUS_CONFIG[status] || { label: status || 'Order Received', Icon: Package };
  const Icon = cfg.Icon;
  return (
    <span className="dtb-order-status-badge">
      <Icon size={14} />
      {cfg.label}
    </span>
  );
}

function parseMoney(value) {
  const numeric = typeof value === 'number' ? value : parseFloat(String(value ?? ''));
  return Number.isFinite(numeric) ? numeric : 0;
}

function money(value) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(parseMoney(value));
}

function hasMoneyField(value) {
  return value !== null && value !== undefined && value !== '';
}

function humanizeToken(value) {
  return String(value || '')
    .replace(/[-_]+/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

function buildAddress(fields = {}) {
  return [fields.address_1, fields.address_2, fields.city, fields.state, fields.postcode]
    .filter(Boolean)
    .join(', ');
}

function OrderItemMedia({ item, fallbackImage = '' }) {
  const imageUrl = resolveOrderItemImage(item) || fallbackImage;
  const imageAlt = item?.image_alt || item?.name || 'Ordered product';

  return (
    <span className={`dtb-order-item__thumb ${imageUrl ? 'has-image' : ''}`} aria-hidden={!imageUrl}>
      {imageUrl ? (
        <img
          src={imageUrl}
          srcSet={item?.image_srcset || undefined}
          sizes="72px"
          alt={imageAlt}
          loading="lazy"
          decoding="async"
        />
      ) : (
        <Package size={18} />
      )}
    </span>
  );
}

function DetailRow({ icon: Icon, label, children }) {
  if (!children) return null;
  return (
    <div className="dtb-order-detail-row">
      <dt className="dtb-order-detail-label">{label}</dt>
      <dd className="dtb-order-detail-value">
        {Icon ? <Icon size={15} style={{ display: 'inline', marginRight: 8, verticalAlign: '-2px', color: '#2255ee' }} /> : null}
        {children}
      </dd>
    </div>
  );
}

export default function OrderConfirmation() {
  const { id } = useParams();
  const [searchParams] = useSearchParams();
  const orderKey = searchParams.get('order_key') || '';
  const [order, setOrder] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!id) return undefined;

    let cancelled = false;

    getOrder(id, orderKey)
      .then((data) => {
        if (!cancelled) setOrder(data);
      })
      .catch((err) => {
        if (!cancelled) {
          if (err.status === 401 || err.status === 403) {
            setError('Please sign in or use the secure order link from your confirmation email.');
          } else {
            setError(err.message || 'Unable to load order details.');
          }
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => { cancelled = true; };
  }, [id, orderKey]);

  const lineItems = Array.isArray(order?.line_items) ? order.line_items : [];
  const itemImageFallbacks = useOrderItemImageFallbacks(lineItems);

  if (loading) {
    return (
      <div className="dtb-order-page page-wrapper">
        <SEOHead noindex title={`Order #${id}`} />
        <div className="dtb-order-shell">
          <div className="dtb-order-hero">
            <span className="dtb-order-status-icon dtb-order-status-icon--neutral">
              <Loader className="animate-spin" size={42} strokeWidth={1.8} />
            </span>
            <p className="dtb-order-eyebrow">Secure order</p>
            <h1 className="dtb-order-title">Loading order</h1>
            <p className="dtb-order-subtitle">Retrieving your order summary and tracking details.</p>
          </div>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="dtb-order-page page-wrapper">
        <SEOHead noindex title="Order unavailable" />
        <div className="dtb-order-shell" style={{ maxWidth: 720 }}>
          <div className="dtb-order-hero">
            <span className="dtb-order-status-icon dtb-order-status-icon--neutral">
              <AlertCircle size={42} strokeWidth={1.8} />
            </span>
            <p className="dtb-order-eyebrow">Order lookup</p>
            <h1 className="dtb-order-title">Unable to load order</h1>
            <p className="dtb-order-subtitle">{error}</p>
            <div className="dtb-order-actions">
              <SmartBackButton fallbackTo="/dashboard?tab=orders" label="Go back" />
              <Link to="/products" className="dtb-order-button dtb-order-button--primary">
                Continue Shopping
              </Link>
            </div>
          </div>
        </div>
      </div>
    );
  }

  const billing = order?.billing || {};
  const billingName = [billing.first_name, billing.last_name].filter(Boolean).join(' ');
  const billingAddress = buildAddress(billing);
  const shippingTotal = parseMoney(order?.shipping_total);
  const trackingOrderId = order?.id || id;
  const trackingUrl = `/order-tracking/${encodeURIComponent(trackingOrderId)}${orderKey ? `?order_key=${encodeURIComponent(orderKey)}` : ''}`;
  const placedLabel = order?.date_created ? new Date(order.date_created).toLocaleDateString() : '';
  const orderTotal = order?.total ? money(order.total) : '';
  const paymentConfirmed = ['processing', 'completed', 'shipped'].includes(order?.status);

  return (
    <div className="dtb-order-page page-wrapper">
      <SEOHead noindex title={`Order #${id}`} />
      <div className="dtb-order-shell">
        <section className="dtb-order-sheet" aria-labelledby="order-title">
          <header className="dtb-order-sheet__hero">
            {paymentConfirmed ? (
              <AnimatedOrderSuccess
                orderId={order?.id || id}
                title="Order confirmed"
                titleId="order-title"
                trackingHref={trackingUrl}
                message={`Your order #${id} is confirmed. A receipt is on its way to your inbox.`}
              />
            ) : (
              <>
                <span className="dtb-order-status-icon dtb-order-status-icon--neutral">
                  <Package size={42} strokeWidth={1.8} />
                </span>
                <p className="dtb-order-eyebrow">Order summary</p>
                <h1 id="order-title" className="dtb-order-title">Order #{id}</h1>
                <p className="dtb-order-subtitle">
                  Review your order details and current payment or fulfillment status below.
                </p>
              </>
            )}
            <div className="dtb-order-hero__metrics" aria-label="Order summary">
              <div>
                <span>Status</span>
                <strong>{order?.status ? humanizeToken(order.status) : 'Received'}</strong>
              </div>
              {placedLabel ? (
                <div>
                  <span>Placed</span>
                  <strong>{placedLabel}</strong>
                </div>
              ) : null}
              {orderTotal ? (
                <div>
                  <span>Total</span>
                  <strong>{orderTotal}</strong>
                </div>
              ) : null}
            </div>
            <div className="dtb-order-badges">
              {order?.status ? <StatusBadge status={order.status} /> : null}
              {placedLabel ? (
                <span className="dtb-order-status-badge">
                  <Clock size={14} /> {placedLabel}
                </span>
              ) : null}
            </div>
          </header>

          <div className="dtb-order-sheet__content">
            <div className="dtb-order-sheet__grid">
              <section className="dtb-order-sheet-section dtb-order-sheet-section--items" aria-labelledby="items-title">
                <header className="dtb-order-sheet-section__header">
                  <h2 id="items-title" className="dtb-order-card__title">
                  <Package size={20} /> Items ordered
                  </h2>
                </header>
                <div className="dtb-order-sheet-section__body">
                  <div className="dtb-order-items">
                    {lineItems.map((item) => (
                      <article key={item.id || item.name} className="dtb-order-item">
                        <div className="dtb-order-item__main">
                          <OrderItemMedia item={item} fallbackImage={itemImageFallbacks[getOrderItemKey(item)]} />
                          <div>
                            <h3 className="dtb-order-item__name">{item.name}</h3>
                            <p className="dtb-order-item__meta">Qty: {item.quantity || 1}</p>
                          </div>
                        </div>
                        <strong className="dtb-order-item__price">{money(item.total)}</strong>
                      </article>
                    ))}
                  </div>

                  {order ? (
                    <div className="dtb-order-totals" aria-label="Order totals">
                      <div className="dtb-order-total-row">
                        <span>Subtotal</span>
                        <strong>{money(order.subtotal)}</strong>
                      </div>
                      {hasMoneyField(order.shipping_total) ? (
                        <div className="dtb-order-total-row">
                          <span>Shipping</span>
                          <strong>{shippingTotal === 0 ? <span className="dtb-order-free">FREE</span> : money(shippingTotal)}</strong>
                        </div>
                      ) : null}
                      {hasMoneyField(order.total_tax) ? (
                        <div className="dtb-order-total-row">
                          <span>Tax</span>
                          <strong>{money(order.total_tax)}</strong>
                        </div>
                      ) : null}
                      <div className="dtb-order-total-row dtb-order-total-row--grand">
                        <span>Total</span>
                        <strong>{money(order.total)}</strong>
                      </div>
                    </div>
                  ) : null}
                </div>
              </section>

              <section className="dtb-order-sheet-section" aria-labelledby="contact-title">
                <header className="dtb-order-sheet-section__header">
                  <h2 id="contact-title" className="dtb-order-card__title">
                    <User size={20} /> Billing &amp; contact
                  </h2>
                </header>
                <div className="dtb-order-sheet-section__body">
                  <dl className="dtb-order-detail-list">
                    <DetailRow icon={Mail} label="Email">{billing.email}</DetailRow>
                    <DetailRow icon={User} label="Name">{billingName}</DetailRow>
                    <DetailRow icon={MapPin} label="Address">{billingAddress}</DetailRow>
                    <DetailRow icon={CreditCard} label="Payment">{order?.payment_method_title}</DetailRow>
                  </dl>
                </div>
              </section>
            </div>

            <section className="dtb-order-sheet-tracking" aria-labelledby="tracking-title">
              <div>
                <h2 id="tracking-title" className="dtb-order-card__title">
                  <Truck size={20} /> Tracking
                </h2>
                <p className="dtb-order-card-copy">
                  Shipment and fulfillment updates will appear on your tracking page as they become available.
                </p>
              </div>
              <Link to={trackingUrl} className="dtb-order-button dtb-order-button--secondary">
                View tracking status
              </Link>
            </section>
          </div>
        </section>

      </div>
    </div>
  );
}
