import { Link } from 'react-router-dom';
import { ShoppingBag, Tags, Search, BookOpen, Wrench, ClipboardList } from 'lucide-react';
import RepairGuarantees from './RepairGuarantees';
import ResponsivePicture from './media/ResponsivePicture.jsx';
import { RESPONSIVE_HERO_MEDIA } from '../assets/media/responsiveHeroMedia.js';

const ACTION_PRESENTATION = {
  tools: {
    primary: { Icon: ShoppingBag, detail: 'Explore professional tools.', homeDetail: 'Shop pro tools' },
    secondary: { Icon: Tags, detail: 'Find your trusted brands.', homeDetail: 'Find your brand' },
  },
  parts: {
    primary: { Icon: Search, detail: 'Find the part you need.', homeDetail: 'Find your part' },
    secondary: { Icon: BookOpen, detail: 'Explore tool schematics.', homeDetail: 'View diagrams' },
  },
  repairs: {
    primary: { Icon: Wrench, detail: 'Get your tools back to work faster.', homeDetail: 'Get back to work' },
    secondary: { Icon: ClipboardList, detail: 'See all repair solutions.', homeDetail: 'See repair options' },
  },
};

function ActionIcon({ Icon }) {
  return <span className="repair-button__icon" aria-hidden="true"><Icon /></span>;
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
  const actionPresentation = ACTION_PRESENTATION[campaign.id];

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
              {actionPresentation && <ActionIcon Icon={actionPresentation.primary.Icon} />}
              <span className="repair-button__copy"><span className="repair-button__label"><span className={campaign.id === 'repairs' ? 'repair-button__default-label' : undefined}>{campaign.primaryAction.label}</span>{campaign.id === 'repairs' && <span className="repair-button__mobile-label">Start a Repair</span>}</span>{actionPresentation && <span className="repair-button__detail">{homeSlide ? actionPresentation.primary.homeDetail : actionPresentation.primary.detail}</span>}</span><Arrow />
            </Link>
            <Link className="repair-button repair-button--secondary" to={campaign.secondaryAction.to}>
              {actionPresentation && <ActionIcon Icon={actionPresentation.secondary.Icon} />}
              <span className="repair-button__copy"><span className="repair-button__label">{campaign.secondaryAction.label}</span>{actionPresentation && <span className="repair-button__detail">{homeSlide ? actionPresentation.secondary.homeDetail : actionPresentation.secondary.detail}</span>}</span><Arrow />
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
