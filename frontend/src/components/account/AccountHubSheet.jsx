import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Home, Package, User, X, ShoppingBag, ChevronRight, AlertCircle, Loader, Wrench, RotateCcw, ChevronDown, LayoutDashboard, Calculator, LifeBuoy, BookOpen, Bell, CheckCheck, Eye, EyeOff, LockKeyhole, Mail, UserRound } from 'lucide-react';
import { getAccountWorkspaceHistory } from '../../api/accountWorkspace.js';
import { getRecentlyViewed } from '../../utils/recentlyViewed.js';
import { buildAccountActivity } from '../../utils/accountActivity.js';
import AccountActivityList from './AccountActivityList.jsx';

const TABS = [
  { id: 'home',    label: 'Home',    Icon: Home },
  { id: 'orders',  label: 'Orders',  Icon: Package },
  { id: 'account', label: 'Account', Icon: User },
];

const HISTORY_FILTERS = [
  { id: 'product', label: 'Product' },
  { id: 'repairs', label: 'Repairs' },
  { id: 'returns', label: 'Returns' },
  { id: 'support', label: 'Support' },
];

function AccountHubHero({ eyebrow, title, subtitle, Icon }) {
  return (
    <header className="account-hub__hero">
      <div className="account-hub__hero-pattern" aria-hidden="true" />
      <span className="account-hub__hero-icon"><Icon size={22} /></span>
      <div className="account-hub__hero-copy">
        {eyebrow ? <p>{eyebrow}</p> : null}
        <h2>{title}</h2>
        {subtitle ? <span>{subtitle}</span> : null}
      </div>
    </header>
  );
}

function AccountHubAccordion({ title, Icon, open, onToggle, links, onNavigate }) {
  return (
    <section className={`account-hub__accordion${open ? ' is-open' : ''}`}>
      <button type="button" className="account-hub__accordion-trigger" onClick={onToggle} aria-expanded={open}>
        <span className="account-hub__accordion-title"><Icon size={18} />{title}</span>
        <ChevronDown size={17} />
      </button>
      <div className="account-hub__accordion-panel">
        <div>
          {links.map(({ to, label, icon: LinkIcon }) => (
            <Link key={to} to={to} onClick={onNavigate} className="account-hub__accordion-link">
              <span>{LinkIcon ? <LinkIcon size={16} /> : null}{label}</span>
              <ChevronRight size={15} />
            </Link>
          ))}
        </div>
      </div>
    </section>
  );
}

