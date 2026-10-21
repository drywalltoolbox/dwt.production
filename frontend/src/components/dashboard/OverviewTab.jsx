/**
 * frontend/src/components/dashboard/OverviewTab.jsx
 *
 * Dashboard Overview tab — stats, recent orders, account info.
 */

import { Link } from 'react-router-dom';
import { m as Motion } from 'framer-motion';
import {
  Package, Wrench, ShoppingCart, Headphones,
  ChevronRight, Loader, CreditCard, RotateCcw,
} from 'lucide-react';
import AccountActivityList from '../account/AccountActivityList.jsx';
import { buildAccountActivity } from '../../utils/accountActivity.js';
import { ORDER_TERMINAL_STATUSES } from '../../api/orders.js';
import { TERMINAL_STATUSES as REPAIR_TERMINAL_STATUSES } from '../../api/repairs.js';
import { RETURN_TERMINAL_STATUSES, SUPPORT_TERMINAL_STATUSES } from '../../api/statusTracking.js';
import { dashboardRevealVariants } from '../../motion/dtbMotion.js';

const CARD = {
  background:   'white',
  border:       '1px solid rgba(15,23,42,0.08)',
  borderRadius: '12px',
  boxShadow:    '0 2px 12px rgba(15,23,42,0.05)',
};

function StatCard( { icon, label, value, color, bg, delay, onClick } ) {
  const Icon = icon;
  return (
    <Motion.button type="button" custom={ delay } variants={ dashboardRevealVariants } initial="hidden" animate="visible"
      whileHover={ { y: -2 } } whileTap={ { scale: 0.98 } } onClick={ onClick }
      className="account-overview-stat"
      style={ { ...CARD, padding: '16px 18px', display: 'flex', alignItems: 'center', gap: '12px' } }
    >
      <div style={ {
        width: '40px', height: '40px', borderRadius: '10px',
        background: bg, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0,
      } }>
        <Icon size={ 18 } style={ { color } } />
      </div>
      <div>
        <p style={ { margin: 0, fontSize: '0.65rem', textTransform: 'uppercase', letterSpacing: '0.09em', color: 'rgba(15,23,42,0.42)', fontWeight: 700 } }>
          { label }
        </p>
        <p style={ { margin: '2px 0 0', fontSize: '1.35rem', fontWeight: 800, color: '#0f172a', lineHeight: 1 } }>
          { value }
        </p>
      </div>
      <ChevronRight size={ 16 } className="account-overview-stat__arrow" />
    </Motion.button>
  );
}

function isRepairOrder( order ) {
  const type = typeof order?.order_type === 'string' ? order.order_type.trim().toLowerCase() : '';
  return type === 'repair_service';
}

function ActivitySection({ title, icon: Icon, color, bg, items, emptyText, onViewAll, loading, delay }) {
  return (
    <Motion.section custom={delay} variants={dashboardRevealVariants} initial="hidden" animate="visible" style={{ ...CARD, padding: '18px 20px' }}>
      <div className="account-overview-section__header">
        <div className="account-overview-section__title">
          <span style={{ background: bg, color }}><Icon size={16} /></span>
          <strong>{title}</strong>
        </div>
        <button type="button" onClick={onViewAll}>View all <ChevronRight size={12} /></button>
      </div>
      {loading ? (
        <div className="account-overview-section__loading"><Loader size={15} className="animate-spin" /> Loading…</div>
      ) : items.length ? (
        <AccountActivityList items={items} limit={4} />
      ) : (
        <p className="account-overview-section__empty">{emptyText}</p>
      )}
    </Motion.section>
  );
}

