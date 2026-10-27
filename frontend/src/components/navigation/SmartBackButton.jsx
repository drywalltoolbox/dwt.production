import { useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import BackButton from '../shared/BackButton.jsx';

export default function SmartBackButton({ fallbackTo = '/dashboard', label = 'Back', className = '', variant = 'light' }) {
  const navigate = useNavigate();
  const handleClick = useCallback((event) => {
    // React Router's history index identifies entries created within this app.
    // A direct entry should use the explicit fallback, even if the browser has older history.
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (typeof window === 'undefined' || !(window.history.state?.idx > 0)) return;
    event.preventDefault();
    navigate(-1);
  }, [navigate]);

  return <BackButton to={fallbackTo} onClick={handleClick} label={label} className={className} variant={variant} />;
}
