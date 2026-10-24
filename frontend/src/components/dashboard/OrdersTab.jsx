/**
 * Customer product orders only. Repairs and returns have their own account
 * destinations; do not duplicate them as filters within Orders.
 */
import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertCircle, Loader, Package } from 'lucide-react';
import { getOrders } from '../../api/orders.js';
import { buildAccountActivity, normalizeOrders } from '../../utils/accountActivity.js';
import AccountActivityList from '../account/AccountActivityList.jsx';

export default function OrdersTab() {
  const [orders, setOrders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const loadOrders = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const response = await getOrders(1, 50);
      setOrders(normalizeOrders(response));
    } catch (reason) {
      setOrders([]);
      setError(reason?.message || 'Your orders are temporarily unavailable.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    let active = true;
    getOrders(1, 50)
      .then((response) => { if (active) setOrders(normalizeOrders(response)); })
      .catch((reason) => {
        if (active) { setOrders([]); setError(reason?.message || 'Your orders are temporarily unavailable.'); }
      })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, []);

  if (loading) {
    return <div className="account-history-state" role="status"><Loader size={20} className="animate-spin"/> Loading orders…</div>;
  }

  const items = buildAccountActivity({
    orders: orders.filter((order) => String(order?.order_type || '').toLowerCase() !== 'repair_service'),
  });

  return (
    <section className="account-history" aria-label="Order history">
      {error && (
        <div className="account-history-state is-error" role="alert">
          <AlertCircle size={18}/>
          <span>{error}</span>
          <button type="button" onClick={loadOrders}>Retry</button>
        </div>
      )}
      {!error && (items.length
        ? <AccountActivityList items={items}/>
        : <div className="account-history-state">
            <Package size={34}/>
            <strong>No orders yet</strong>
            <span>Your product orders will appear here after your first purchase.</span>
            <Link to="/products">Browse products</Link>
          </div>
      )}
    </section>
  );
}
