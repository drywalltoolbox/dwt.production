import BackButton from '../components/shared/BackButton.jsx';
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link, useSearchParams } from 'react-router-dom';
import { ArrowRight, Check, Clock3, Mail, Phone, X } from 'lucide-react';
import SEOHead from '../components/shared/SEOHead';
import Dropdown from '../components/ui/Dropdown';
import { apiClient } from '../api/client';
import './contact.css';

const inquiryTypes = [
  { value: 'orders_shipping', label: 'Orders, Shipping & Delivery' },
  { value: 'product_guidance', label: 'Product Questions & Technical Support' },
  { value: 'parts_compatibility', label: 'Replacement Parts & Compatibility' },
  { value: 'repairs_service', label: 'Repairs & Service' },
  { value: 'returns_warranty', label: 'Returns & Warranty' },
  { value: 'business_orders', label: 'Bulk & Business Orders' },
  { value: 'other', label: 'Other' },
];

const legacyInquiryTypes = {
  'Technical Support': 'product_guidance',
  'Bulk Order Inquiry': 'business_orders',
  'Returns & Warranty': 'returns_warranty',
  'Parts Availability': 'parts_compatibility',
  'Custom Fabrication': 'product_guidance',
  'General Question': 'other',
};

function resolveInquiryType(value) {
  const requested = String(value || '').trim();
  if (inquiryTypes.some((type) => type.value === requested)) return requested;
  return legacyInquiryTypes[requested] || '';
}

