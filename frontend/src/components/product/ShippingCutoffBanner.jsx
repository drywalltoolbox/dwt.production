import { useEffect, useState } from 'react';
import { Truck } from 'lucide-react';
import { apiClient } from '../../api/client.js';

const SHIPPING_CUTOFF_ENDPOINT = '/wp-json/dtb/v1/storefront/shipping-cutoff';
const CLOCK_TICK_MS = 1000;

function readCutoff(payload) {
  const cutoffAt = Date.parse(payload?.cutoff_at_utc || '');
  const serverNow = Date.parse(payload?.server_now_utc || '');
  if (!Number.isFinite(cutoffAt) || !Number.isFinite(serverNow)) return null;

  return {
    cutoffAt,
    serverOffset: serverNow - Date.now(),
    sameDayOpen: payload.same_day_cutoff_open === true,
    timezone: typeof payload.timezone === 'string' ? payload.timezone : '',
  };
}

function formatRemaining(milliseconds) {
  const seconds = Math.max(0, Math.ceil(milliseconds / 1000));
  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const remainingSeconds = seconds % 60;
  const pad = (value) => String(value).padStart(2, '0');
  const dayPrefix = days > 0 ? `${days}d ` : '';
  return `${dayPrefix}${pad(hours)}h ${pad(minutes)}m ${pad(remainingSeconds)}s`;
}

export default function ShippingCutoffBanner({ stockStatus }) {
  const [cutoff, setCutoff] = useState(null);
  const [now, setNow] = useState(Date.now());

  useEffect(() => {
    const controller = new AbortController();
    let active = true;

    apiClient(SHIPPING_CUTOFF_ENDPOINT, {
      method: 'GET',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
      signal: controller.signal,
      globalLoading: false,
    })
      .then((payload) => {
        if (active) setCutoff(readCutoff(payload));
      })
      .catch((error) => {
        if (active && error?.name !== 'AbortError') setCutoff(null);
      });

    return () => {
      active = false;
      controller.abort();
    };
  }, []);

  useEffect(() => {
    if (!cutoff) return undefined;
    const timer = window.setInterval(() => setNow(Date.now()), CLOCK_TICK_MS);
    return () => window.clearInterval(timer);
  }, [cutoff]);

  useEffect(() => {
    if (!cutoff?.sameDayOpen) return undefined;
    const refreshDelay = Math.max(0, cutoff.cutoffAt - (Date.now() + cutoff.serverOffset) + 150);
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      apiClient(SHIPPING_CUTOFF_ENDPOINT, {
        method: 'GET',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
        signal: controller.signal,
        globalLoading: false,
      })
        .then((payload) => setCutoff(readCutoff(payload)))
        .catch(() => setCutoff(null));
    }, refreshDelay);
    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [cutoff]);

  if (!cutoff || stockStatus !== 'instock') return null;

  const remaining = Math.max(0, cutoff.cutoffAt - (now + cutoff.serverOffset));
  const isSameDayWindowOpen = cutoff.sameDayOpen && remaining > 0;
  const countdown = formatRemaining(remaining);
  const timeZoneLabel = cutoff.timezone ? ` (${cutoff.timezone})` : '';

  return (
    <aside
      className={`dtb-shipping-cutoff${isSameDayWindowOpen ? ' is-open' : ' is-next-window'}`}
      aria-label="Shipping cutoff"
      data-cutoff-state={isSameDayWindowOpen ? 'open' : 'next-window'}
    >
      <span className="dtb-shipping-cutoff__icon" aria-hidden="true">
        <Truck size={23} strokeWidth={1.8} />
      </span>
      <span className="dtb-shipping-cutoff__copy">
        {isSameDayWindowOpen ? (
          <>
            <strong><span className="dtb-shipping-cutoff__stock-dot" />In stock.</strong>
            <span> Order within <time className="dtb-shipping-cutoff__timer" dateTime={new Date(cutoff.cutoffAt).toISOString()}>{countdown}</time> to ship today.</span>
            <small>Same-day shipping cutoff: 2:00 PM{timeZoneLabel}. Delivery options and charges are confirmed at checkout.</small>
          </>
        ) : (
          <>
            <strong>Today&apos;s same-day shipping cutoff has passed.</strong>
            <span> Next shipping cutoff in <time className="dtb-shipping-cutoff__timer" dateTime={new Date(cutoff.cutoffAt).toISOString()}>{countdown}</time>.</span>
            <small>Shipping options, delivery estimates, and charges are confirmed at checkout.</small>
          </>
        )}
      </span>
      <span className="dtb-shipping-cutoff__pulse" aria-hidden="true" />
    </aside>
  );
}
