/**
 * Reference-matched account masthead. Visual hierarchy follows the approved
 * dashboard mockup; all identity and counts are real authenticated data.
 */
import { ChevronRight, UserRound } from 'lucide-react';
const joinName = (user) => [user?.first_name, user?.last_name].filter(Boolean).join(' ').trim();

export default function AccountMasthead({ user, metrics, metricsLoading, onOpenTab }) {
  const fullName = joinName(user) || user?.display_name || 'Your account';
  const greeting = (user?.first_name || String(fullName).split(/\s+/)[0] || 'there').trim();
  const initials = (joinName(user) || fullName).split(/\s+/).slice(0, 2).map((part) => part[0] || '').join('').toUpperCase();
  return (
    <section className="dtb-masthead" aria-label="Your account summary">
      <div className="dtb-masthead__art" aria-hidden="true"><span/><span/><span/></div>
      <div className="dtb-masthead__inner dtb-account-container">
        <div className="dtb-masthead__top">
          <div className="dtb-masthead__intro">
            <h1>Welcome back, <span>{greeting}.</span></h1>
            <p className="dtb-masthead__subtitle">Your tools. Your orders. Your jobs. All in one place.</p>
          </div>
          <button type="button" className="dtb-masthead-profile" onClick={() => onOpenTab('settings')} aria-label="Open account settings">
            <div className="dtb-masthead-profile__upper">
              <span className="dtb-masthead-profile__avatar" aria-hidden="true">{initials || <UserRound size={24}/>}</span>
              <span className="dtb-masthead-profile__identity"><strong>{fullName}</strong><small>Manage your account</small></span>
              <ChevronRight size={21} aria-hidden="true"/>
            </div>
            <div className="dtb-masthead-profile__details dtb-masthead-profile__details--metrics" aria-label="Your current account activity">
              {[
                ['ACTIVE ORDERS', metrics?.active_orders],
                ['ACTIVE REPAIRS', metrics?.active_repairs],
                ['ACTIVE JOBS', metrics?.active_jobs],
              ].map(([label, value]) => (
                <span key={label}><small>{label}</small><strong aria-label={metricsLoading || value == null ? label + ' loading' : label + ': ' + value}>{metricsLoading || value == null ? '—' : value}</strong></span>
              ))}
            </div>
          </button>
        </div>
      </div>
    </section>
  );
}
