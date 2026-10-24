/**
 * Contractor-focused account overview. One composition of data already loaded
 * by AccountHub: no independent API calls, no copied commerce state.
 */
import { Link } from 'react-router-dom';
import {
  ArrowRight, ChevronRight, Headphones, MapPin, NotebookPen, Package,
  PackageOpen, RotateCcw, Search, Settings2, Wrench,
} from 'lucide-react';
import AccountActivityList from '../account/AccountActivityList.jsx';
import { buildAccountActivity } from '../../utils/accountActivity.js';
import { ORDER_TERMINAL_STATUSES } from '../../api/orders.js';
import { TERMINAL_STATUSES as REPAIR_TERMINAL_STATUSES } from '../../api/repairs.js';

const isRepairOrder = (order) => String(order?.order_type || '').toLowerCase() === 'repair_service';

function OverviewStat({ label, count, icon: Icon, type = '', onClick, loading, error }) {
  return (
    <button type="button" className="dtb-account-card dtb-account-stat" onClick={onClick}
      aria-label={`Open ${label}`}>
      <span className={`dtb-account-stat__icon ${type ? `is-${type}` : ''}`}><Icon size={19}/></span>
      <span className="dtb-account-stat__text"><strong>{loading ? '…' : error ? '—' : count}</strong><span>{label}</span></span>
      <ChevronRight className="dtb-account-stat__arrow" size={17}/>
    </button>
  );
}

function EmptyState({ icon: Icon, title, description, action, onAction }) {
  return <div className="dtb-account-empty"><span className="dtb-account-empty__icon"><Icon size={21}/></span><div className="dtb-account-empty__copy"><strong>{title}</strong><p>{description}</p>{action && <button type="button" className="dtb-account-panel__link" onClick={onAction}>{action} <ArrowRight size={14}/></button>}</div></div>;
}

function ActivityPanel({ title, items, loading, error, onViewAll, icon: Icon, emptyTitle, emptyDescription, emptyAction }) {
  return (
    <section className="dtb-account-card dtb-account-panel">
      <header className="dtb-account-panel__header"><h2>{title}</h2><button className="dtb-account-panel__link" type="button" onClick={onViewAll}>View all <ChevronRight size={15}/></button></header>
      {loading ? <div role="status" className="dtb-account-loading">Loading {title.toLowerCase()}…</div> :
        error ? <div role="alert" className="dtb-account-error">Unable to load {title.toLowerCase()}. Open the section to try again.</div> :
          items.length > 0 ? <AccountActivityList items={items} limit={3}/> :
            <EmptyState icon={Icon} title={emptyTitle} description={emptyDescription} action={emptyAction} onAction={onViewAll}/>}
    </section>
  );
}

