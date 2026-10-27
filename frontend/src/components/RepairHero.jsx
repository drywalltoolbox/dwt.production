import { Link } from 'react-router-dom';
import RepairGuarantees from './RepairGuarantees';
import ResponsivePicture from './media/ResponsivePicture.jsx';
import { RESPONSIVE_HERO_MEDIA } from '../assets/media/responsiveHeroMedia.js';

function ActionIcon({ primary }) {
  return (
    <span className="repair-button__icon" aria-hidden="true">
      {primary ? (
        <svg viewBox="0 0 24 24" fill="none"><path d="M14.7 6.2a5.1 5.1 0 0 0-6.3 6.3L3 17.9a2.2 2.2 0 0 0 3.1 3.1l5.4-5.4a5.1 5.1 0 0 0 6.3-6.3l-3 3-3.1-.7-.7-3.1 3.7-2.3Z" fill="currentColor" /></svg>
      ) : (
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><rect x="5" y="3" width="14" height="18" rx="2" /><path d="M9 8h6M9 12h6M9 16h4" /></svg>
      )}
    </span>
  );
}

function Arrow() {
  return (
    <span className="repair-button__arrow" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none">
        <path d="M4.75 12h14.5M12.5 5.25 19.25 12l-6.75 6.75" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
    </span>
  );
}

export default function RepairHero({
  campaign,
  titleId = 'repair-hero-title',
  media = RESPONSIVE_HERO_MEDIA.repairs,
  homeSlide = false,
  isActive = true,
}) {
  return (
    <section
      className={`repair-hero${homeSlide ? ' repair-hero--home-slide' : ''}`}
      aria-labelledby={titleId}
    >
      <ResponsivePicture
        className="repair-hero__media"
        imageClassName="repair-hero__image"
        media={media}
        decorative
        loading={homeSlide || isActive ? 'eager' : 'lazy'}
        fetchPriority={isActive ? 'high' : 'auto'}
      />
      <div className="repair-hero__scrim" aria-hidden="true" />
      <div className="repair-hero__inner">
        <div className="repair-hero__content">
          <p className="repair-eyebrow">{campaign.eyebrow}</p>
          <h1 id={titleId}>
            {campaign.titleLines.map((line, index) => (
              <span
                className={`repair-hero__title-line${index === campaign.accentLine ? ' repair-hero__title-line--accent' : ' repair-hero__title-line--primary'}`}
                key={line}
              >
                {line}
              </span>
            ))}
          </h1>
          <p className="repair-hero__lead">{campaign.description}</p>
          <div className="repair-hero__actions" aria-label={`${campaign.label} actions`}>
            <Link className="repair-button repair-button--primary" to={campaign.primaryAction.to}>
              {!homeSlide && <ActionIcon primary />}
              <span className="repair-button__copy"><span className="repair-button__label"><span className="repair-button__default-label">{campaign.primaryAction.label}</span>{!homeSlide && <span className="repair-button__mobile-label">Start a Repair</span>}</span>{!homeSlide && <span className="repair-button__detail">Get your tools back to work faster.</span>}</span><Arrow />
            </Link>
            <Link className="repair-button repair-button--secondary" to={campaign.secondaryAction.to}>
              {!homeSlide && <ActionIcon />}
              <span className="repair-button__copy"><span className="repair-button__label">{campaign.secondaryAction.label}</span>{!homeSlide && <span className="repair-button__detail">See all repair solutions.</span>}</span><Arrow />
            </Link>
            {campaign.id === 'repairs' && (
              <Link className="repair-track-link" to="/repairs/track">
                <span className="repair-track-link__circle" aria-hidden="true"><span className="repair-track-link__arrow" /></span>
                <span className="repair-track-link__text">Track Repair</span>
              </Link>
            )}
          </div>
        </div>
      </div>
      <RepairGuarantees />
    </section>
  );
}
