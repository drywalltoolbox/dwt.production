import { useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, Check, Clock3, Mail, Phone } from 'lucide-react';
import SEOHead from '../components/shared/SEOHead';
import Dropdown from '../components/ui/Dropdown';
import { apiClient } from '../api/client';
import './contact.css';

const inquiryTypes = [
  'Technical Support',
  'Bulk Order Inquiry',
  'Returns & Warranty',
  'Parts Availability',
  'Custom Fabrication',
  'General Question',
];

export default function Contact() {
  const inquiryRef = useRef(null);
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    inquiryType: '',
    message: ''
  });
  const [submitting, setSubmitting] = useState(false);
  const [success, setSuccess]       = useState(false);
  const [tracking, setTracking]     = useState(null);
  const [error, setError]           = useState('');

  const handleChange = (e) => {
    setFormData({ ...formData, [e.target.name]: e.target.value });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
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
          subject: formData.inquiryType || 'General Question',
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
      setError(err?.message || 'Unable to send your message. Please call us directly at (609) 866-5269.');
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
          <p>Have a question, need product guidance, or want to talk to our team? Send us a message and we’ll get back to you as soon as possible.</p>
        </div>
      </header>

      <section className="dtb-contact__content" aria-label="Contact Drywall Toolbox">
        <aside className="dtb-contact__information">
          <h2>Contact Information</h2>
          <p className="dtb-contact__information-intro">Reach out directly or use the form and our support team will respond as soon as possible.</p>
          <div className="dtb-contact__details">
            <div className="dtb-contact__detail">
              <span className="dtb-contact__detail-icon"><Phone size={23} strokeWidth={1.9} aria-hidden="true" /></span>
              <div><span className="dtb-contact__detail-label">Phone</span><a href="tel:+16098665269">(609) 866-5269</a></div>
            </div>
            <div className="dtb-contact__detail">
              <span className="dtb-contact__detail-icon"><Mail size={23} strokeWidth={1.9} aria-hidden="true" /></span>
              <div><span className="dtb-contact__detail-label">Email</span><a href="mailto:support@drywalltoolbox.com">support@drywalltoolbox.com</a></div>
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
          <h2 id="dtb-contact-form-title">Send Us a Message</h2>
          {success ? (
            <div className="dtb-contact__success" role="status">
              <span className="dtb-contact__success-icon"><Check size={28} aria-hidden="true" /></span>
              <h3>Message sent!</h3>
              <p>Your inquiry has been received. Our team will respond as soon as possible.</p>
              {tracking && (
                <Link className="dtb-contact__ticket-link" to={`/support/status/${tracking.id}?token=${encodeURIComponent(tracking.token)}`}>
                  Track {tracking.number || 'your ticket'} <ArrowRight size={16} aria-hidden="true" />
                </Link>
              )}
              <button type="button" className="dtb-contact__submit dtb-contact__reset" onClick={() => { setSuccess(false); setTracking(null); }}>
                Send another message
              </button>
            </div>
          ) : (
            <form className="dtb-contact__form" onSubmit={handleSubmit}>
              {error && <p className="dtb-contact__error" role="alert">{error}</p>}
              <div className="dtb-contact__field-row">
                <div className="dtb-contact__field">
                  <label htmlFor="dtb-contact-name">Full Name</label>
                  <input id="dtb-contact-name" type="text" name="name" autoComplete="name" value={formData.name} onChange={handleChange} placeholder="John Doe" required disabled={submitting} />
                </div>
                <div className="dtb-contact__field">
                  <label htmlFor="dtb-contact-email">Email Address</label>
                  <input id="dtb-contact-email" type="email" name="email" autoComplete="email" value={formData.email} onChange={handleChange} placeholder="you@example.com" required disabled={submitting} />
                </div>
              </div>
              <div className="dtb-contact__field">
                <label htmlFor="dtb-contact-type">Inquiry Type</label>
                <Dropdown
                  id="dtb-contact-type"
                  triggerRef={inquiryRef}
                  value={formData.inquiryType}
                  onChange={(value) => { setFormData((current) => ({ ...current, inquiryType: value })); setError(''); }}
                  options={inquiryTypes.map((type) => ({ value: type, label: type }))}
                  placeholder="Select an inquiry type"
                  required
                  disabled={submitting}
                  fullWidth
                  className="dtb-contact__inquiry-dropdown"
                />
              </div>
              <div className="dtb-contact__field">
                <label htmlFor="dtb-contact-message">Message</label>
                <textarea id="dtb-contact-message" rows={4} name="message" value={formData.message} onChange={handleChange} placeholder="How can we help?" required disabled={submitting} />
              </div>
              <button type="submit" className="dtb-contact__submit" disabled={submitting}>
                {submitting ? 'Sending…' : 'Submit Inquiry'} {!submitting && <ArrowRight size={18} aria-hidden="true" />}
              </button>
            </form>
          )}
        </section>
      </section>
    </main>
  );
}
