import { ChevronRight, ImageOff } from 'lucide-react';

export function SelectorGrid({ children, variant = 'categories', className = '' }) {
  return (
    <div className={`dtb-selector-grid dtb-selector-grid--${variant}${className ? ` ${className}` : ''}`}>
      {children}
    </div>
  );
}

export function MediaSelectorCard({
  title,
  meta,
  image,
  imageAlt = '',
  imageSrcSet,
  imageSizes,
  imageLoading = 'lazy',
  imageFetchPriority,
  onClick,
  className = '',
  media,
}) {
  return (
    <button
      type="button"
      className={`dtb-selector-card dtb-selector-card--media${className ? ` ${className}` : ''}`}
      onClick={onClick}
    >
      {media || (image ? (
        <img
          src={image}
          srcSet={imageSrcSet}
          sizes={imageSizes}
          alt={imageAlt}
          className="dtb-selector-card__image"
          loading={imageLoading}
          fetchPriority={imageFetchPriority}
          decoding="async"
        />
      ) : (
        <span className="dtb-selector-card__image-fallback" aria-hidden="true">
          <ImageOff size={26} />
        </span>
      ))}
      <span className="dtb-selector-card__scrim" aria-hidden="true" />
      <span className="dtb-selector-card__overlay">
        <span className="dtb-selector-card__text">
          <span className="dtb-selector-card__title">{title}</span>
          {meta ? <span className="dtb-selector-card__meta">{meta}</span> : null}
        </span>
        <ChevronRight className="dtb-selector-card__chevron" size={18} aria-hidden="true" />
      </span>
    </button>
  );
}

export function BrandSelectorCard({
  name,
  logo,
  meta,
  onClick,
  className = '',
  style,
  logoStyle,
}) {
  return (
    <button
      type="button"
      role="listitem"
      className={`dtb-selector-card dtb-selector-card--brand${className ? ` ${className}` : ''}`}
      style={style}
      onClick={onClick}
    >
      {logo ? (
        <img
          src={logo}
          alt={`${name} logo`}
          className="dtb-selector-card__brand-logo"
          style={logoStyle}
          loading="lazy"
          decoding="async"
        />
      ) : (
        <span className="dtb-selector-card__brand-fallback" aria-hidden="true">
          <ImageOff size={28} />
        </span>
      )}
      <span className={`dtb-selector-card__title${logo ? ' dtb-selector-card__title--visually-hidden' : ''}`}>{name}</span>
      {meta ? <span className="dtb-selector-card__brand-meta">{meta}</span> : null}
    </button>
  );
}
