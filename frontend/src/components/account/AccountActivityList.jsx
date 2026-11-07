import { Link } from 'react-router-dom';
import { ChevronRight } from 'lucide-react';
import '../../styles/account-activity.css';

const TYPE_CONFIG = {
  order: { className: 'is-order' },
  'repair-order': { className: 'is-repair' },
  repair: { className: 'is-repair' },
  return: { className: 'is-return' },
  support: { className: 'is-support' },
};

function formatDate(value) {
  if (!value) return '';
  return new Date(value).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

export default function AccountActivityList({ items, limit, onNavigate }) {
  const visibleItems = typeof limit === 'number' ? items.slice(0, limit) : items;

  return (
    <div className="account-activity-list">
      {visibleItems.map((item) => {
        const config = TYPE_CONFIG[item.type] || TYPE_CONFIG.order;
        return (
          <Link key={item.id} to={item.href} onClick={onNavigate} className="account-activity-card">
            <span className={`account-activity-card__marker ${config.className}`} aria-hidden="true" />
            <span className="account-activity-card__body">
              <span className="account-activity-card__eyebrow">{item.label}</span>
              <span className="account-activity-card__title-row">
                <span className="account-activity-card__title">{item.title}</span>
                <span className={`account-activity-card__status is-${item.type}`}>{item.statusLabel}</span>
              </span>
              <span className="account-activity-card__meta">
                {[item.detail, formatDate(item.date)].filter(Boolean).join(' · ')}
              </span>
            </span>
            <span className="account-activity-card__aside">
              {Number.isFinite(item.amount) ? (
                <strong className="account-activity-card__amount">{`$${item.amount.toFixed(2)}`}</strong>
              ) : null}
            </span>
            <ChevronRight className="account-activity-card__chevron" size={16} />
          </Link>
        );
      })}
    </div>
  );
}
