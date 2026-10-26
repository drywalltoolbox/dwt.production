import { Link } from 'react-router-dom';
import RepairGuarantees from './RepairGuarantees';
import ResponsivePicture from './media/ResponsivePicture.jsx';
import { RESPONSIVE_HERO_MEDIA } from '../assets/media/responsiveHeroMedia.js';

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
              {campaign.primaryAction.label}<Arrow />
            </Link>
            <Link className="repair-button repair-button--secondary" to={campaign.secondaryAction.to}>
              {campaign.secondaryAction.label}<Arrow />
            </Link>
            {campaign.id === 'repairs' && (
              <Link className="repair-button repair-button--utility" to="/repairs/track">
                Track Repair<Arrow />
              </Link>
            )}
          </div>
        </div>
      </div>
      <RepairGuarantees />
    </section>
  );
}
