import { useCallback, useEffect, useState } from 'react';
import { Share, X } from 'lucide-react';
import './mobile-install-nudge.css';

const MOBILE_INSTALL_NUDGE_DISMISSED_KEY = 'dtb:a2hs-install-nudge-dismissed:v1';
const MOBILE_INSTALL_NUDGE_SHOWN_KEY = 'dtb:a2hs-install-nudge-shown:v1';
const MOBILE_MEDIA_QUERY = '(max-width: 767px)';
const DISPLAY_DELAY_MS = 1800;

function getStorageFlag(key) {
  if (typeof window === 'undefined') return false;

  try {
    return window.localStorage.getItem(key) === '1';
  } catch {
    return false;
  }
}

function setStorageFlag(key) {
  if (typeof window === 'undefined') return;

  try {
    window.localStorage.setItem(key, '1');
  } catch {
    // Local storage can be unavailable in private browsing or strict modes.
  }
}

function isIOSSafari() {
  if (typeof window === 'undefined') return false;

  const userAgent = window.navigator.userAgent ?? '';
  const isIOS = /iphone|ipad|ipod/i.test(userAgent);
  if (!isIOS) return false;

  return /safari/i.test(userAgent) && !/crios|fxios|edgios|opios/i.test(userAgent);
}

function isStandaloneApp() {
  if (typeof window === 'undefined') return false;

  return (
    window.matchMedia?.('(display-mode: standalone)')?.matches === true ||
    window.navigator?.standalone === true
  );
}

function isMobileViewport() {
  if (typeof window === 'undefined') return false;

  return window.matchMedia?.(MOBILE_MEDIA_QUERY)?.matches === true;
}

export default function MobileInstallNudge({ suppressed = false }) {
  const [visible, setVisible] = useState(false);

  const dismiss = useCallback(() => {
    setStorageFlag(MOBILE_INSTALL_NUDGE_DISMISSED_KEY);
    setVisible(false);
  }, []);

  useEffect(() => {
    if (suppressed || visible) return undefined;
    if (getStorageFlag(MOBILE_INSTALL_NUDGE_DISMISSED_KEY)) return undefined;
    if (getStorageFlag(MOBILE_INSTALL_NUDGE_SHOWN_KEY)) return undefined;
    if (isStandaloneApp() || !isMobileViewport() || !isIOSSafari()) return undefined;

    const timer = window.setTimeout(() => {
      if (getStorageFlag(MOBILE_INSTALL_NUDGE_DISMISSED_KEY)) return;
      if (getStorageFlag(MOBILE_INSTALL_NUDGE_SHOWN_KEY)) return;
      if (isStandaloneApp() || !isMobileViewport() || !isIOSSafari()) return;
      setStorageFlag(MOBILE_INSTALL_NUDGE_SHOWN_KEY);
      setVisible(true);
    }, DISPLAY_DELAY_MS);

    return () => window.clearTimeout(timer);
  }, [suppressed, visible]);

  useEffect(() => {
    const onInstalled = () => {
      setVisible(false);
      setStorageFlag(MOBILE_INSTALL_NUDGE_DISMISSED_KEY);
    };

    window.addEventListener('appinstalled', onInstalled);
    return () => window.removeEventListener('appinstalled', onInstalled);
  }, []);

  useEffect(() => {
    if (!visible) return undefined;

    const mediaQuery = window.matchMedia?.(MOBILE_MEDIA_QUERY);
    if (!mediaQuery) return undefined;

    const handleViewportChange = (event) => {
      if (!event.matches) setVisible(false);
    };

    if (mediaQuery.addEventListener) {
      mediaQuery.addEventListener('change', handleViewportChange);
      return () => mediaQuery.removeEventListener('change', handleViewportChange);
    }

    mediaQuery.addListener(handleViewportChange);
    return () => mediaQuery.removeListener(handleViewportChange);
  }, [visible]);

  if (!visible || suppressed) return null;

  return (
    <aside className="mobile-install-nudge" aria-label="Install app prompt">
      <div className="mobile-install-nudge__card">
        <div className="mobile-install-nudge__content">
          <p>
            On iPhone/iPad, tap <Share size={ 12 } strokeWidth={ 2.4 } aria-hidden="true" className="mobile-install-nudge__share-icon" /> then
            <strong> Add to Home Screen</strong>.
          </p>
        </div>
        <button
          type="button"
          className="mobile-install-nudge__close"
          onClick={ dismiss }
          aria-label="Dismiss add to home screen prompt"
        >
          <X size={ 14 } strokeWidth={ 2.6 } aria-hidden="true" />
        </button>
      </div>
    </aside>
  );
}
