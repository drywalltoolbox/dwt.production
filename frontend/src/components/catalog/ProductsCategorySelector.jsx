import BackButton from '../shared/BackButton.jsx';
import { useEffect, useMemo, useState } from 'react';
import { fetchCatalogProducts } from '../../services/catalogPlatformCache.js';
import { brandToSlug } from '../../utils/catalogUrlState.js';
import { resolveCategoryThumbnail } from '../../utils/categoryThumbnailImages.js';
import { MediaSelectorCard, SelectorGrid } from '../selectors/SelectorCards.jsx';
import './products-selector.css';

const ALL_PRODUCTS_CATEGORY = {
  key: 'all-products',
  slug: 'all-products',
  name: 'All Products',
  isAllProducts: true,
};

const ALL_PRODUCTS_PREVIEW_LIMIT = 8;
const ALL_PRODUCTS_SLIDE_INTERVAL_MS = 3800;

/*
 * Product discovery owns brand/category preview selection. These mappings are
 * deliberately retained here while card geometry/presentation is shared with
 * the schematics selector system.
 */
const COLUMBIA_CATEGORY_IMAGE_OVERRIDES = {
  'automatic_tapers': '/wp/wp-content/uploads/2026/media/columbia_tools_taper_01-scaled.webp',
  'automatic-tapers': '/wp/wp-content/uploads/2026/media/columbia_tools_taper_01-scaled.webp',
  'compound_tubes': '/wp/wp-content/uploads/2026/media/columbia_tools_pcmt42_01.webp',
  'compound-tubes': '/wp/wp-content/uploads/2026/media/columbia_tools_pcmt42_01.webp',
  'corner_flushers': '/wp/wp-content/uploads/2026/media/columbia_tools_2_5df_01.webp',
  'corner-flushers': '/wp/wp-content/uploads/2026/media/columbia_tools_2_5df_01.webp',
  'corner_tools': '/wp-content/uploads/2026/media/columbia_tools_8cfb_01.webp',
  'corner-tools': '/wp-content/uploads/2026/media/columbia_tools_8cfb_01.webp',
  'finishing_boxes': '/wp-content/uploads/2026/media/columbia_tools_10ffba_01.webp',
  'finishing-boxes': '/wp-content/uploads/2026/media/columbia_tools_10ffba_01.webp',
  'handles': '/wp-content/uploads/2026/media/columbia_tools_mh_all_01.webp',
  'handles_extensions': '/wp-content/uploads/2026/media/columbia_tools_mh_all_01.webp',
  'handles-extensions': '/wp-content/uploads/2026/media/columbia_tools_mh_all_01.webp',
  'nail_spotters': '/wp-content/uploads/2026/media/columbia_tools_3ns_01.webp',
  'nail-spotters': '/wp-content/uploads/2026/media/columbia_tools_3ns_01.webp',
  'parts': 'https://cdn.shopify.com/s/files/1/0277/6678/4139/products/FA268_bd097418-98a3-491a-a1ab-f39a2cae3ba0.jpg?v=1764182531',
  'replacement_parts': 'https://cdn.shopify.com/s/files/1/0277/6678/4139/products/FA268_bd097418-98a3-491a-a1ab-f39a2cae3ba0.jpg?v=1764182531',
  'replacement-parts': 'https://cdn.shopify.com/s/files/1/0277/6678/4139/products/FA268_bd097418-98a3-491a-a1ab-f39a2cae3ba0.jpg?v=1764182531',
  'pumps': '/wp-content/uploads/2026/media/columbia_tools_hmp_01.webp',
  'predator_family': '/wp-content/uploads/2026/media/columbia_tools_predator_family_01.webp',
  'predator-family': '/wp-content/uploads/2026/media/columbia_tools_predator_family_01.webp',
  'semi_automatic_tapers': '/wp-content/uploads/2026/media/columbia_tools_sat_01.webp',
  'semi-automatic-tapers': '/wp-content/uploads/2026/media/columbia_tools_sat_01.webp',
  'semi_automatic_taper': '/wp-content/uploads/2026/media/columbia_tools_sat_01.webp',
  'semi-automatic-taper': '/wp-content/uploads/2026/media/columbia_tools_sat_01.webp',
  'toolsets': '/wp-content/uploads/2026/media/columbia_tools_ts_01.webp',
  'tool-sets-kits': '/wp-content/uploads/2026/media/columbia_tools_ts_01.webp',
};

const LEVEL5_CATEGORY_IMAGE_OVERRIDES = {
  'automatic_tapers': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/level5_4_760_07.webp',
  'compound_tubes': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/level5_4_741_01.webp',
  'corner_finishers': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/level5_4_733_01.webp',
  'flat_boxes': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/level5_4_766_01.webp',
  'powered_compound_applicators': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/level5_4_772_01.webp',
  'semi_automatic_tapers_banjos': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/level5_5_311_01.webp',
};

