/**
 * Reference-matched account masthead. Visual hierarchy follows the approved
 * dashboard mockup; all identity and counts are real authenticated data.
 */
import { ChevronRight, MapPin, UserRound } from 'lucide-react';
const joinName = (user) => [user?.first_name, user?.last_name].filter(Boolean).join(' ').trim();

export default function AccountMasthead({ user, onOpenTab }) {
  const fullName = joinName(user) || user?.display_name || 'Your account';
  const greeting = (user?.first_name || String(fullName).split(/\s+/)[0] || 'there').trim();
  const initials = (joinName(user) || fullName).split(/\s+/).slice(0, 2).map((part) => part[0] || '').join('').toUpperCase();
  const since = user?.registered ? new Date(user.registered) : null;
  const membership = since && !Number.isNaN(since.getTime())
    ? `Customer since ${since.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })}`
    : 'Drywall Toolbox account';
  const accountRole = String(user?.roles?.[0] || 'customer').replace(/_/g, ' ');
  const location = [user?.billing_city, user?.billing_state].filter(Boolean).join(', ');
  return (
    <section className="dtb-masthead" aria-label="Your account summary">
      <div className="dtb-masthead__art" aria-hidden="true"><span/><span/><span/></div>
      <div className="dtb-masthead__inner dtb-account-container">
        <div className="dtb-masthead__top">
          <div className="dtb-masthead__intro">
            <p className="dtb-masthead__eyebrow">CONTRACTOR ACCOUNT</p>
            <h1>Welcome back, <span>{greeting}.</span></h1>
            <p className="dtb-masthead__subtitle">Your tools. Your orders. Your jobs. All in one place.</p>
          </div>
          <button type="button" className="dtb-masthead-profile" onClick={() => onOpenTab('settings')} aria-label="Open account settings">
            <div className="dtb-masthead-profile__upper">
              <span className="dtb-masthead-profile__avatar" aria-hidden="true">{initials || <UserRound size={24}/>}</span>
              <span className="dtb-masthead-profile__identity"><strong>{fullName}</strong><small>{membership}</small></span>
              <ChevronRight size={21} aria-hidden="true"/>
            </div>
            <div className="dtb-masthead-profile__details">
              <span><UserRound size={19} aria-hidden="true"/><span><small>ACCOUNT TYPE</small><strong>{accountRole}</strong></span></span>
              <span><MapPin size={19} aria-hidden="true"/><span><small>{location ? 'LOCATION' : 'YOUR ACCOUNT'}</small><strong>{location || 'Manage profile'}</strong></span></span>
            </div>
          </button>
        </div>
      </div>
    </section>
  );
}
