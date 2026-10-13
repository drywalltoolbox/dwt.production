import InteriorPageHero from './InteriorPageHero.jsx';

/** Backwards-compatible presentation API for non-catalog informational headers. */
export default function PageHeroBanner({ eyebrow, title, highlight, description }) {
  return (
    <InteriorPageHero
      id="dtb-page-hero-title"
      title={<>{title}{highlight ? <> {highlight}</> : null}</>}
      description={description}
      breadcrumbs={[{ label: 'Home', path: '/' }, { label: title }]}
    />
  );
}