const PLATINUM_CATEGORY_IMAGE_OVERRIDES = {
  'applicator_heads': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/platinum_pt_aica_01.webp',
  'automatic_compound_tubes': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/platinum_pt_ct42_01.webp',
  'automatic_goosenecks_box_fillers': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/platinum_pt_gn_01.webp',
  'compound_tubes': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/platinum_pt_ct42_01.webp',
  'goosenecks_box_fillers_adapters': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/platinum_pt_gn_01.webp',
  'handles': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/platinum_pt_ebh_01.webp',
  'handles_extensions': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/platinum_pt_ebh_01.webp',
  'handles-extensions': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/platinum_pt_ebh_01.webp',
  'semi_automatic_tapers_banjos': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/platinum_pt_tp_01.webp',
  'semi_automatic_tools': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/platinum_pt_tp_01.webp',
};

const CATEGORY_IMAGE_OVERRIDES = {
  level5: LEVEL5_CATEGORY_IMAGE_OVERRIDES,
  tapetech: {
    'applicator_heads': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/tapetech_16tt_02.webp',
    'automatic_corner_flushers': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/tapetech_cf25tt_01.webp',
    'automatic_goosenecks_box_fillers': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/tapetech_85t_01.webp',
    'automatic_nail_spotters': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/tapetech_ns02tt_02.webp',
    'automatic_tapers': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/tapetech_07tt_02.webp',
    'automatic-tapers': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/tapetech_07tt_02.webp',
    'compound_tubes': '/wp-content/uploads/2026/media/tapetech_ct42tt_01.webp',
    'compound-tubes': '/wp-content/uploads/2026/media/tapetech_ct42tt_01.webp',
    'corner_tools': '/wp-content/uploads/2026/media/tapetech_ca08tt_01.webp',
    'corner-tools': '/wp-content/uploads/2026/media/tapetech_ca08tt_01.webp',
    'corner_flushers': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/tapetech_cf25tt_01.webp',
    'finishing_boxes': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/tapetech_ez15tt_03.webp',
    'finishing-boxes': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/tapetech_ez15tt_03.webp',
    'flat_boxes': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/tapetech_ez15tt_03.webp',
    'goosenecks_box_fillers_adapters': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/tapetech_85t_01.webp',
    'handles': '/wp-content/uploads/2026/media/tapetech_88tte_03.webp',
    'handles_extensions': '/wp-content/uploads/2026/media/tapetech_88tte_03.webp',
    'handles-extensions': '/wp-content/uploads/2026/media/tapetech_88tte_03.webp',
    'pumps': '/wp-content/uploads/2026/media/tapetech_76ttca_01.webp',
    'nail_spotters': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/tapetech_ns02tt_02.webp',
    'nail-spotters': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/media/tapetech_ns02tt_02.webp',
  },
  'columbia-tools': COLUMBIA_CATEGORY_IMAGE_OVERRIDES,
  'columbia-taping-tools': COLUMBIA_CATEGORY_IMAGE_OVERRIDES,
  platinum: PLATINUM_CATEGORY_IMAGE_OVERRIDES,
  'usg-sheetrock-tools': {
    'stilts': 'https://drywalltoolbox.com/wp/wp-content/uploads/2026/09/ChatGPT-Image-Sep-5-2026-09_38_49-AM-2.png',
  },
};

function resolveCategoryImage(brand, category) {
  const slug = brandToSlug(brand);
  const catKey = (category.key || category.slug || '').toLowerCase();
  const normalizedCatKey = catKey.replace(/_/g, '-');
  const underscoredCatKey = catKey.replace(/-/g, '_');
  const overrides = CATEGORY_IMAGE_OVERRIDES[slug] || {};
  return overrides[catKey] || overrides[normalizedCatKey] || overrides[underscoredCatKey] || resolveCategoryThumbnail(category);
}

