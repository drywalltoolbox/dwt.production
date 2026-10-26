export default function RepairGuarantees() {
  return (
    <div className="repair-guarantees" aria-label="Our repair service promises">
      <ul className="repair-guarantees__grid">
        <li className="repair-guarantees__item">
          <span className="repair-guarantees__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M12 2.8 14.2 5l3.1-.1.8 3 2.7 1.5-1 2.9 1 2.9-2.7 1.5-.8 3-3.1-.1-2.2 2.2-2.2-2.2-3.1.1-.8-3-2.7-1.5 1-2.9-1-2.9 2.7-1.5.8-3 3.1.1L12 2.8Z" stroke="currentColor" strokeWidth="1.8"/><circle cx="12" cy="12.4" r="3.1" stroke="currentColor" strokeWidth="1.8"/></svg></span>
          <div>
            <h2>Genuine OEM Parts</h2>
            <p>We use genuine OEM parts to keep your tools performing the way they were built to.</p>
          </div>
        </li>

        <li className="repair-guarantees__item">
          <span className="repair-guarantees__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="m14.5 3.5 6 6-9.8 9.8a2.1 2.1 0 0 1-3-3l6.8-6.8ZM12 6l6 6M5.5 20.5l-2 1" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"/></svg></span>
          <div>
            <h2>Transparent Pricing</h2>
            <p>Clear estimates, no hidden fees, and no surprise charges. Nothing extra moves forward without your approval.</p>
          </div>
        </li>

        <li className="repair-guarantees__item">
          <span className="repair-guarantees__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M12 2.8 20 6v5.1c0 5-3.4 8.4-8 10.1-4.6-1.7-8-5.1-8-10.1V6l8-3.2Z" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round"/><path d="m8.4 12.2 2.3 2.3 4.9-5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"/></svg></span>
          <div>
            <h2>Guaranteed Satisfaction</h2>
            <p>If something isn’t right after service, we’ll make it right at no cost to you.</p>
          </div>
        </li>
      </ul>
    </div>
  );
}