function RecentlyViewedTile({ product, onClose }) {
  return (
    <Link
      to={`/products/${product.slug}`}
      onClick={onClose}
      style={{ display: 'flex', alignItems: 'center', gap: '10px', padding: '8px 10px', borderRadius: '8px', textDecoration: 'none', transition: 'background 0.12s' }}
      onMouseEnter={(e) => { e.currentTarget.style.background = '#f1f5f9'; }}
      onMouseLeave={(e) => { e.currentTarget.style.background = 'transparent'; }}
    >
      <div style={{ width: '40px', height: '40px', borderRadius: '6px', background: '#f8fafc', border: '1px solid rgba(15,23,42,0.08)', flexShrink: 0, overflow: 'hidden', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        {product.image
          ? <img src={product.image} alt={product.name} style={{ width: '100%', height: '100%', objectFit: 'contain' }} />
          : <Package size={18} style={{ color: 'rgba(15,23,42,0.2)' }} />
        }
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <p style={{ margin: 0, fontSize: '0.8rem', fontWeight: 600, color: '#0f172a', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{product.name}</p>
        {product.price && <p style={{ margin: '1px 0 0', fontSize: '0.72rem', color: 'rgba(15,23,42,0.5)' }}>{product.price}</p>}
      </div>
      <ChevronRight size={14} strokeWidth={2.5} style={{ color: 'rgba(15,23,42,0.25)', flexShrink: 0 }} />
    </Link>
  );
}

function GuestAuthForm({ mode, onModeChange, onLogin, onRegister, authLoading }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const busy = submitting || authLoading;

  const changeMode = (nextMode) => {
    setSubmitError('');
    setPassword('');
    setConfirmPassword('');
    setShowPassword(false);
    onModeChange(nextMode);
  };

  const handleSubmit = async (event) => {
    event.preventDefault();
    setSubmitError('');
    if (mode === 'register' && password !== confirmPassword) {
      setSubmitError('Passwords do not match.');
      return;
    }
    if (mode === 'register' && password.length < 8) {
      setSubmitError('Choose a password with at least eight characters.');
      return;
    }

    setSubmitting(true);
    try {
      if (mode === 'login') {
        await onLogin(email.trim(), password);
      } else {
        await onRegister({ firstName: firstName.trim(), lastName: lastName.trim(), email: email.trim(), password });
      }
    } catch (error) {
      setSubmitError(error?.message || (mode === 'login' ? 'Sign in failed. Please try again.' : 'Account creation failed. Please try again.'));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <section className="account-hub-auth" aria-labelledby="account-hub-auth-title">
      <div className="account-hub-auth__switcher" role="tablist" aria-label="Account access">
        <button type="button" role="tab" aria-selected={mode === 'login'} className={mode === 'login' ? 'is-active' : ''} onClick={() => changeMode('login')} disabled={busy}>Sign In</button>
        <button type="button" role="tab" aria-selected={mode === 'register'} className={mode === 'register' ? 'is-active' : ''} onClick={() => changeMode('register')} disabled={busy}>Create Account</button>
      </div>
      <header className="account-hub-auth__header">
        <span aria-hidden="true"><UserRound size={20} /></span>
        <div>
          <h3 id="account-hub-auth-title">{mode === 'login' ? 'Welcome back' : 'Join Drywall Toolbox'}</h3>
          <p>{mode === 'login' ? 'Sign in to access your orders and services.' : 'Create one account for orders, repairs, and support.'}</p>
        </div>
      </header>
      {submitError ? <div className="account-hub-auth__error" role="alert"><AlertCircle size={17} /><span>{submitError}</span></div> : null}
      <form className="account-hub-auth__form" onSubmit={handleSubmit}>
        {mode === 'register' ? (
          <div className="account-hub-auth__name-grid">
            <label><span>First name</span><div><UserRound size={17} /><input value={firstName} onChange={(event) => setFirstName(event.target.value)} autoComplete="given-name" disabled={busy} required /></div></label>
            <label><span>Last name</span><div><UserRound size={17} /><input value={lastName} onChange={(event) => setLastName(event.target.value)} autoComplete="family-name" disabled={busy} required /></div></label>
          </div>
        ) : null}
        <label><span>Email</span><div><Mail size={17} /><input type="email" value={email} onChange={(event) => setEmail(event.target.value)} autoComplete="email" inputMode="email" placeholder="name@example.com" disabled={busy} required /></div></label>
        <label><span>Password</span><div><LockKeyhole size={17} /><input type={showPassword ? 'text' : 'password'} value={password} onChange={(event) => setPassword(event.target.value)} autoComplete={mode === 'login' ? 'current-password' : 'new-password'} placeholder={mode === 'login' ? 'Enter your password' : 'At least 8 characters'} minLength={mode === 'register' ? 8 : undefined} disabled={busy} required /><button type="button" onClick={() => setShowPassword((visible) => !visible)} aria-label={showPassword ? 'Hide password' : 'Show password'} aria-pressed={showPassword} disabled={busy}>{showPassword ? <EyeOff size={17} /> : <Eye size={17} />}</button></div></label>
        {mode === 'register' ? <label><span>Confirm password</span><div><LockKeyhole size={17} /><input type={showPassword ? 'text' : 'password'} value={confirmPassword} onChange={(event) => setConfirmPassword(event.target.value)} autoComplete="new-password" disabled={busy} required /></div></label> : null}
        <button className="account-hub-auth__submit" type="submit" disabled={busy}>{busy ? <><Loader size={17} className="animate-spin" /> {mode === 'login' ? 'Signing in…' : 'Creating account…'}</> : mode === 'login' ? 'Sign In' : 'Create Account'}</button>
      </form>
      {mode === 'login' ? <Link className="account-hub-auth__forgot" to="/forgot-password">Forgot your password?</Link> : <p className="account-hub-auth__legal">By creating an account you agree to our terms of service and privacy policy.</p>}
    </section>
  );
}

function RecentlyViewedSection({ recentlyViewed, closeSheet, navigate }) {
  return (
    <section className="account-hub__list-section">
      <button type="button" className="account-hub__section-header" onClick={() => { closeSheet(); navigate('/products'); }}>
        <span>Recently viewed</span>
        <ChevronRight size={16} strokeWidth={2.5} />
      </button>
      {recentlyViewed.length > 0 ? (
        <div className="account-hub__recently-viewed-list">
          {recentlyViewed.slice(0, 4).map((p) => <RecentlyViewedTile key={p.id} product={p} onClose={closeSheet} />)}
        </div>
      ) : (
        <div className="account-hub__empty-card">
          <div className="account-hub__empty-icon"><ShoppingBag size={26} strokeWidth={1.5} /></div>
          <p>Recently viewed products will appear here.</p>
        </div>
      )}
    </section>
  );
}

function filterAccountHistory(activity, filter) {
  if (filter === 'product') return activity.filter((item) => item.type === 'order');
  if (filter === 'repairs') return activity.filter((item) => item.type === 'repair' || item.type === 'repair-order');
  if (filter === 'returns') return activity.filter((item) => item.type === 'return');
  if (filter === 'support') return activity.filter((item) => item.type === 'support');
  return activity;
}

function historyFilterError(errors, filter) {
  if (filter === 'product') return errors.orders;
  if (filter === 'repairs') return errors.repairs;
  if (filter === 'returns') return errors.returns;
  if (filter === 'support') return errors.support;
  return Object.values(errors).filter(Boolean).join(' ');
}

function historyFilterEmptyCopy(filter) {
  if (filter === 'product') return 'Product orders will appear here after checkout.';
  if (filter === 'repairs') return 'Repair requests and repair service orders will appear here.';
  if (filter === 'returns') return 'Return requests will appear here.';
  if (filter === 'support') return 'Support tickets and service conversations will appear here.';
  return 'Product orders, repair requests, returns, and support tickets will appear here.';
}

export default function AccountHubSheet({ isOpen, onClose, user, onLogin, onRegister, authLoading, onLogout, onUnreadCountChange }) {
  const navigate = useNavigate();
  const sheetRef = useRef(null);
  const closeButtonRef = useRef(null);
  const previouslyFocusedRef = useRef(null);
  const [activeTab, setActiveTab] = useState('home');
  const [historyFilter, setHistoryFilter] = useState('product');
  const [recentlyViewed, setRecentlyViewed] = useState([]);
  const [orders, setOrders] = useState([]);
  const [repairs, setRepairs] = useState([]);
  const [returns, setReturns] = useState([]);
  const [supportTickets, setSupportTickets] = useState([]);
  const [ordersLoading, setOrdersLoading] = useState(false);
  const [historyErrors, setHistoryErrors] = useState({ orders: '', repairs: '', returns: '', support: '' });
  const [accountSections, setAccountSections] = useState({ services: false, support: false });
  const [readNotificationKeys, setReadNotificationKeys] = useState([]);
  const [notificationReadUserId, setNotificationReadUserId] = useState('');
  const [isSigningOut, setIsSigningOut] = useState(false);
  const [signOutError, setSignOutError] = useState('');
  const [guestAuthMode, setGuestAuthMode] = useState('login');

  const showOrdersTab = useCallback((filter = 'product') => {
    setHistoryFilter(filter);
    setActiveTab('orders');
  }, []);

  const closeSheet = useCallback(() => {
    setActiveTab('home');
    setHistoryFilter('product');
    setAccountSections({ services: false, support: false });
    onClose?.();
  }, [onClose]);

  const handleSignOut = useCallback(async () => {
    if (isSigningOut) return;
    setIsSigningOut(true);
    setSignOutError('');
    try {
      await onLogout?.();
      closeSheet();
    } catch (error) {
      setSignOutError(error?.message || 'Unable to sign out securely. Please try again.');
    } finally {
      setIsSigningOut(false);
    }
  }, [closeSheet, isSigningOut, onLogout]);

  const applyHistoryResults = useCallback((payload) => {
    setOrders(payload.orders || []);
    setRepairs(payload.repairs || []);
    setReturns(payload.returns || []);
    setSupportTickets(payload.supportTickets || []);
    setHistoryErrors({ orders: '', repairs: '', returns: '', support: '' });
  }, []);

  const showHistoryFailure = useCallback((error) => {
    const message = error?.message || 'Account history is temporarily unavailable.';
    setHistoryErrors({ orders: message, repairs: message, returns: message, support: message });
  }, []);

  const loadOrders = useCallback(async () => {
    if (!user?.id) return;
    setOrdersLoading(true);
    setHistoryErrors({ orders: '', repairs: '', returns: '', support: '' });
    try {
      applyHistoryResults(await getAccountWorkspaceHistory({ perPage: 20 }));
    } catch (error) {
      showHistoryFailure(error);
    } finally {
      setOrdersLoading(false);
    }
  }, [applyHistoryResults, showHistoryFailure, user?.id]);

  useEffect(() => {
    if (!isOpen) return;
    Promise.resolve(getRecentlyViewed()).then(setRecentlyViewed);
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen || !user?.id) return undefined;
    let cancelled = false;
    Promise.resolve().then(() => {
      if (cancelled) return;
      setOrdersLoading(true);
      setHistoryErrors({ orders: '', repairs: '', returns: '', support: '' });
      return getAccountWorkspaceHistory({ perPage: 20 })
        .then((result) => { if (!cancelled) applyHistoryResults(result); })
        .catch((error) => { if (!cancelled) showHistoryFailure(error); })
        .finally(() => { if (!cancelled) setOrdersLoading(false); });
    });
    return () => { cancelled = true; };
  }, [isOpen, user?.id, applyHistoryResults, showHistoryFailure]);

  useEffect(() => {
    if (!isOpen) return undefined;
    const previousOverflow = document.body.style.overflow;
    previouslyFocusedRef.current = document.activeElement;
    const focusTimer = window.requestAnimationFrame(() => closeButtonRef.current?.focus?.());
    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        closeSheet();
        return;
      }
      if (event.key !== 'Tab' || !sheetRef.current) return;

      const focusable = Array.from(sheetRef.current.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )).filter((element) => element.getClientRects().length > 0);
      if (!focusable.length) return;

      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };
    document.body.style.overflow = 'hidden';
    window.addEventListener('keydown', onKeyDown);
    return () => {
      window.cancelAnimationFrame(focusTimer);
      document.body.style.overflow = previousOverflow;
      window.removeEventListener('keydown', onKeyDown);
      previouslyFocusedRef.current?.focus?.();
    };
  }, [isOpen, closeSheet]);

  const displayName = useMemo(() => [user?.first_name, user?.last_name].filter(Boolean).join(' ') || user?.display_name || 'My Account', [user]);
  const firstName = useMemo(() => {
    const preferredName = user?.first_name || user?.display_name || user?.name || displayName;
    return String(preferredName).trim().split(/\s+/)[0] || 'there';
  }, [displayName, user]);
  const activity = useMemo(() => buildAccountActivity({ orders, repairs, returns, supportTickets }), [orders, repairs, returns, supportTickets]);
  const notifications = useMemo(() => activity.slice(0, 6), [activity]);
  const notificationKeys = useMemo(() => notifications.map((item) => `${item.id}:${item.status}:${item.sortDate}`), [notifications]);
  const unreadCount = useMemo(() => notificationReadUserId === String(user?.id || '') ? notificationKeys.filter((key) => !readNotificationKeys.includes(key)).length : 0, [notificationKeys, notificationReadUserId, readNotificationKeys, user?.id]);
  const filteredHistoryActivity = useMemo(() => filterAccountHistory(activity, historyFilter), [activity, historyFilter]);
  const historyErrorText = useMemo(() => Object.values(historyErrors).filter(Boolean).join(' '), [historyErrors]);
  const activeHistoryError = useMemo(() => historyFilterError(historyErrors, historyFilter), [historyErrors, historyFilter]);
  const allHistoryFailed = Boolean(historyErrorText) && activity.length === 0;

  useEffect(() => {
    if (!user?.id || ordersLoading) return;
    let cancelled = false;
    window.queueMicrotask(() => {
      if (cancelled) return;
      const storageKey = `dtb-account-notifications-read:${user.id}`;
      try {
        const stored = window.localStorage.getItem(storageKey);
        if (stored === null) {
          window.localStorage.setItem(storageKey, JSON.stringify(notificationKeys));
          setReadNotificationKeys(notificationKeys);
          setNotificationReadUserId(String(user.id));
          return;
        }
        const parsed = JSON.parse(stored);
        setReadNotificationKeys(Array.isArray(parsed) ? parsed : []);
      } catch {
        setReadNotificationKeys([]);
      }
      setNotificationReadUserId(String(user.id));
    });
    return () => { cancelled = true; };
  }, [notificationKeys, ordersLoading, user?.id]);

  useEffect(() => {
    onUnreadCountChange?.(user ? unreadCount : 0);
  }, [onUnreadCountChange, unreadCount, user]);

  const markAllNotificationsRead = useCallback(() => {
    if (!user?.id) return;
    setReadNotificationKeys(notificationKeys);
    try {
      window.localStorage.setItem(`dtb-account-notifications-read:${user.id}`, JSON.stringify(notificationKeys));
    } catch {
      // Read state is presentation-only; account history remains available without storage.
    }
  }, [notificationKeys, user]);

  return (
    <div
      className={`account-hub${isOpen ? ' account-hub--open' : ''}`}
      role="dialog"
      aria-modal="true"
      aria-label="Account hub"
      aria-hidden={!isOpen}
      inert={!isOpen ? true : undefined}
    >
      <button type="button" className="account-hub__backdrop" onClick={closeSheet} aria-label="Close account hub" tabIndex={-1} />

      <section ref={sheetRef} className={`dtb-side-sheet__panel account-hub__sheet${user ? '' : ' account-hub__sheet--guest'}`}>
        <header className="account-hub__drawer-header">
          <span className="account-hub__drawer-icon" aria-hidden="true"><User size={18} strokeWidth={2.2} /></span>
          <div className="account-hub__drawer-copy">
            <h2>{user ? 'Account hub' : 'Your account'}</h2>
            <p>{user ? `Welcome, ${firstName}` : 'Sign in or continue browsing'}</p>
          </div>
          <button ref={closeButtonRef} type="button" className="account-hub__close" onClick={closeSheet} aria-label="Close account hub" tabIndex={isOpen ? 0 : -1}>
            <X size={18} strokeWidth={2.5} />
          </button>
        </header>

        <div className="account-hub__content">
          {!user && activeTab === 'home' ? <div className="account-hub__panel account-hub__panel--guest"><section className="account-hub-guest-intro"><h2>Track orders and keep every job organized</h2><p>One secure account keeps purchases, repairs, returns, and support together.</p><button type="button" onClick={() => setActiveTab('account')}>Sign in or create an account <ChevronRight size={18} /></button></section><RecentlyViewedSection recentlyViewed={recentlyViewed} closeSheet={closeSheet} navigate={navigate} /></div> : null}

          {!user && activeTab === 'orders' ? <div className="account-hub__panel account-hub__panel--guest-state"><section className="account-hub-guest-intro"><h2>Sign in to view your orders and services</h2><p>Access purchases, repair requests, returns, and support tickets securely.</p><button type="button" onClick={() => setActiveTab('account')}>Sign in <ChevronRight size={18} /></button></section><div className="account-hub-guest-state__icon" aria-hidden="true"><Package size={34} strokeWidth={1.35} /></div></div> : null}

          {!user && activeTab === 'account' ? <div className="account-hub__panel account-hub__panel--auth"><GuestAuthForm mode={guestAuthMode} onModeChange={setGuestAuthMode} onLogin={onLogin} onRegister={onRegister} authLoading={authLoading} /></div> : null}

          {user && activeTab === 'home' && (
            <div className="account-hub__panel account-hub__panel--home">
              <AccountHubHero
                eyebrow="Account overview"
                title={`Welcome back, ${firstName}`}
                Icon={Home}
              />
              <section className="account-hub__notifications" aria-labelledby="account-hub-notifications-title">
                <div className="account-hub__notifications-heading">
                  <div>
                    <span className="account-hub__notifications-icon" aria-hidden="true"><Bell size={17} /></span>
                    <h3 id="account-hub-notifications-title">Notifications</h3>
                    {unreadCount > 0 ? <span className="account-hub__notifications-count">{unreadCount}</span> : null}
                  </div>
                  {unreadCount > 0 ? <button type="button" onClick={markAllNotificationsRead} className="account-hub__mark-read"><CheckCheck size={14} /> Mark all read</button> : null}
                </div>
                {ordersLoading ? (
                  <div className="account-hub__notification-state"><Loader size={16} className="animate-spin" /> Checking for updates…</div>
                ) : notifications.length ? (
                  <div className="account-hub__notification-list">
                    {notifications.slice(0, 3).map((item) => {
                      const itemKey = `${item.id}:${item.status}:${item.sortDate}`;
                      return (
                        <Link key={itemKey} to={item.href} onClick={closeSheet} className={`account-hub__notification${readNotificationKeys.includes(itemKey) ? '' : ' is-unread'}`}>
                          <span className="account-hub__notification-dot" aria-hidden="true" />
                          <span className="account-hub__notification-copy"><strong>{item.title}</strong><span>{item.statusLabel}{item.detail ? ` · ${item.detail}` : ''}</span></span>
                          <ChevronRight size={15} aria-hidden="true" />
                        </Link>
                      );
                    })}
                  </div>
                ) : <div className="account-hub__notification-state"><CheckCheck size={17} /> You’re all caught up.</div>}
              </section>
              <div className="account-hub__home-header">
                <div className="account-hub__summary-grid">
                  <button type="button" onClick={() => showOrdersTab('product')} className="account-hub__summary-card">
                    <Package size={18} /><strong>{orders.length}</strong><span>Orders</span>
                  </button>
                  <button type="button" onClick={() => showOrdersTab('repairs')} className="account-hub__summary-card is-repair">
                    <Wrench size={18} /><strong>{repairs.length}</strong><span>Repairs</span>
                  </button>
                  <button type="button" onClick={() => showOrdersTab('returns')} className="account-hub__summary-card is-return">
                    <RotateCcw size={18} /><strong>{returns.length}</strong><span>Returns</span>
                  </button>
                </div>
              </div>

              <section className="account-hub__list-section">
                <button type="button" className="account-hub__section-header" onClick={() => showOrdersTab('product')}>
                  <span>Recent activity</span>
                  <span className="account-hub__section-action">View all <ChevronRight size={14} /></span>
                </button>
                {ordersLoading ? (
                  <div className="account-hub__loading"><Loader size={18} className="animate-spin" /> Loading activity…</div>
                ) : activity.length ? (
                  <>
                    {historyErrorText ? <div className="account-hub__empty-card"><p>Some account history is temporarily unavailable. Available activity is shown below.</p></div> : null}
                    <AccountActivityList items={activity} limit={4} onNavigate={closeSheet} />
                  </>
                ) : (
                  <div className="account-hub__empty-card"><div className="account-hub__empty-icon"><Package size={24} /></div><p>Your orders, repairs, returns, and support tickets will appear here.</p></div>
                )}
              </section>

              <RecentlyViewedSection recentlyViewed={recentlyViewed} closeSheet={closeSheet} navigate={navigate} />
            </div>
          )}

          {user && activeTab === 'orders' && (
            <div className="account-hub__panel">
              <AccountHubHero
                eyebrow="Account history"
                title="Orders & services"
                subtitle="Product orders, repairs, returns, and support tickets in one place."
                Icon={Package}
              />
              {ordersLoading ? (
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '10px', padding: '48px 0' }}>
                  <Loader size={20} style={{ color: '#2255ee' }} className="animate-spin" />
                  <span style={{ fontSize: '0.85rem', color: 'rgba(15,23,42,0.5)' }}>Loading account history…</span>
                </div>
              ) : allHistoryFailed ? (
                <div className="account-hub__panel account-hub__panel--centered">
                  <div className="account-hub__empty-state">
                    <div className="account-hub__empty-state-icon"><AlertCircle size={34} strokeWidth={1.4} /></div>
                    <strong className="account-hub__empty-state-title">Account history unavailable</strong>
                    <p className="account-hub__empty-state-body">{historyErrorText}</p>
                    <button type="button" className="account-hub__outline-btn" onClick={loadOrders}>Retry</button>
                  </div>
                </div>
              ) : activity.length === 0 ? (
                <div className="account-hub__panel account-hub__panel--centered">
                  <div className="account-hub__empty-state">
                    <div className="account-hub__empty-state-icon"><Package size={34} strokeWidth={1.4} /></div>
                    <strong className="account-hub__empty-state-title">No account activity yet</strong>
                    <p className="account-hub__empty-state-body">Product orders, repair requests, returns, and support tickets will appear here.</p>
                    <button type="button" className="account-hub__outline-btn" onClick={() => { closeSheet(); navigate('/products'); }}>Start shopping</button>
                  </div>
                </div>
              ) : (
                <div className="account-history account-hub__history-section">
                  <div className="account-history__filters account-hub__history-filters" role="tablist" aria-label="Filter account history">
                    {HISTORY_FILTERS.map(({ id, label }) => (
                      <button
                        key={id}
                        type="button"
                        role="tab"
                        aria-selected={historyFilter === id}
                        className={`account-history__filter account-hub__history-filter${historyFilter === id ? ' is-active' : ''}`}
                        onClick={() => setHistoryFilter(id)}
                      >
                        <span>{label}</span>
                      </button>
                    ))}
                  </div>

                  {activeHistoryError ? <div className="account-history-state is-error" role="status"><AlertCircle size={18} /><span>{activeHistoryError}</span><button type="button" onClick={loadOrders}>Retry</button></div> : null}

                  {filteredHistoryActivity.length ? (
                    <AccountActivityList items={filteredHistoryActivity} onNavigate={closeSheet} />
                  ) : (
                    <div className="account-history-state">
                      <Package size={34} />
                      <strong>No activity found</strong>
                      <span>{historyFilterEmptyCopy(historyFilter)}</span>
                      {historyFilter === 'support' ? (
                        <Link to="/contact" onClick={closeSheet}>Contact support</Link>
                      ) : (
                        <Link to="/products" onClick={closeSheet}>Browse products</Link>
                      )}
                    </div>
                  )}

                  <button
                    type="button"
                    onClick={() => { closeSheet(); navigate('/dashboard?tab=orders'); }}
                    style={{ marginTop: '10px', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '4px', width: '100%', padding: '9px', borderRadius: '8px', border: '1px solid rgba(15,23,42,0.1)', background: 'transparent', fontSize: '0.8rem', fontWeight: 650, color: '#2255ee', cursor: 'pointer', transition: 'background 0.12s' }}
                    onMouseEnter={(e) => { e.currentTarget.style.background = '#eff6ff'; }}
                    onMouseLeave={(e) => { e.currentTarget.style.background = 'transparent'; }}
                  >
                    View full history <ChevronRight size={13} />
                  </button>
                </div>
              )}
            </div>
          )}

          {user && activeTab === 'account' && (
            <div className="account-hub__panel">
              <AccountHubHero
                eyebrow="My account"
                title={displayName}
                subtitle={user?.email || 'Manage your Drywall Toolbox account.'}
                Icon={User}
              />
              <div className="account-hub__account-menu">
                <Link to="/dashboard" onClick={closeSheet} className="account-hub__dashboard-link">
                  <span><LayoutDashboard size={18} />My Dashboard</span>
                  <ChevronRight size={16} />
                </Link>
                <Link to="/dashboard?tab=jobs" onClick={closeSheet} className="account-hub__dashboard-link">
                  <span><BookOpen size={18} />My Jobs &amp; Notes</span>
                  <ChevronRight size={16} />
                </Link>
                <AccountHubAccordion
                  title="Services"
                  Icon={Wrench}
                  open={accountSections.services}
                  onToggle={() => setAccountSections((state) => ({ ...state, services: !state.services }))}
                  onNavigate={closeSheet}
                  links={[
                    { to: '/repairs', label: 'Repair Services', icon: Wrench },
                    { to: '/schematics', label: 'Schematics', icon: BookOpen },
                    { to: '/calculators', label: 'Calculators', icon: Calculator },
                  ]}
                />
                <AccountHubAccordion
                  title="Support"
                  Icon={LifeBuoy}
                  open={accountSections.support}
                  onToggle={() => setAccountSections((state) => ({ ...state, support: !state.support }))}
                  onNavigate={closeSheet}
                  links={[
                    { to: '/contact', label: 'Contact Us', icon: LifeBuoy },
                    { to: '/returns', label: 'Returns Portal', icon: RotateCcw },
                    { to: '/help', label: 'Help', icon: BookOpen },
                  ]}
                />
              </div>
              <section className="account-hub__list-section">
                <button type="button" className="account-hub__signout-btn" onClick={handleSignOut} disabled={isSigningOut}>
                  {isSigningOut ? 'Signing out…' : 'Sign out'}
                </button>
                {signOutError ? <p className="account-hub__signout-error" role="alert">{signOutError}</p> : null}
              </section>
            </div>
          )}
        </div>

        <nav className="account-hub-nav" aria-label="Account hub navigation">
            {TABS.map(({ id, label, Icon }) => (
              <button key={id} type="button" className={`account-hub-nav__item${activeTab === id ? ' account-hub-nav__item--active' : ''}`} onClick={() => setActiveTab(id)} aria-selected={activeTab === id} aria-label={label} tabIndex={isOpen ? 0 : -1}>
                <span className="account-hub-nav__pill"><Icon size={22} strokeWidth={activeTab === id ? 2.2 : 1.6} /></span>
                <small>{label}</small>
              </button>
            ))}
        </nav>
      </section>
    </div>
  );
}
