import { Link } from 'react-router-dom';
import '../../styles/breadcrumb.css';

/** Render the route hierarchy; filter state and removal belong to the catalog panel. */
export default function Breadcrumb({ items = [], tone = 'default', className = '' }) {
  const hasPath = items.length > 0;
  if (!hasPath) return null;

  const classes = [
    'dtb-breadcrumb-bar',
    tone === 'inverse' ? 'dtb-breadcrumb-bar--inverse' : '',
    className,
  ].filter(Boolean).join(' ');

  return (
    <div className={classes}>
      {hasPath ? (
        <nav className="dtb-breadcrumb" aria-label="Breadcrumb">
          <ol>
            {items.map((item, index) => {
              const isLast = index === items.length - 1;
              return (
                <li key={item.path || item.label} aria-current={isLast ? 'page' : undefined}>
                  {isLast ? (
                    <span className="dtb-breadcrumb__current">{item.label}</span>
                  ) : item.onClick ? (
                    <button type="button" className="dtb-breadcrumb__action" onClick={item.onClick}>
                      {item.label}
                    </button>
                  ) : (
                    <Link to={item.path}>{item.label}</Link>
                  )}
                </li>
              );
            })}
          </ol>
        </nav>
      ) : null}

    </div>
  );
}
