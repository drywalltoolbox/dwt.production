/**
 * Unified customer account workspace at /dashboard.
 * URL is the single navigation authority, and the existing DTB history read
 * model is the single API for order/repair/return/support summary data.
 */
import { useEffect, useRef, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Headphones, LayoutDashboard, LogOut, MapPin, Package, RotateCcw, Settings, Wrench } from 'lucide-react';
import { useAuthContext } from '../../auth/AuthContext.js';
import { getAccountWorkspaceHistory } from '../../api/accountWorkspace.js';
import { listJobs } from '../../api/jobs.js';
import NavbarTabs from '../ui/NavbarTabs.jsx';
import OverviewTab from './OverviewTab.jsx';
import AccountMasthead from './AccountMasthead.jsx';
import JobsTab from './JobsTab.jsx';
import OrdersTab from './OrdersTab.jsx';
import RepairsTab from './RepairsTab.jsx';
import ReturnsTab from './ReturnsTab.jsx';
import SupportTicketsTab from './SupportTicketsTab.jsx';
import SettingsTab from './SettingsTab.jsx';
import '../../styles/dashboard-experience.css';

const TABS = [
  { id: 'overview', label: 'Overview', icon: LayoutDashboard },
  { id: 'jobs', label: 'My Jobs', icon: MapPin },
  { id: 'orders', label: 'Orders', icon: Package },
  { id: 'repairs', label: 'Repairs', icon: Wrench },
  { id: 'returns', label: 'Returns', icon: RotateCcw },
  { id: 'support', label: 'Support Tickets', icon: Headphones },
  { id: 'settings', label: 'Settings', icon: Settings },
];

const EMPTY_HISTORY = { orders: [], repairs: [], returns: [], supportTickets: [] };