export default function Contact() {
  const [searchParams] = useSearchParams();
  const inquiryRef = useRef(null);
  const dialogRef = useRef(null);
  const openerRef = useRef(null);
  const [wizardOpen, setWizardOpen] = useState(false);
  const [step, setStep] = useState(0);
  const [formData, setFormData] = useState(() => {
    const requestedInquiry = resolveInquiryType(searchParams.get('inquiry'));
    const sku = (searchParams.get('sku') || '').trim();
    const handFinishing = searchParams.get('topic') === 'hand-finishing';
    return {
      name: '',
      email: '',
      inquiryType: requestedInquiry,
      message: handFinishing
        ? 'I need help choosing a hand-finishing setup. My typical work is: '
        : sku && /^[A-Za-z0-9._-]{1,80}$/.test(sku)
          ? `I am considering tool set ${sku}. My question is: `
          : '',
    };
  });
  const [submitting, setSubmitting] = useState(false);
  const submittingRef = useRef(false);
  submittingRef.current = submitting;
  const [success, setSuccess]       = useState(false);
  const [tracking, setTracking]     = useState(null);
  const [error, setError]           = useState('');

  useEffect(() => {
    if (!wizardOpen) return undefined;
    const body = document.body;
    const html = document.documentElement;
    const scrollY = window.scrollY;
    const scrollX = window.scrollX;
    const prior = {
      position: body.style.position,
      top: body.style.top,
      left: body.style.left,
      right: body.style.right,
      width: body.style.width,
      overflow: body.style.overflow,
      htmlOverflow: html.style.overflow,
    };
    // iOS Safari does not reliably prevent touch scrolling with body
    // overflow:hidden alone. Fix the page in place and keep scroll state.
    body.style.position = 'fixed';
    body.style.top = `-${scrollY}px`;
    body.style.left = `-${scrollX}px`;
    body.style.right = '0';
    body.style.width = '100%';
    body.style.overflow = 'hidden';
    html.style.overflow = 'hidden';
    const dialog = dialogRef.current;
    const focusable = () => [...(dialog?.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled]), [tabindex="0"]') || [])]
      .filter((item) => item.getClientRects().length);
    (focusable()[0] || dialog)?.focus({ preventScroll: true });
    const onKeyDown = (event) => {
      if (event.key === 'Escape' && !submittingRef.current) {
        event.preventDefault();
        setWizardOpen(false);
      }
      if (event.key !== 'Tab') return;
      const items = focusable();
      if (!items.length) { event.preventDefault(); return; }
      const first = items[0];
      const last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault(); last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault(); first.focus();
      }
    };
    document.addEventListener('keydown', onKeyDown);
    return () => {
      body.style.position = prior.position;
      body.style.top = prior.top;
      body.style.left = prior.left;
      body.style.right = prior.right;
      body.style.width = prior.width;
      body.style.overflow = prior.overflow;
      html.style.overflow = prior.htmlOverflow;
      window.scrollTo({ left: scrollX, top: scrollY, behavior: 'instant' });
      document.removeEventListener('keydown', onKeyDown);
      openerRef.current?.focus({ preventScroll: true });
    };
  }, [wizardOpen]);

  const advance = () => {
    setError('');
    if (step === 0) {
      const name = document.getElementById('dtb-contact-name');
      const email = document.getElementById('dtb-contact-email');
      if (!name?.reportValidity()) return;
      if (!email?.reportValidity()) return;
    }
    if (step === 1 && !formData.inquiryType) {
      setError('Select an inquiry type to continue.');
      inquiryRef.current?.focus();
      return;
    }
    setStep((current) => Math.min(2, current + 1));
  };

  const handleChange = (e) => {
    setFormData({ ...formData, [e.target.name]: e.target.value });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!formData.message.trim()) { setError('Enter your inquiry message.'); return; }
    if (!formData.inquiryType) {
      setError('Please select an inquiry type.');
      inquiryRef.current?.focus();
      return;
    }
    setError('');
    setSubmitting(true);

    try {
      const response = await apiClient('/wp-json/dtb/v1/support/submit', {
        method: 'POST',
        body: JSON.stringify({
          name:    formData.name,
          email:   formData.email,
          subject: inquiryTypes.find((type) => type.value === formData.inquiryType)?.label || 'Other',
          message: formData.message,
          type:    'contact',
          website: '', // honeypot — intentionally blank
        }),
      });
      setTracking(
        response?.ticket_id && response?.public_token
          ? { id: response.ticket_id, token: response.public_token, number: response.ticket_number }
          : null
      );
      setSuccess(true);
      setFormData({ name: '', email: '', inquiryType: '', message: '' });
    } catch (err) {
      setError(err?.message || 'Unable to send your message. Please call us directly at 612-978-1007.');
    } finally {
      setSubmitting(false);
    }
  };


  return (
    <main className="dtb-contact page-wrapper">
      <SEOHead
        title="Contact Us"
        description="Get in touch with the Drywall Toolbox team. Expert support from real people who know drywall tools."
        canonical="/contact"
      />

      <header className="dtb-contact__hero">
        <div className="dtb-contact__hero-inner">
          <span className="dtb-contact__eyebrow">Contact Us</span>
          <h1>We're here to help.</h1>
        </div>
      </header>

      <section className="dtb-contact__content" aria-label="Contact Drywall Toolbox">
        <aside className="dtb-contact__information">
          <h2>Contact Information</h2>
          <p className="dtb-contact__information-intro">Reach out directly or use our seamless inquiry form. Our experts will get back to you soon.</p>
          <div className="dtb-contact__details">
            <div className="dtb-contact__detail">
              <span className="dtb-contact__detail-icon"><Phone size={23} strokeWidth={1.9} aria-hidden="true" /></span>
              <div><span className="dtb-contact__detail-label">Phone</span><a href="tel:+16129781007">612-978-1007</a></div>
            </div>
            <div className="dtb-contact__detail">
              <span className="dtb-contact__detail-icon"><Mail size={23} strokeWidth={1.9} aria-hidden="true" /></span>
              <div><span className="dtb-contact__detail-label">Email</span><a href="mailto:dtb@drywalltoolbox.com">dtb@drywalltoolbox.com</a></div>
            </div>
            <div className="dtb-contact__detail">
              <span className="dtb-contact__detail-icon"><Clock3 size={23} strokeWidth={1.9} aria-hidden="true" /></span>
              <div>
                <span className="dtb-contact__detail-label">Business Hours</span>
                <span className="dtb-contact__hours">Monday – Friday</span>
                <span className="dtb-contact__hours">8:00 AM – 5:00 PM Central Time (CT)</span>
              </div>
            </div>
          </div>
        </aside>

        <section className="dtb-contact__form-panel" aria-labelledby="dtb-contact-form-title">
          <h2 id="dtb-contact-form-title">Get the Help You Need.</h2>
          <p>Get expert help with tools, parts, orders, and repairs—all in one place.</p>
          <button ref={openerRef} type="button" className="dtb-contact__submit dtb-contact__open-wizard" onClick={() => { setWizardOpen(true); setStep(0); setError(''); }}>
            Send an Inquiry <ArrowRight size={18} aria-hidden="true" />
          </button>
        </section>
        {wizardOpen && createPortal(
          <div className="dtb-contact__dialog-backdrop" onMouseDown={(event) => { if (event.target === event.currentTarget && !submitting) setWizardOpen(false); }}>
            <section ref={dialogRef} role="dialog" aria-modal="true" aria-labelledby="dtb-contact-dialog-title" tabIndex={-1} className="dtb-contact__dialog">
              <div className="dtb-contact__dialog-header">
                <div>
                  <span className="dtb-contact__form-kicker">DRYWALL TOOLBOX · SUPPORT</span>
                  <h2 id="dtb-contact-dialog-title">{success ? 'Inquiry received' : 'Contact our team'}</h2>
                </div>
                <button type="button" className="dtb-contact__dialog-close" aria-label="Close inquiry" onClick={() => !submitting && setWizardOpen(false)} disabled={submitting}><X size={20} aria-hidden="true" /></button>
              </div>
              {success ? (
                <div className="dtb-contact__success" role="status">
                  <span className="dtb-contact__success-icon"><Check size={28} aria-hidden="true" /></span>
                  <h3>Message sent!</h3>
                  <p>Your inquiry has been received. Our team will respond as soon as possible.</p>
                  {tracking && <Link className="dtb-contact__ticket-link" to={`/support/status/${tracking.id}?token=${encodeURIComponent(tracking.token)}`}>Track {tracking.number || 'your ticket'} <ArrowRight size={16} aria-hidden="true" /></Link>}
                  <button type="button" className="dtb-contact__submit" onClick={() => { setSuccess(false); setTracking(null); setWizardOpen(false); }}>Done</button>
                  <button type="button" className="dtb-contact__dialog-secondary" onClick={() => { setSuccess(false); setTracking(null); setStep(0); }}>Send another inquiry</button>
                </div>
              ) : (
                <>
                  <ol className="dtb-contact__steps" aria-label="Inquiry progress">
                    {['Contact details', 'Inquiry type', 'Your message'].map((label, index) => (
                      <li key={label} className={index === step ? 'is-current' : index < step ? 'is-complete' : ''} aria-current={index === step ? 'step' : undefined}>
                        <span className="dtb-contact__step-number">{index < step ? <Check size={14} aria-hidden="true" /> : index + 1}</span>
                        <span>{label}</span>
                      </li>
                    ))}
                  </ol>
                  <form className="dtb-contact__form dtb-contact__wizard-form" noValidate onSubmit={(event) => {
                    if (step !== 2) { event.preventDefault(); advance(); return; }
                    handleSubmit(event);
                  }}>
                    {error && <p className="dtb-contact__error" role="alert">{error}</p>}
                    {step === 0 && (
                      <div className="dtb-contact__wizard-step">
                        <h3>Your contact information</h3>
                        <p>We'll use these details to respond to your inquiry.</p>
                        <div className="dtb-contact__field"><label htmlFor="dtb-contact-name">Full name</label><input id="dtb-contact-name" type="text" name="name" autoComplete="name" value={formData.name} onChange={handleChange} placeholder="Full name" required disabled={submitting} /></div>
                        <div className="dtb-contact__field"><label htmlFor="dtb-contact-email">Email address</label><input id="dtb-contact-email" type="email" name="email" autoComplete="email" value={formData.email} onChange={handleChange} placeholder="you@example.com" required disabled={submitting} /></div>
                      </div>
                    )}
                    {step === 1 && (
                      <div className="dtb-contact__wizard-step">
                        <h3>What can we help with?</h3>
                        <p>Choose the topic that best matches your request.</p>
                        <div className="dtb-contact__field"><label htmlFor="dtb-contact-type">Inquiry type</label>
                          <Dropdown id="dtb-contact-type" triggerRef={inquiryRef} value={formData.inquiryType} onChange={(value) => { setFormData((current) => ({ ...current, inquiryType: value })); setError(''); }} options={inquiryTypes} placeholder="Select an inquiry type" required fitViewport disabled={submitting} fullWidth className="dtb-contact__inquiry-dropdown" />
                        </div>
                      </div>
                    )}
                    {step === 2 && (
                      <div className="dtb-contact__wizard-step">
                        <h3>Describe your inquiry</h3>
                        <p>Include the product, model, or order information if relevant. Do not include payment details.</p>
                        <div className="dtb-contact__field"><label htmlFor="dtb-contact-message">Message</label><textarea id="dtb-contact-message" rows={6} name="message" value={formData.message} onChange={handleChange} placeholder="How can we help?" required disabled={submitting} /></div>
                      </div>
                    )}
                    <div className="dtb-contact__wizard-actions">
                      {step > 0 ? <BackButton onClick={() => { setStep((current) => current - 1); setError(''); }} disabled={submitting} label="Back" /> : <button type="button" className="dtb-contact__dialog-secondary" onClick={() => setWizardOpen(false)}>Cancel</button>}
                      <button type="submit" className="dtb-contact__submit" disabled={submitting}>{step === 2 ? (submitting ? 'Sending…' : 'Submit inquiry') : 'Continue'} <ArrowRight size={17} aria-hidden="true" /></button>
                    </div>
                  </form>
                </>
              )}
            </section>
          </div>,
          document.body
        )}
      </section>
    </main>
  );
}
