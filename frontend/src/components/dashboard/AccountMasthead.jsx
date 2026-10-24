/**
 * Reference-matched account masthead. Visual hierarchy follows the approved
 * dashboard mockup; all identity and counts are real authenticated data.
 */
import { ChevronRight, Headphones, MapPin, Package, RotateCcw, UserRound, Wrench } from 'lucide-react';
import { buildAccountActivity } from '../../utils/accountActivity.js';

const repairOrder = (order) => String(order?.order_type || '').toLowerCase() === 'repair_service';
const joinName = (user) => [user?.first_name, user?.last_name].filter(Boolean).join(' ').trim();

function StatusTile({ icon: Icon, tone, title, count, hint, loading, error, onClick }) {
  return (
    <button type="button" className="dtb-masthead-tile" onClick={onClick} aria-label={`View ${title}`}>
      <span className={`dtb-masthead-tile__icon dtb-masthead-tile__icon--${tone}`}><Icon size={22} strokeWidth={1.9}/></span>
      <span className="dtb-masthead-tile__body">
        <span className="dtb-masthead-tile__label">{title}</span>
        <strong className="dtb-masthead-tile__number" aria-live="polite">{loading ? '…' : error ? '—' : count}</strong>
        <span className="dtb-masthead-tile__hint">{error ? 'Unavailable' : loading ? 'Loading activity' : hint}</span>
      </span>
      <ChevronRight size={20} className="dtb-masthead-tile__chevron" aria-hidden="true"/>
    </button>
  );
}

export default function AccountMasthead({ user, history, loading, error, jobsTotal, jobsLoading, jobsError, onOpenTab }) {
  const fullName = joinName(user) || user?.display_name || 'Your account';
  const greeting = (user?.first_name || String(fullName).split(/\s+/)[0] || 'there').trim();
  const initials = (joinName(user) || fullName).split(/\s+/).slice(0, 2).map((part) => part[0] || '').join('').toUpperCase();
  const since = user?.registered ? new Date(user.registered) : null;
  const membership = since && !Number.isNaN(since.getTime())
    ? `Customer since ${since.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })}`
    : 'Drywall Toolbox account';
  const orderData = history?.orders || [];
  const productOrders = orderData.filter((order) => !repairOrder(order));
  const repairItems = buildAccountActivity({
    orders: orderData.filter(repairOrder), repairs: history?.repairs || [],
  });
  const accountRole = String(user?.roles?.[0] || 'customer').replace(/_/g, ' ');
  const location = [user?.billing_city, user?.billing_state].filter(Boolean).join(', ');
  return (
    <section className="dtb-masthead" aria-label="Your account summary">
      <div className="dtb-masthead__art" aria-hidden="true"><span/><span/><span/></div>
      <div className="dtb-masthead__inner dtb-account-container">
        <div className="dtb-masthead__top">
          <div className="dtb-masthead__intro">
            <p className="dtb-masthead__eyebrow">CONTRACTOR ACCOUNT</p>
            <h1>Welcome back, <span>{greeting}.</span></h1>
            <p className="dtb-masthead__subtitle">Your tools. Your orders. Your jobs. All in one place.</p>
          </div>
          <button type="button" className="dtb-masthead-profile" onClick={() => onOpenTab('settings')} aria-label="Open account settings">
            <div className="dtb-masthead-profile__upper">
              <span className="dtb-masthead-profile__avatar" aria-hidden="true">{initials || <UserRound size={24}/>}</span>
              <span className="dtb-masthead-profile__identity"><strong>{fullName}</strong><small>{membership}</small></span>
              <ChevronRight size={21} aria-hidden="true"/>
            </div>
            <div className="dtb-masthead-profile__details">
              <span><UserRound size={19} aria-hidden="true"/><span><small>ACCOUNT TYPE</small><strong>{accountRole}</strong></span></span>
              <span><MapPin size={19} aria-hidden="true"/><span><small>{location ? 'LOCATION' : 'YOUR ACCOUNT'}</small><strong>{location || 'Manage profile'}</strong></span></span>
            </div>
          </button>
        </div>
        <div className="dtb-masthead__tiles">
          <StatusTile icon={Package} tone="orders" title="RECENT ORDERS" count={productOrders.length} hint="View your orders" loading={loading} error={error} onClick={() => onOpenTab('orders')}/>
          <StatusTile icon={Wrench} tone="repairs" title="RECENT REPAIRS" count={repairItems.length} hint="Track tool service" loading={loading} error={error} onClick={() => onOpenTab('repairs')}/>
          <StatusTile icon={RotateCcw} tone="returns" title="RECENT RETURNS" count={(history?.returns || []).length} hint="View return requests" loading={loading} error={error} onClick={() => onOpenTab('returns')}/>
          <StatusTile icon={Headphones} tone="support" title="SUPPORT TICKETS" count={(history?.supportTickets || []).length} hint="View requests" loading={loading} error={error} onClick={() => onOpenTab('support')}/>
        </div>
      </div>
    </section>
  );
}
