import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { m as Motion } from 'framer-motion';
import { ArrowRight, Loader, Package, RotateCcw } from 'lucide-react';
import { getCustomerReturns } from '../../api/returns.js';
import { buildAccountActivity, normalizeReturns } from '../../utils/accountActivity.js';
import { dashboardRevealVariants } from '../../motion/dtbMotion.js';

const CARD = {
  background: 'white',
  border: '1px solid rgba(15,23,42,0.08)',
  borderRadius: '12px',
  boxShadow: '0 2px 12px rgba(15,23,42,0.05)',
};

async function fetchReturns() {
  return normalizeReturns(await getCustomerReturns(1, 50));
}

function formatDate(value) {
  if (!value) return '';
  try {
    return new Date(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  } catch {
    return '';
  }
}

export default function ReturnsTab() {
  const [returns, setReturns] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const loadReturns = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      setReturns(await fetchReturns());
    } catch {
      setReturns([]);
      setError('Returns are temporarily unavailable. Please try again shortly.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    let cancelled = false;
    fetchReturns()
      .then((items) => {
        if (cancelled) return;
        setReturns(items);
      })
      .catch(() => {
        if (cancelled) return;
        setReturns([]);
        setError('Returns are temporarily unavailable. Please try again shortly.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, []);

  const activity = useMemo(() => buildAccountActivity({ returns }), [returns]);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '14px' }}>
      <Motion.div custom={0.05} variants={dashboardRevealVariants} initial="hidden" animate="visible" style={{ ...CARD, padding: '18px 20px' }}>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '10px', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
            <div style={{ width: '34px', height: '34px', borderRadius: '8px', background: '#f5f3ff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              <RotateCcw size={16} style={{ color: '#7c3aed' }} />
            </div>
            <div>
              <span style={{ display: 'block', fontSize: '0.92rem', fontWeight: 700, color: '#0f172a' }}>Returns</span>
              <span style={{ display: 'block', marginTop: '1px', fontSize: '0.72rem', color: 'rgba(15,23,42,0.48)' }}>Track approvals, received items, refunds, and exchanges.</span>
            </div>
          </div>
          <Link to="/returns" style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', textDecoration: 'none', fontSize: '0.78rem', fontWeight: 700, color: '#7c3aed' }}>
            Start a return <ArrowRight size={12} />
          </Link>
        </div>

        {loading && (
          <div style={{ display: 'flex', alignItems: 'center', gap: '8px', padding: '8px 0' }}>
            <Loader size={15} className="animate-spin" style={{ color: '#7c3aed' }} />
            <span style={{ fontSize: '0.82rem', color: 'rgba(15,23,42,0.45)' }}>Loading returns…</span>
          </div>
        )}

        {!loading && error && (
          <div style={{ fontSize: '0.82rem', color: '#dc2626', background: '#fef2f2', border: '1px solid #fecaca', borderRadius: '8px', padding: '10px 12px' }}>
            <span>{error}</span>
            <button type="button" onClick={loadReturns} style={{ marginLeft: '10px', border: 'none', background: 'transparent', color: '#dc2626', fontWeight: 700, cursor: 'pointer' }}>Retry</button>
          </div>
        )}

        {!loading && !error && activity.length === 0 && (
          <div style={{ textAlign: 'center', padding: '20px 14px', borderRadius: '10px', background: '#f8fafc' }}>
            <Package size={24} style={{ color: 'rgba(15,23,42,0.2)', display: 'block', margin: '0 auto 8px' }} />
            <p style={{ margin: '0 0 12px', fontSize: '0.82rem', color: 'rgba(15,23,42,0.45)' }}>
              No return requests yet.
            </p>
            <Link to="/returns" style={{ textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '0.78rem', fontWeight: 700, color: '#7c3aed', background: '#f5f3ff', padding: '7px 12px', borderRadius: '7px' }}>
              <RotateCcw size={12} /> Start a return
            </Link>
          </div>
        )}

        {!loading && !error && activity.length > 0 && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
            {activity.slice(0, 5).map((item) => (
              <Link key={item.id} to={item.href} style={{ textDecoration: 'none' }}>
                <div style={{ border: '1px solid rgba(15,23,42,0.08)', borderRadius: '9px', padding: '10px 12px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '10px' }}>
                  <div style={{ minWidth: 0 }}>
                    <p style={{ margin: 0, fontSize: '0.82rem', fontWeight: 700, color: '#0f172a' }}>{item.title}</p>
                    <p style={{ margin: '2px 0 0', fontSize: '0.72rem', color: 'rgba(15,23,42,0.45)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                      {item.detail}{item.date ? ` • ${formatDate(item.date)}` : ''}
                    </p>
                  </div>
                  <span style={{ fontSize: '0.72rem', fontWeight: 700, color: '#7c3aed', background: '#f5f3ff', borderRadius: '999px', padding: '3px 8px', textTransform: 'capitalize', whiteSpace: 'nowrap' }}>
                    {item.statusLabel}
                  </span>
                </div>
              </Link>
            ))}
          </div>
        )}
      </Motion.div>
    </div>
  );
}