export default function OverviewTab( { user, orders, repairs = [], returns = [], supportTickets = [], ordersLoading, onTabChange } ) {
  const nameParts = [ user.first_name, user.last_name ].filter( Boolean ).join( ' ' );
  const displayName = nameParts || user.display_name || user.email;

  const repairOrders   = orders.filter(isRepairOrder);
  const productOrders  = orders.filter((order) => !isRepairOrder(order));
  const orderActivity  = buildAccountActivity({ orders: productOrders });
  const repairActivity = buildAccountActivity({ orders: repairOrders, repairs });
  const returnActivity = buildAccountActivity({ returns });
  const activeOrders = productOrders.filter((order) => !ORDER_TERMINAL_STATUSES.includes(order.status));
  const activeRepairs = repairActivity.filter((item) => (
    item.type === 'repair-order'
      ? !ORDER_TERMINAL_STATUSES.includes(item.status)
      : !REPAIR_TERMINAL_STATUSES.includes(item.status)
  ));
  const activeReturns = returns.filter((item) => !RETURN_TERMINAL_STATUSES.includes(item.status));
  const activeSupportTickets = supportTickets.filter((ticket) => !SUPPORT_TERMINAL_STATUSES.includes(ticket.status));

  return (
    <div style={ { display: 'flex', flexDirection: 'column', gap: '18px' } }>

      {/* Stats row */}
      <div className="account-overview-stats">
        <StatCard icon={ Package } label="Orders" value={ ordersLoading ? '…' : String( activeOrders.length ) } color="#2255ee" bg="#eff6ff" delay={ 0 } onClick={ () => onTabChange( 1 ) } />
        <StatCard icon={ Wrench } label="Repairs" value={ ordersLoading ? '…' : String( activeRepairs.length ) } color="#0284c7" bg="#ecfeff" delay={ 0.05 } onClick={ () => onTabChange( 2 ) } />
        <StatCard icon={ RotateCcw } label="Returns" value={ ordersLoading ? '…' : String( activeReturns.length ) } color="#7c3aed" bg="#f5f3ff" delay={ 0.1 } onClick={ () => onTabChange( 3 ) } />
        <StatCard icon={ Headphones } label="Support Tickets" value={ ordersLoading ? '…' : String( activeSupportTickets.length ) } color="#d97706" bg="#fffbeb" delay={ 0.15 } onClick={ () => onTabChange( 4 ) } />
      </div>

      <ActivitySection title="Recent Orders" icon={Package} color="#2255ee" bg="#eff6ff" items={orderActivity} emptyText="No product orders yet." onViewAll={() => onTabChange(1)} loading={ordersLoading} delay={0.24} />

      <div className="account-overview-service-grid">
        <ActivitySection title="Recent Repairs" icon={Wrench} color="#0284c7" bg="#ecfeff" items={repairActivity} emptyText="No repair activity yet." onViewAll={() => onTabChange(2)} loading={ordersLoading} delay={0.28} />
        <ActivitySection title="Recent Returns" icon={RotateCcw} color="#7c3aed" bg="#f5f3ff" items={returnActivity} emptyText="No return activity yet." onViewAll={() => onTabChange(3)} loading={ordersLoading} delay={0.32} />
      </div>

      {/* Account info */}
      <Motion.div custom={ 0.3 } variants={ dashboardRevealVariants } initial="hidden" animate="visible"
        style={ { ...CARD, padding: '18px 20px' } }
      >
        <div style={ { display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '14px' } }>
          <div style={ { width: '34px', height: '34px', borderRadius: '8px', background: '#f8fafc', display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
            <CreditCard size={ 16 } style={ { color: '#64748b' } } />
          </div>
          <span style={ { fontSize: '0.92rem', fontWeight: 700, color: '#0f172a' } }>Account Information</span>
        </div>

        <div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '16px' } }>
          { [
            { label: 'Full Name',    value: displayName },
            { label: 'Email',        value: user.email  },
            { label: 'Account Type', value: ( user.roles?.[0] || '' ).replace( /_/g, ' ' ).replace( /\b\w/g, ( c ) => c.toUpperCase() ) || '—' },
            { label: 'Member Since', value: user.registered
                ? new Date( user.registered ).toLocaleDateString( 'en-US', { year: 'numeric', month: 'long' } ) : '—' },
          ].map( ( row ) => (
            <div key={ row.label }>
              <p style={ { margin: '0 0 3px', fontSize: '0.65rem', textTransform: 'uppercase', letterSpacing: '0.09em', fontWeight: 700, color: 'rgba(15,23,42,0.38)' } }>{ row.label }</p>
              <p style={ { margin: 0, fontSize: '0.86rem', color: '#0f172a', fontWeight: 500, wordBreak: 'break-word' } }>{ row.value }</p>
            </div>
          ) ) }
        </div>
      </Motion.div>

      {/* Quick links */}
      <Motion.div custom={ 0.36 } variants={ dashboardRevealVariants } initial="hidden" animate="visible"
        style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))', gap: '10px' } }
      >
        { [
          { icon: ShoppingCart, label: 'Browse Products', to: '/products',  color: '#2255ee', bg: '#eff6ff' },
          { icon: ShoppingCart, label: 'View Cart',        to: '/cart',      color: '#ea580c', bg: '#fff7ed' },
          { icon: Wrench,       label: 'Book a Repair',    to: '/repairs',   color: '#16a34a', bg: '#f0fdf4' },
        ].map( ( action ) => (
          <Link key={ action.to } to={ action.to } style={ { textDecoration: 'none' } }>
            <div style={ {
              display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
              gap: '8px', padding: '16px 12px',
              background: 'white', border: '1px solid rgba(15,23,42,0.07)', borderRadius: '10px',
              textAlign: 'center', transition: 'border-color 0.15s, box-shadow 0.15s, transform 0.12s', cursor: 'pointer',
            } }
              onMouseEnter={ ( e ) => { e.currentTarget.style.borderColor = action.color; e.currentTarget.style.boxShadow = `0 4px 14px ${ action.color }22`; e.currentTarget.style.transform = 'translateY(-1px)'; } }
              onMouseLeave={ ( e ) => { e.currentTarget.style.borderColor = 'rgba(15,23,42,0.07)'; e.currentTarget.style.boxShadow = 'none'; e.currentTarget.style.transform = 'translateY(0)'; } }
            >
              <div style={ { width: '36px', height: '36px', borderRadius: '9px', background: action.bg, display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
                <action.icon size={ 16 } style={ { color: action.color } } />
              </div>
              <span style={ { fontSize: '0.75rem', fontWeight: 650, color: '#374151' } }>{ action.label }</span>
            </div>
          </Link>
        ) ) }
      </Motion.div>

    </div>
  );
}