export default function AccountHub() {
  const { user, isAuthenticated, isLoading, logout } = useAuthContext();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const requested = searchParams.get('tab');
  const activeTabId = requested === 'addresses' ? 'settings'
    : TABS.some((tab) => tab.id === requested) ? requested : 'overview';
  const activeIndex = TABS.findIndex((tab) => tab.id === activeTabId);
  const [history, setHistory] = useState(EMPTY_HISTORY);
  const [heroSummary, setHeroSummary] = useState(null);
  const [historyError, setHistoryError] = useState('');
  const [historyLoading, setHistoryLoading] = useState(true);
  const [jobsSummary, setJobsSummary] = useState({ jobs: [], total: 0 });
  const [jobsError, setJobsError] = useState('');
  const [jobsLoading, setJobsLoading] = useState(true);
  const [jobsRevision, setJobsRevision] = useState(0);
  const accountNavRef = useRef(null);
  const accountMainRef = useRef(null);
  const pendingTabScrollRef = useRef(null);
  const [signingOut, setSigningOut] = useState(false);
  const [signOutError, setSignOutError] = useState('');

  useEffect(() => {
    if (!isLoading && !isAuthenticated) navigate('/login', { replace: true });
  }, [isLoading, isAuthenticated, navigate]);

  // Customer-scoped history. Only the overview consumes the aggregate. Other
  // tabs retain their authoritative domain reads for pagination/detail actions.
  useEffect(() => {
    if (!user?.id) return undefined;
    let alive = true;
    Promise.resolve().then(() => {
      if (!alive) return;
      setHistoryLoading(true);
      setHistoryError('');
      return getAccountWorkspaceHistory({ perPage: 30 })
        .then((data) => { if (alive) { setHistory(data); setHeroSummary(data.summary); } })
        .catch((error) => { if (alive) { setHistory(EMPTY_HISTORY); setHeroSummary(null); setHistoryError(error?.message || 'Account history is unavailable.'); } })
        .finally(() => { if (alive) setHistoryLoading(false); });
    });
    return () => { alive = false; };
  }, [user?.id]);

  useEffect(() => {
    if (!user?.id || activeTabId !== 'overview') return undefined;
    let alive = true;
    Promise.resolve().then(() => {
      if (!alive) return;
      setJobsLoading(true);
      setJobsError('');
      return listJobs({ page: 1, perPage: 3 })
        .then((data) => { if (alive) setJobsSummary({ jobs: data.jobs || [], total: Number(data.total) || 0 }); })
        .catch((error) => { if (alive) { setJobsSummary({ jobs: [], total: 0 }); setJobsError(error?.message || 'My Jobs is unavailable.'); } })
        .finally(() => { if (alive) setJobsLoading(false); });
    });
    return () => { alive = false; };
  }, [user?.id, jobsRevision]);

  // Scroll only after an explicit account-tab action, never on initial load or
  // browser history navigation. Respect reduced-motion preferences.
  useEffect(() => {
    if (pendingTabScrollRef.current !== activeTabId) return undefined;
    pendingTabScrollRef.current = null;
    const frame = window.requestAnimationFrame(() => {
      accountMainRef.current?.focus({ preventScroll: true });
      accountNavRef.current?.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        block: 'start',
      });
    });
    return () => window.cancelAnimationFrame(frame);
  }, [activeTabId]);

  const openTab = (id) => {
    const tab = TABS.find((entry) => entry.id === id);
    if (!tab) return;
    pendingTabScrollRef.current = id;
    if (activeTabId === id) {
      accountMainRef.current?.focus({ preventScroll: true });
      accountNavRef.current?.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        block: 'start',
      });
      pendingTabScrollRef.current = null;
      return;
    }
    const next = new URLSearchParams(searchParams);
    if (id === 'overview') next.delete('tab');
    else next.set('tab', id);
    setSearchParams(next, { replace: true });
  };

  const signOut = async () => {
    if (signingOut) return;
    setSigningOut(true);
    setSignOutError('');
    try { await logout(); navigate('/', { replace: true }); }
    catch (error) { setSignOutError(error?.message || 'Could not sign out. Please retry.'); }
    finally { setSigningOut(false); }
  };

  if (isLoading || !user) {
    return <div className="dtb-account-page" style={{ minHeight: '50vh', display: 'grid', placeItems: 'center' }} role="status">Loading your account…</div>;
  }

  return (
    <div className="page-wrapper dtb-account-page">
      <AccountMasthead user={user} metrics={heroSummary} metricsLoading={historyLoading} onOpenTab={openTab}/>
      <nav ref={accountNavRef} className="dtb-account-container dtb-account-nav" aria-label="Account sections">
        <NavbarTabs tabs={TABS} activeIndex={activeIndex} onChange={(index) => openTab(TABS[index]?.id)} className="dtb-account-reference-tabs"/>
      </nav>
      <main ref={accountMainRef} tabIndex={-1} className="dtb-account-container dtb-account-main" id="account-main">
        {activeTabId === 'overview' && (
          <OverviewTab user={user}
            orders={history.orders} repairs={history.repairs} returns={history.returns}
            supportTickets={history.supportTickets} ordersLoading={historyLoading}
            errors={historyError ? { orders: historyError, repairs: historyError, returns: historyError, support: historyError } : {}}
            jobs={jobsSummary.jobs} jobsTotal={jobsSummary.total}
            jobsLoading={jobsLoading} jobsError={jobsError} onOpenTab={openTab}/>
        )}
        {activeTabId === 'jobs' && <JobsTab onJobsChanged={() => setJobsRevision((revision) => revision + 1)}/>}
        {activeTabId === 'orders' && <OrdersTab userId={user.id}/>}
        {activeTabId === 'repairs' && <RepairsTab userId={user.id}/>}
        {activeTabId === 'returns' && <ReturnsTab/>}
        {activeTabId === 'support' && <SupportTicketsTab/>}
        {activeTabId === 'settings' && <SettingsTab user={user}/>}
        <div className="dtb-account-logout">
          <button type="button" onClick={signOut} disabled={signingOut}><LogOut size={16}/>{signingOut ? 'Signing out…' : 'Sign out'}</button>
        </div>
        {signOutError && <p role="alert" className="dtb-jobs-error">{signOutError}</p>}
      </main>
    </div>
  );
}
