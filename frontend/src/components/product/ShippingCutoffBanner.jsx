import { useEffect, useState } from 'react';
import { Truck } from 'lucide-react';
import { apiClient } from '../../api/client.js';

const SHIPPING_CUTOFF_ENDPOINT = '/wp-json/dtb/v1/storefront/shipping-cutoff';
const CLOCK_TICK_MS = 1000;
const BUSINESS_DAYS = new Set(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']);

function readCutoff(payload) {
  const cutoffAt = Date.parse(payload?.cutoff_at_utc || '');
  const serverNow = Date.parse(payload?.server_now_utc || '');
  if (!Number.isFinite(cutoffAt) || !Number.isFinite(serverNow)) return null;

  const timezone = typeof payload.timezone === 'string' ? payload.timezone : '';
  try {
    new Intl.DateTimeFormat('en-US', { timeZone: timezone }).format();
  } catch {
    return null;
  }

  return {
    cutoffAt,
    serverOffset: serverNow - Date.now(),
    sameDayOpen: payload.same_day_cutoff_open === true,
    timezone,
  };
}

function getLocalDateParts(timestamp, timezone) {
  const dateFormatter = new Intl.DateTimeFormat('en-US', {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  });
  const date = Object.fromEntries(
    dateFormatter.formatToParts(new Date(timestamp))
      .filter(({ type }) => type !== 'literal')
      .map(({ type, value }) => [type, Number(value)]),
  );
  return date;
}

function getCutoffDayMidnight(cutoffAt, timezone) {
  const date = getLocalDateParts(cutoffAt, timezone);
  const timeFormatter = new Intl.DateTimeFormat('en-US', {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  });
  const midnightAsUtc = Date.UTC(date.year, date.month - 1, date.day);
  let estimate = midnightAsUtc;

  // Resolve the configured site's local midnight to UTC without assuming the
  // browser timezone or a fixed UTC offset (which can change with DST).
  for (let attempt = 0; attempt < 3; attempt += 1) {
    const parts = Object.fromEntries(
      timeFormatter.formatToParts(new Date(estimate))
        .filter(({ type }) => type !== 'literal')
        .map(({ type, value }) => [type, Number(value)]),
    );
    const representedAsUtc = Date.UTC(
      parts.year,
      parts.month - 1,
      parts.day,
      parts.hour,
      parts.minute,
      parts.second,
    );
    const adjustment = midnightAsUtc - representedAsUtc;
    estimate += adjustment;
    if (adjustment === 0) break;
  }

  return estimate;
}

function getNextWindowDayLabel(cutoffAt, timezone) {
  return new Intl.DateTimeFormat('en-US', {
    timeZone: timezone,
    weekday: 'long',
  }).format(new Date(cutoffAt));
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

function formatCutoffTime(timestamp, timezone) {
  return new Intl.DateTimeFormat('en-US', {
    timeZone: timezone,
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(timestamp));
}

export default function ShippingCutoffBanner({ stockStatus }) {
  const [cutoff, setCutoff] = useState(null);
  const [now, setNow] = useState(0);

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
        if (active) {
          setNow(Date.now());
          setCutoff(readCutoff(payload));
        }
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
    if (!cutoff?.sameDayOpen) return undefined;
    const timer = window.setInterval(() => setNow(Date.now()), CLOCK_TICK_MS);
    return () => window.clearInterval(timer);
  }, [cutoff]);

  useEffect(() => {
    if (!cutoff) return undefined;
    const refreshAt = cutoff.sameDayOpen
      ? cutoff.cutoffAt
      : getCutoffDayMidnight(cutoff.cutoffAt, cutoff.timezone);
    const refreshDelay = Math.max(0, refreshAt - (Date.now() + cutoff.serverOffset) + 150);
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      apiClient(SHIPPING_CUTOFF_ENDPOINT, {
        method: 'GET',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
        signal: controller.signal,
        globalLoading: false,
      })
        .then((payload) => {
          setNow(Date.now());
          setCutoff(readCutoff(payload));
        })
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
  const siteNow = now + cutoff.serverOffset;
  const currentDate = getLocalDateParts(siteNow, cutoff.timezone);
  const nextCutoffDate = getLocalDateParts(cutoff.cutoffAt, cutoff.timezone);
  const daysUntilCutoff = Math.round((
    Date.UTC(nextCutoffDate.year, nextCutoffDate.month - 1, nextCutoffDate.day)
    - Date.UTC(currentDate.year, currentDate.month - 1, currentDate.day)
  ) / 86400000);
  const currentWeekday = new Intl.DateTimeFormat('en-US', {
    timeZone: cutoff.timezone,
    weekday: 'long',
  }).format(new Date(siteNow));
  const cutoffPassedToday = !cutoff.sameDayOpen
    ? daysUntilCutoff === 1 && BUSINESS_DAYS.has(currentWeekday)
    : remaining === 0;
  const countdown = formatRemaining(remaining);
  const timeZoneLabel = cutoff.timezone ? ` (${cutoff.timezone})` : '';
  const cutoffTimeLabel = formatCutoffTime(cutoff.cutoffAt, cutoff.timezone);
  const nextWindowDay = getNextWindowDayLabel(cutoff.cutoffAt, cutoff.timezone);

  return (
    <aside
      className={`dtb-shipping-cutoff${isSameDayWindowOpen ? ' is-open' : ' is-closed'}`}
      aria-label="Shipping cutoff"
      data-cutoff-state={isSameDayWindowOpen ? 'open' : 'closed'}
    >
      <Truck className="dtb-shipping-cutoff__icon" size={18} strokeWidth={1.8} aria-hidden="true" />
      <span className="dtb-shipping-cutoff__copy">
        {isSameDayWindowOpen ? (
          <>
            <strong><span className="dtb-shipping-cutoff__stock-dot" />In stock.</strong>
            <span> Order within <time className="dtb-shipping-cutoff__timer" dateTime={new Date(cutoff.cutoffAt).toISOString()}>{countdown}</time> to ship today.</span>
            <small>Same-day shipping cutoff: {cutoffTimeLabel}{timeZoneLabel}.</small>
          </>
        ) : (
          <>
            <strong>
              {cutoffPassedToday
                ? 'Today\'s same-day shipping window has wrapped up.'
                : `Same-day shipping resumes ${nextWindowDay}.`}
            </strong>
            <span> You can still place your order anytime.</span>
          </>
        )}
      </span>
    </aside>
  );
}
