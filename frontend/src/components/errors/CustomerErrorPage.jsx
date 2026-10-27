import BackButton from '../shared/BackButton.jsx';
import { Headphones, Home, RefreshCw, Search, ShieldAlert, WifiOff } from 'lucide-react';
import { useParams } from 'react-router-dom';
import SEOHead from '../shared/SEOHead.jsx';
import { getCustomerErrorContent } from '../../utils/customerErrors.js';
import { DTB_LOGO_WHITE_URL } from '../../utils/dtbLogoAssets.js';
import '../../styles/customer-error.css';

const APP_BASE = (process.env.PUBLIC_URL || '').replace(/\/+$/, '');

function appHref(path = '/') {
  const normalized = path.startsWith('/') ? path : `/${path}`;
  return `${APP_BASE}${normalized}` || '/';
}

export default function CustomerErrorPage({
  code: explicitCode,
  title,
  message,
  showDebug = false,
  debugDetails = null,
}) {
  const params = useParams();
  const content = getCustomerErrorContent(explicitCode || params.code || 500);
  const isOffline = content.code === 'offline';
  const displayCode = isOffline ? 'OFFLINE' : content.code;
  const primaryHref = content.code === 401 ? appHref('/login') : appHref('/');
  const primaryLabel = content.code === 401 ? 'Sign in' : 'Return home';

  return (
    <div className="customer-error-page" role="alert">
      <SEOHead noindex title={`${displayCode} | Drywall Toolbox`} />
      <div className="customer-error-page__pattern" aria-hidden="true" />
      <section className="customer-error-card">
        <a className="customer-error-card__brand" href={appHref('/')} aria-label="Drywall Toolbox home">
          <img src={DTB_LOGO_WHITE_URL} alt="Drywall Toolbox" />
        </a>

        <div className="customer-error-card__content">
          <span className="customer-error-card__icon" aria-hidden="true">
            {isOffline ? <WifiOff size={30} /> : <ShieldAlert size={30} />}
          </span>
          <p className="customer-error-card__eyebrow">{content.eyebrow}</p>
          <p className="customer-error-card__code">{displayCode}</p>
          <h1>{title || content.title}</h1>
          <p className="customer-error-card__message">{message || content.message}</p>

          <div className="customer-error-card__actions">
            <a href={primaryHref} className="customer-error-button customer-error-button--primary">
              <Home size={17} /> {primaryLabel}
            </a>
            <a href={appHref('/products')} className="customer-error-button customer-error-button--secondary">
              <Search size={17} /> Browse products
            </a>
            <button type="button" onClick={() => window.location.reload()} className="customer-error-button customer-error-button--ghost">
              <RefreshCw size={17} /> Try again
            </button>
          </div>

          <div className="customer-error-card__footer">
            <BackButton label="Go back" onClick={() => { if (window.history.state?.idx > 0) window.history.back(); else window.location.assign(appHref('/')); }} />
            <a href={appHref('/contact')}><Headphones size={15} /> Contact support</a>
          </div>

          {showDebug && debugDetails ? (
            <pre className="customer-error-card__debug">{JSON.stringify(debugDetails, null, 2)}</pre>
          ) : null}
        </div>
      </section>
    </div>
  );
}
