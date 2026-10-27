import { Link } from 'react-router-dom';
import './back-button.css';

export default function BackButton({
  onClick,
  to,
  label = 'Back',
  className = '',
  hideLabelOnMobile = false,
  iconOnly = false,
  variant = 'light',
  disabled = false,
}) {
  const classes = [
    'dtb-back-button',
    variant === 'dark' ? 'dtb-back-button--dark' : '',
    hideLabelOnMobile ? 'dtb-back-button--mobile-icon-only' : '',
    iconOnly ? 'dtb-back-button--icon-only' : '',
    className,
  ].filter(Boolean).join(' ');
  const content = (
    <>
      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path fill="currentColor" d="m2.82 12l7.715 7.716q.22.22.218.528t-.224.529t-.529.221t-.529-.221L1.83 13.137q-.242-.243-.354-.54q-.111-.299-.111-.597t.111-.596q.112-.298.354-.54L9.47 3.22q.221-.221.532-.218q.31.003.532.224q.22.221.22.529t-.22.529z" />
      </svg>
      {!iconOnly && <span>{label}</span>}
    </>
  );
  const common = { className: classes, 'aria-label': label, title: label, onClick };
  return to ? <Link to={to} {...common}>{content}</Link> : <button type="button" disabled={disabled} {...common}>{content}</button>;
}
