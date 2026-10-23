/**
 * frontend/src/components/dashboard/AccountHub.jsx
 *
 * Account dashboard hub — mirrors the CalculatorHub pattern.
 *
 * Layout:
 *   Gradient hero strip  →  floating pill tab nav  →  animated tab panel
 *
 * Tabs: Overview · Orders · Repairs · Returns · Support Tickets · Settings
 *
 * Features:
 *   - Deep-linkable via ?tab= query param (overview | orders | repairs | returns | support | settings)
 *   - Tab persisted to localStorage
 *   - AnimatePresence tab transitions (opacity + scale, 0.22 s)
 *   - Touch swipe support for mobile
 *   - Shared data (recent orders) fetched once and passed to tabs
 */

import { useState, useEffect, useRef, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { m as Motion, useReducedMotion } from 'framer-motion';
import { dashboardRevealTransition, dtbTransition, reducedTransition } from '../../motion/dtbMotion.js';
import {
  Headphones, LayoutDashboard, Package, Wrench, RotateCcw, Settings, LogOut, User,
} from 'lucide-react';

import { useAuthContext }             from '../../auth/AuthContext.js';
import { getCustomerOrders }          from '../../api/orders.js';
import { getCustomerRepairs }         from '../../api/repairs.js';
import { getCustomerReturns }         from '../../api/returns.js';
import { getCustomerSupportTickets }  from '../../api/support.js';
import { normalizeOrders, normalizeRepairs, normalizeReturns, normalizeSupportTickets } from '../../utils/accountActivity.js';

import OverviewTab   from './OverviewTab.jsx';
import OrdersTab     from './OrdersTab.jsx';
import RepairsTab    from './RepairsTab.jsx';
import ReturnsTab    from './ReturnsTab.jsx';
import SupportTicketsTab from './SupportTicketsTab.jsx';
import SettingsTab   from './SettingsTab.jsx';
import NavbarTabs    from '../ui/NavbarTabs.jsx';

// ─── Tab definitions ──────────────────────────────────────────────────────────

const BASE_TABS = [
  { id: 'overview',   label: 'Overview',   shortLabel: 'Overview',  icon: LayoutDashboard },
  { id: 'orders',     label: 'Orders',     shortLabel: 'Orders',    icon: Package         },
  { id: 'repairs',    label: 'Repairs',    shortLabel: 'Repairs',   icon: Wrench          },
  { id: 'returns',    label: 'Returns',     shortLabel: 'Returns',   icon: RotateCcw       },
  { id: 'support',    label: 'Support Tickets', shortLabel: 'Support', icon: Headphones    },
  { id: 'settings',   label: 'Settings',   shortLabel: 'Settings',  icon: Settings        },
];

// ─── Animation variants ───────────────────────────────────────────────────────

const tabTransition = {
  initial: { opacity: 1, y: 8 },
  animate: { opacity: 1, y: 0, transition: dashboardRevealTransition },
};

// Dot-grid hero overlay
const DOT_GRID = {
  position:        'absolute',
  inset:           0,
  backgroundImage: 'radial-gradient(circle at 2px 2px, rgba(255,255,255,0.07) 1px, transparent 0)',
  backgroundSize:  '36px 36px',
  pointerEvents:   'none',
};

function settledValue(result, normalize) {
  return result.status === 'fulfilled' ? normalize(result.value) : [];
}

// ─── Hub component ────────────────────────────────────────────────────────────

export default function AccountHub() {
  const TABS = useMemo( () => BASE_TABS, [] );
  const reduceMotion = useReducedMotion();
  const navigate                                      = useNavigate();
  const { user, isAuthenticated, isLoading, logout }  = useAuthContext();

  // Resolve initial tab from URL param → localStorage → 0.
  // Reads window.location.search directly (no hook) so it never triggers re-renders.
  const resolveInitialTab = () => {
    const urlTab = new URLSearchParams( window.location.search ).get( 'tab' );
    if ( urlTab ) {
      if ( urlTab === 'addresses' ) {
        return TABS.findIndex( ( t ) => t.id === 'settings' );
      }
      const idx = TABS.findIndex( ( t ) => t.id === urlTab );
      if ( idx >= 0 ) return idx;
    }
    try {
      const cached = JSON.parse( localStorage.getItem( 'dtb_dashboard_tab' ) || '0' );
      if ( typeof cached === 'string' ) {
        const cachedIndex = TABS.findIndex( ( tab ) => tab.id === cached );
        return cachedIndex >= 0 ? cachedIndex : 0;
      }
      // Numeric values were stored by the previous five-tab dashboard.
      if ( typeof cached === 'number' ) {
        if ( cached === 4 ) return TABS.findIndex( ( tab ) => tab.id === 'settings' );
        return cached >= 0 && cached <= 3 ? cached : 0;
      }
      return 0;
    } catch { return 0; }
  };

  const [ activeTab,      setActiveTab      ] = useState( resolveInitialTab );
  const [ recentOrders,   setRecentOrders   ] = useState( [] );
  const [ recentRepairs,  setRecentRepairs  ] = useState( [] );
  const [ recentReturns,  setRecentReturns  ] = useState( [] );
  const [ recentSupportTickets, setRecentSupportTickets ] = useState( [] );
  const [ isSigningOut, setIsSigningOut ] = useState( false );
  const [ signOutError, setSignOutError ] = useState( '' );
  const [ ordersLoading,  setOrdersLoading  ] = useState( true );
  const activeTabId = TABS[ activeTab ]?.id ?? 'overview';

  // Touch swipe
  const touchStartX = useRef( null );
  const touchEndX   = useRef( null );

  // Auth redirect
  useEffect( () => {
    if ( ! isLoading && ! isAuthenticated ) {
      navigate( '/login', { replace: true } );
    }
  }, [ isLoading, isAuthenticated, navigate ] );

  // Sync URL param when tab changes — uses history.replaceState directly
  // so it never triggers a React re-render (avoids setSearchParams loop).
  useEffect( () => {
    const tabId = TABS[ activeTab ]?.id ?? 'overview';
    try {
      const url = new URL( window.location.href );
      if ( tabId === 'overview' ) {
        url.searchParams.delete( 'tab' );
      } else {
        url.searchParams.set( 'tab', tabId );
      }
      window.history.replaceState( null, '', url.toString() );
      localStorage.setItem( 'dtb_dashboard_tab', JSON.stringify( tabId ) );
    } catch { /* noop */ }
  }, [ TABS, activeTab ] );

  // Fetch overview data only when the Overview tab is visible. Settings, support,
  // returns, and orders have isolated tab-level loaders; preloading every service
  // on every dashboard visit causes unrelated 404s to surface on the Settings tab.
  useEffect( () => {
    if ( ! user?.id || activeTabId !== 'overview' ) return undefined;
    let cancelled = false;

    Promise.allSettled( [
      getCustomerOrders( user.id, 1, 50 ),
      getCustomerRepairs( 1, 50 ),
      getCustomerReturns( 1, 50 ),
      getCustomerSupportTickets( 1, 50 ),
    ] )
      .then( ( [ ordersResult, repairsResult, returnsResult, supportResult ] ) => {
        if ( cancelled ) return;
        setRecentOrders( settledValue( ordersResult, normalizeOrders ) );
        setRecentRepairs( settledValue( repairsResult, normalizeRepairs ) );
        setRecentReturns( settledValue( returnsResult, normalizeReturns ) );
        setRecentSupportTickets( settledValue( supportResult, normalizeSupportTickets ) );
      } )
      .finally( () => { if ( ! cancelled ) setOrdersLoading( false ); } );

    return () => { cancelled = true; };
  }, [ activeTabId, user?.id ] );

  function changeTab( idx ) {
    if ( idx === activeTab ) return;
    setActiveTab( idx );
  }

  function handleTouchStart( e ) {
    if ( e.targetTouches && e.targetTouches.length > 0 ) {
      touchStartX.current = e.targetTouches[0].clientX;
    }
  }
  function handleTouchMove( e ) {
    if ( e.targetTouches && e.targetTouches.length > 0 ) {
      touchEndX.current = e.targetTouches[0].clientX;
    }
  }
  function handleTouchEnd() {
    if ( touchStartX.current === null || touchEndX.current === null ) return;
    const dist = touchStartX.current - touchEndX.current;
    if ( dist >  70 && activeTab < TABS.length - 1 ) changeTab( activeTab + 1 );
    if ( dist < -70 && activeTab > 0               ) changeTab( activeTab - 1 );
    touchStartX.current = null;
    touchEndX.current   = null;
  }

  async function handleLogout() {
    if ( isSigningOut ) return;
    setIsSigningOut( true );
    setSignOutError( '' );
    try {
      await logout();
      navigate( '/', { replace: true } );
    } catch ( error ) {
      setSignOutError( error?.message || 'Unable to sign out securely. Please try again.' );
    } finally {
      setIsSigningOut( false );
    }
  }

  // Loading / auth guard
  if ( isLoading || ! user ) {
    return (
      <div style={ { display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: '60vh' } }>
        <div style={ { display: 'flex', gap: '7px' } }>
          { [ 0, 1, 2 ].map( ( i ) => (
            <Motion.span
              key={ i }
              style={ { display: 'block', width: '9px', height: '9px', borderRadius: '50%', background: '#2255ee' } }
              animate={ reduceMotion ? { scale: 1, opacity: 1 } : { scale: [ 1, 1.5, 1 ], opacity: [ 0.3, 1, 0.3 ] } }
              transition={ reduceMotion ? reducedTransition : { duration: 1.1, repeat: Infinity, delay: i * 0.2, ease: 'easeInOut' } }
            />
          ) ) }
        </div>
      </div>
    );
  }

  const displayName = [ user.first_name, user.last_name ].filter( Boolean ).join( ' ' ) || user.email;
  const initials    = ( ( user.first_name?.[0] || '' ) + ( user.last_name?.[0] || user.email?.[0] || '' ) ).toUpperCase();

  return (
    <div className="page-wrapper" style={ { minHeight: '100vh', background: '#f4f6fb' } }>

      {/* ── Hero ── */}
      <div style={ {
        background: 'linear-gradient(140deg, #0b1120 0%, #0f2150 45%, #1a3fa8 100%)',
        position:   'relative',
        overflow:   'hidden',
      } }>
        {/* Dot-grid overlay */}
        <div style={ DOT_GRID } />

        {/* Radial glow behind avatar */}
        <div style={ {
          position:         'absolute',
          top:              '-60px',
          left:             '50%',
          transform:        'translateX(-50%)',
          width:            '320px',
          height:           '320px',
          borderRadius:     '50%',
          background:       'radial-gradient(circle, rgba(59,130,246,0.18) 0%, transparent 70%)',
          pointerEvents:    'none',
        } } />

        <div style={ { position: 'relative', zIndex: 1, maxWidth: '1200px', margin: '0 auto', padding: 'clamp(1.75rem, 4.5vw, 3rem) clamp(1.25rem, 5vw, 3rem)' } }>
          <Motion.div
            initial={ reduceMotion ? false : { opacity: 1, y: 8 } }
            animate={ { opacity: 1, y: 0 } }
            transition={ reduceMotion ? reducedTransition : dtbTransition.emphasized }
          >
            {/* ── Desktop layout: avatar + text side by side ── */}
            <div className="dash-hero-inner">

              {/* Avatar */}
              <div className="dash-hero-avatar">
                { initials || <User size={ 28 } /> }
              </div>

              {/* Text block */}
              <div className="dash-hero-text">
                <h1 style={ {
                  color:         'white',
                  fontSize:      'clamp(1.25rem, 3.5vw, 1.75rem)',
                  fontWeight:    800,
                  margin:        0,
                  letterSpacing: '-0.03em',
                  lineHeight:    1.15,
                } }>
                  Welcome back,<br className="dash-hero-br" />
                  <span style={ { color: '#93c5fd' } }>
                    { displayName }
                  </span>
                </h1>
              </div>
            </div>

          </Motion.div>
        </div>
      </div>

      {/* ── NavbarTabs ── */}
      <div style={ { width: '100%', padding: 'clamp(1rem, 2.5vw, 1.5rem) clamp(1.25rem, 5vw, 3rem) clamp(0.5rem, 1vw, 0.75rem)' } }>
        <div style={ { maxWidth: '1200px', margin: '0 auto' } }>
          <NavbarTabs
            tabs={ TABS }
            activeIndex={ activeTab }
            onChange={ changeTab }
          />
        </div>
      </div>

      {/* ── Tab content panel ── */}
      <div
        style={ { width: '100%', padding: 'clamp(0.25rem, 1vw, 0.5rem) clamp(1.25rem, 5vw, 3rem) clamp(2rem, 4vw, 3rem)' } }
        onTouchStart={ handleTouchStart }
        onTouchMove={ handleTouchMove }
        onTouchEnd={ handleTouchEnd }
      >
        <div style={ { maxWidth: '1200px', margin: '0 auto' } }>
          <Motion.div
            key={ activeTab }
            variants={ reduceMotion ? { initial: { opacity: 1, y: 0 }, animate: { opacity: 1, y: 0, transition: reducedTransition } } : tabTransition }
            initial="initial"
            animate="animate"
            style={ {
              background:   'transparent',
            } }
          >
              {/* Thin accent rule */}
              <div style={ { height: '3px', width: '32px', borderRadius: '999px', background: '#2255ee', marginBottom: '18px', opacity: 0.7 } } />

              { TABS[ activeTab ]?.id === 'overview' && (
                <OverviewTab
                  user={ user }
                  orders={ recentOrders }
                  repairs={ recentRepairs }
                  returns={ recentReturns }
                  supportTickets={ recentSupportTickets }
                  ordersLoading={ ordersLoading }
                  onTabChange={ changeTab }
                />
              ) }
              { TABS[ activeTab ]?.id === 'orders' && <OrdersTab userId={ user.id } /> }
              { TABS[ activeTab ]?.id === 'repairs' && <RepairsTab userId={ user.id } /> }
              { TABS[ activeTab ]?.id === 'returns' && <ReturnsTab /> }
              { TABS[ activeTab ]?.id === 'support' && <SupportTicketsTab /> }
              { TABS[ activeTab ]?.id === 'settings' && <SettingsTab user={ user } /> }
          </Motion.div>
        </div>
      </div>

      {/* ── Sign out button (bottom of page) ── */}
      <div style={ { padding: '0 clamp(1.25rem, 5vw, 3rem) clamp(2rem, 4vw, 3rem)', maxWidth: '1200px', margin: '0 auto' } }>
        <button
          type="button"
          onClick={ handleLogout }
          disabled={ isSigningOut }
          className="dash-logout-btn"
          style={ {
            display:        'flex',
            alignItems:     'center',
            gap:            '8px',
            padding:        '12px 16px',
            borderRadius:   '10px',
            border:         '1px solid rgba(220,38,38,0.2)',
            background:     '#fef2f2',
            color:          '#dc2626',
            fontSize:       '0.875rem',
            fontWeight:     650,
            cursor:         'pointer',
            width:          '100%',
            justifyContent: 'center',
            transition:     'background 0.15s',
          } }
          onMouseEnter={ ( e ) => { e.currentTarget.style.background = '#fee2e2'; } }
          onMouseLeave={ ( e ) => { e.currentTarget.style.background = '#fef2f2'; } }
        >
          <LogOut size={ 15 } /> { isSigningOut ? 'Signing out…' : 'Sign out' }
        </button>
        { signOutError && (
          <p role="alert" style={ { margin: '9px 0 0', color: '#b91c1c', fontSize: '0.8rem', textAlign: 'center' } }>
            { signOutError }
          </p>
        ) }
      </div>

      {/* ── Responsive CSS ── */}
      <style>{ `
        /* ── Hero layout ── */
        .dash-hero-inner {
          display:        flex;
          align-items:    center;
          gap:            16px;
          margin-bottom:  20px;
        }

        .dash-hero-avatar {
          width:           68px;
          height:          68px;
          border-radius:   50%;
          background:      linear-gradient(135deg, #2255ee, #2255ee);
          display:         flex;
          align-items:     center;
          justify-content: center;
          font-size:       1.35rem;
          font-weight:     800;
          color:           white;
          border:          2.5px solid rgba(255,255,255,0.2);
          flex-shrink:     0;
          letter-spacing:  -0.02em;
          box-shadow:      0 0 0 6px rgba(59,130,246,0.15);
        }

        .dash-hero-text {
          display:        flex;
          flex-direction: column;
          min-width:      0;
        }

        /* line break only on mobile */
        .dash-hero-br { display: none; }

        /* ── Mobile overrides (≤ 639px) ── */
        @media (max-width: 639px) {
          .dash-hero-inner {
            flex-direction: column;
            align-items:    center;
            text-align:     center;
            gap:            14px;
            margin-bottom:  22px;
          }

          .dash-hero-avatar {
            width:      80px;
            height:     80px;
            font-size:  1.6rem;
            box-shadow: 0 0 0 8px rgba(59,130,246,0.14);
          }

          .dash-hero-text {
            align-items: center;
          }

          .dash-hero-br { display: inline; }

          .dash-hero-inner { margin-bottom: 0; }
        }

        /* ── Show shorter tab labels on very small screens ── */
        @media (max-width: 430px) {
          .dash-tab-label { font-size: 0.76rem; }
        }

        /* Hide scrollbar on tab strip */
        .scrollbar-none::-webkit-scrollbar { display: none; }
        .scrollbar-none { scrollbar-width: none; }
      ` }</style>
    </div>
  );
}