export default function OverviewTab({
  user, orders = [], repairs = [], returns = [], supportTickets = [],
  jobs = [], jobsTotal = 0, jobsLoading = false, jobsError = '',
  ordersLoading = false, errors = {}, onOpenTab,
}) {
  const firstName = (user?.first_name || '').trim();
  const fallbackName = String(user?.display_name || '').trim().split(/\s+/)[0];
  const greeting = firstName || fallbackName || 'there';
  const productOrders = orders.filter((order) => !isRepairOrder(order));
  const repairOrders = orders.filter(isRepairOrder);
  const orderActivity = buildAccountActivity({ orders: productOrders });
  const repairActivity = buildAccountActivity({ orders: repairOrders, repairs });
  const returnActivity = buildAccountActivity({ returns });
  const activeOrders = productOrders.filter((order) => !ORDER_TERMINAL_STATUSES.includes(order.status));
  const activeRepairs = repairActivity.filter((item) =>
    item.type === 'repair-order'
      ? !ORDER_TERMINAL_STATUSES.includes(item.status)
      : !REPAIR_TERMINAL_STATUSES.includes(item.status));

  return (
    <div className="dtb-account-overview">
      <header className="dtb-account-welcome">
        <div><span className="dtb-account-eyebrow">CONTRACTOR HUB</span><h1>Welcome back, {greeting}.</h1><p>Your orders, jobs, and tools—all in one place.</p></div>
        <span className="dtb-account-avatar" aria-hidden="true">{greeting[0].toUpperCase()}</span>
      </header>

      <div className="dtb-account-grid">
        <OverviewStat label="Active Orders" icon={Package} count={activeOrders.length} loading={ordersLoading} error={errors.orders} onClick={() => onOpenTab('orders')}/>
        <OverviewStat label="Tool Repairs" icon={Wrench} type="repair" count={activeRepairs.length} loading={ordersLoading} error={errors.repairs} onClick={() => onOpenTab('repairs')}/>
        <OverviewStat label="My Jobs" icon={MapPin} type="jobs" count={jobsTotal} loading={jobsLoading} error={jobsError} onClick={() => onOpenTab('jobs')}/>
      </div>

      <div className="dtb-account-layout">
        <div className="dtb-account-stack">
          <ActivityPanel title="Recent Orders" icon={PackageOpen} items={orderActivity} loading={ordersLoading} error={errors.orders}
            emptyTitle="No orders yet" emptyDescription="Your orders and shipment details will appear here."
            emptyAction="Browse tools" onViewAll={() => onOpenTab('orders')}/>
          <ActivityPanel title="Tool Repairs" icon={Wrench} items={repairActivity} loading={ordersLoading} error={errors.repairs}
            emptyTitle="No tool repairs yet" emptyDescription="Track service requests or get your equipment serviced."
            emptyAction="View repairs" onViewAll={() => onOpenTab('repairs')}/>
        </div>

        <div className="dtb-account-stack">
          <section className="dtb-account-card dtb-account-panel">
            <header className="dtb-account-panel__header"><h2>My Jobs</h2><button className="dtb-account-panel__link" type="button" onClick={() => onOpenTab('jobs')}>View all <ChevronRight size={15}/></button></header>
            {jobsLoading ? <div role="status" className="dtb-account-loading">Loading jobs…</div> :
              jobsError ? <div role="alert" className="dtb-account-error">Unable to load your jobs. Open My Jobs to retry.</div> :
                jobs.length ? <div className="dtb-account-job-list">{jobs.map((job) => <button type="button" key={job.id} onClick={() => onOpenTab('jobs')}><MapPin size={16}/><span><strong>{job.name}</strong><small>{job.location || 'No location added'}</small></span><ChevronRight size={16}/></button>)}</div> :
                  <EmptyState icon={NotebookPen} title="Keep your jobsites organized" description="Save job locations and notes to find them later." action="Create your first job" onAction={() => onOpenTab('jobs')}/>}
          </section>

          <section className="dtb-account-card dtb-account-panel">
            <header className="dtb-account-panel__header"><h2>Quick Access</h2></header>
            <div className="dtb-account-quick">
              <Link to="/products"><Search size={17}/> Find Tools & Parts</Link>
              <Link to="/repairs"><Wrench size={17}/> Request Repair</Link>
              <Link to="/cart"><Package size={17}/> View Cart</Link>
              <button type="button" onClick={() => onOpenTab('support')} className="dtb-account-quick-link"><Headphones size={17}/> Get Support</button>
            </div>
          </section>
          <ActivityPanel title="Recent Returns" icon={RotateCcw} items={returnActivity} loading={ordersLoading} error={errors.returns}
            emptyTitle="No recent returns" emptyDescription="Return requests will appear here when needed."
            onViewAll={() => onOpenTab('returns')}/>
        </div>
      </div>

      <div className="dtb-account-bottom">
        <button type="button" className="dtb-account-panel__link" onClick={() => onOpenTab('settings')}><Settings2 size={16}/> Manage account settings <ArrowRight size={15}/></button>
      </div>
    </div>
  );
}