const WORDPRESS_RESIZED_IMAGE_RE = /-\d+x\d+(?=\.(?:avif|jpe?g|png|webp)(?:[?#]|$))/i;
const SELECTOR_IMAGE_SIZES = '(min-width: 1024px) 25vw, (min-width: 768px) 33vw, 50vw';

function normalizePreviewImageUrl(src = '') {
  if (!src || src.startsWith('data:') || src.endsWith('.svg')) return src;
  return src.replace(WORDPRESS_RESIZED_IMAGE_RE, '');
}

function withUrlParam(src, key, value) {
  try {
    const url = new URL(src);
    url.searchParams.set(key, value);
    return url.toString();
  } catch {
    return src;
  }
}

function resolvePreviewImageMeta(src = '') {
  const normalizedSrc = normalizePreviewImageUrl(src);
  if (!normalizedSrc || normalizedSrc.endsWith('.svg')) {
    return { src: normalizedSrc, srcSet: undefined, sizes: undefined };
  }

  if (/cdn\.shopify\.com/i.test(normalizedSrc)) {
    return {
      src: withUrlParam(normalizedSrc, 'width', '960'),
      srcSet: [480, 720, 960, 1280]
        .map((width) => `${withUrlParam(normalizedSrc, 'width', String(width))} ${width}w`)
        .join(', '),
      sizes: SELECTOR_IMAGE_SIZES,
    };
  }

  return {
    src: normalizedSrc,
    srcSet: `${normalizedSrc} 1x, ${normalizedSrc} 2x`,
    sizes: SELECTOR_IMAGE_SIZES,
  };
}

function primaryProductImage(product = {}) {
  return product?.media?.image
    || product?.cardProduct?.image
    || product?.image
    || '';
}

function AllProductsSlideshow({ images }) {
  const [activeIndex, setActiveIndex] = useState(0);
  const [failedImages, setFailedImages] = useState(() => new Set());
  const availableImages = useMemo(
    () => images.filter((src) => src && !failedImages.has(src)),
    [failedImages, images],
  );

  useEffect(() => {
    if (availableImages.length < 2) return undefined;

    const advance = () => {
      if (document.visibilityState !== 'visible') return;
      setActiveIndex((current) => (current + 1) % availableImages.length);
    };
    const timer = window.setInterval(advance, ALL_PRODUCTS_SLIDE_INTERVAL_MS);
    return () => window.clearInterval(timer);
  }, [availableImages.length]);

  useEffect(() => {
    if (availableImages.length < 2) return;
    const nextImage = new Image();
    nextImage.src = availableImages[(activeIndex + 1) % availableImages.length];
  }, [activeIndex, availableImages]);

  if (availableImages.length === 0) return null;

  return (
    <span className="dtb-selector-card__slideshow" aria-hidden="true">
      {availableImages.map((src, index) => {
        const preview = resolvePreviewImageMeta(src);
        return (
          <img
            key={src}
            src={preview.src}
            srcSet={preview.srcSet}
            sizes={preview.sizes}
            alt=""
            className={`dtb-selector-card__slide${index === activeIndex % availableImages.length ? ' is-active' : ''}`}
            width={640}
            height={427}
            loading={index === 0 ? 'eager' : 'lazy'}
            fetchPriority={index === 0 ? 'high' : 'low'}
            decoding="async"
            onError={() => setFailedImages((current) => new Set(current).add(src))}
          />
        );
      })}
    </span>
  );
}

function ProductCategoryCard({ brand, category, index, onSelectCategory, allProductsImages = [], itemLabel = 'product' }) {
  const isAllProducts = Boolean(category?.isAllProducts);
  const resolvedImage = isAllProducts ? '' : (category?.preview?.url || resolveCategoryImage(brand, category));
  const [failedImage, setFailedImage] = useState({ key: '', src: '' });
  const previewImage = resolvePreviewImageMeta(resolvedImage);
  const imageKey = `${brandToSlug(brand)}:${category.key || category.slug || category.name}:${previewImage.src}`;
  const cardImage = failedImage.key === imageKey && failedImage.src === previewImage.src ? '' : previewImage.src;
  const hasSlideshow = isAllProducts && allProductsImages.length > 0;
  const slideshowKey = `${brandToSlug(brand)}:${allProductsImages.join('|')}`;

  let media;
  if (hasSlideshow) {
    media = <AllProductsSlideshow key={slideshowKey} images={allProductsImages} />;
  } else if (cardImage) {
    media = (
      <img
        src={cardImage}
        srcSet={previewImage.srcSet}
        sizes={previewImage.sizes}
        alt=""
        className="dtb-selector-card__image"
        width={640}
        height={427}
        loading={index < 4 ? 'eager' : 'lazy'}
        fetchPriority={index < 4 ? 'high' : 'auto'}
        decoding="async"
        onError={() => setFailedImage({ key: imageKey, src: previewImage.src })}
      />
    );
  }

  return (
    <MediaSelectorCard
      title={category.name}
      meta={`${category.count} ${itemLabel}${category.count === 1 ? '' : 's'}`}
      media={media}
      className={isAllProducts ? 'dtb-selector-card--all-products' : ''}
      onClick={() => onSelectCategory(category)}
    />
  );
}

export default function ProductsCategorySelector({
  brand,
  brandLogo,
  categories,
  onSelectCategory,
  onBack,
  includeAllProducts = true,
  loadAllProductsPreview = true,
  itemLabel = 'product',
  logoOnlyHeader = false,
}) {
  const [previewState, setPreviewState] = useState({ brand: '', images: [], total: null });
  const [partsPreview, setPartsPreview] = useState({ brand: '', image: '' });
  const normalizedCategories = Array.isArray(categories) ? categories : [];
  const categoryCountFallback = normalizedCategories.reduce((sum, category) => (
    sum + Number(category?.count || category?.productCount || 0)
  ), 0);
  const hasAuthoritativePreview = previewState.brand === brand;
  const allProductsImages = hasAuthoritativePreview ? previewState.images : [];
  const allProductsCount = hasAuthoritativePreview && Number.isFinite(previewState.total)
    ? previewState.total
    : categoryCountFallback;

  useEffect(() => {
    if (!brand || !includeAllProducts || !loadAllProductsPreview) return undefined;

    let cancelled = false;
    const loadPreviews = async () => {
      try {
        const response = await fetchCatalogProducts({
          brands: [brand],
          page: 1,
          perPage: ALL_PRODUCTS_PREVIEW_LIMIT,
          sort: 'popular',
        });
        if (cancelled) return;
        const images = Array.from(new Set(
          (Array.isArray(response?.items) ? response.items : [])
            .map(primaryProductImage)
            .map(normalizePreviewImageUrl)
            .filter((src) => src && !src.endsWith('.svg')),
        )).slice(0, ALL_PRODUCTS_PREVIEW_LIMIT);
        const total = Number(response?.pagination?.total);
        setPreviewState({
          brand,
          images,
          total: Number.isFinite(total) ? total : null,
        });
      } catch {
        if (!cancelled) setPreviewState({ brand, images: [], total: null });
      }
    };

    loadPreviews();
    return () => { cancelled = true; };
  }, [brand, includeAllProducts, loadAllProductsPreview]);

  // A brand's Parts category has no curated thumbnail mapping. Resolve a
  // real, published part image from the existing catalog API instead of
  // showing the missing-image placeholder on an otherwise populated card.
  const hasPartsCategory = normalizedCategories.some((category) =>
    ['parts', 'replacement_parts', 'replacement-parts'].includes(
      String(category?.key || category?.slug || '').toLowerCase()
    ) && Number(category?.count || category?.productCount || 0) > 0
  );

  useEffect(() => {
    if (!brand || !hasPartsCategory) return undefined;
    let cancelled = false;
    fetchCatalogProducts({ brands: [brand], isParts: 1, page: 1, perPage: 8 })
      .then((response) => {
        if (cancelled) return;
        const image = (Array.isArray(response?.items) ? response.items : [])
          .map(primaryProductImage)
          .find(Boolean) || '';
        setPartsPreview({ brand, image });
      })
      .catch(() => {
        if (!cancelled) setPartsPreview({ brand, image: '' });
      });
    return () => { cancelled = true; };
  }, [brand, hasPartsCategory]);

  const allProductsCard = {
    ...ALL_PRODUCTS_CATEGORY,
    count: allProductsCount,
    image: '',
  };
  const displayCategories = includeAllProducts
    ? [
      allProductsCard,
      ...normalizedCategories.filter((category) => !['all-products', 'all_products'].includes(String(category?.slug || category?.key || '').toLowerCase())),
    ]
    : normalizedCategories;

  return (
    <div className={`product-selector${logoOnlyHeader ? ' product-selector--brand-products' : ''}`}>
      <div className="product-selector-header">
        <BackButton onClick={onBack} className="dtb-product-nav-back" label="Back to brands" iconOnly />
        <div className="product-selector-header-content">
          {brandLogo && (
            <img
              src={brandLogo}
              alt={`${brand} logo`}
              className="product-brand-header-logo"
            />
          )}
          <div className={logoOnlyHeader && brandLogo ? 'sr-only' : 'product-selector-header-copy'}>
            <h1>Shop {brand} by category</h1>
            {!logoOnlyHeader && <p>Choose a category to browse this brand’s products.</p>}
          </div>
        </div>
      </div>

      <SelectorGrid variant="categories">
        {displayCategories.map((category, index) => (
          <ProductCategoryCard
            key={category.key || category.slug || category.name}
            brand={brand}
            category={['parts', 'replacement_parts', 'replacement-parts'].includes(String(category.key || category.slug || '').toLowerCase()) && partsPreview.brand === brand && partsPreview.image
              ? { ...category, preview: { url: partsPreview.image } }
              : category}
            index={index}
            onSelectCategory={onSelectCategory}
            allProductsImages={category.isAllProducts ? allProductsImages : []}
            itemLabel={itemLabel}
          />
        ))}
      </SelectorGrid>
    </div>
  );
}
