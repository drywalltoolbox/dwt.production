import { useEffect, useMemo, useState } from 'react';
import { Autocomplete } from '@base-ui/react/autocomplete';
import { matchSorter } from 'match-sorter';
import { Search, X } from 'lucide-react';
import { getProducts } from '../../services/catalog.js';
import { useCart } from '../../context/CartContext.jsx';
import AddToCartButton from '../ui/AddToCartButton.jsx';
import '../../styles/storefront-catalog-autocomplete.css';

const MAX_RESULTS = 6;

function categoryText(product) {
  return (Array.isArray(product?.categories) ? product.categories : [])
    .map((category) => category?.name || category?.slug || '')
    .filter(Boolean)
    .join(' ');
}

function fuzzyMatchProducts(products, query) {
  if (!query) return products;

  return matchSorter(products, query, {
    keys: [
      'name',
      'brand',
      'sku',
      'part_number',
      'upc',
      'slug',
      (item) => categoryText(item),
      (item) => item?.short_description || '',
      { key: 'name', threshold: matchSorter.rankings.CONTAINS },
      { key: 'sku', threshold: matchSorter.rankings.WORD_STARTS_WITH },
      { key: 'brand', threshold: matchSorter.rankings.WORD_STARTS_WITH },
    ],
  });
}

function highlightText(text, query) {
  const value = String(text || '');
  const trimmed = String(query || '').trim();
  if (!trimmed) return value;

  const limited = trimmed.slice(0, 100);
  const escaped = limited.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const regex = new RegExp(`(${escaped})`, 'gi');

  return value
    .split(regex)
    .map((part, index) => (part.toLocaleLowerCase() === limited.toLocaleLowerCase()
      ? <mark key={`${part}-${index}`}>{part}</mark>
      : part));
}

function productPrice(product) {
  if (typeof product?.price === 'number') return `$${product.price.toFixed(2)}`;
  return 'View product';
}

function productPurchaseState(product) {
  const variable = Boolean(product?.is_variable || product?.type === 'variable');
  const outOfStock = product?.stock_status === 'outofstock';

  return {
    canAdd: !variable && !outOfStock,
    disabled: outOfStock,
    label: outOfStock ? 'Sold out' : variable ? 'Options' : 'Add',
  };
}

function purchaseAriaLabel(product, purchase) {
  const name = product?.name || 'product';
  if (purchase.disabled) return `${name} is sold out`;
  if (!purchase.canAdd) return `Choose options for ${name}`;
  return `Add ${name} to cart`;
}

export default function StorefrontCatalogAutocomplete({
  inputRef,
  query,
  open,
  onOpenChange,
  onQueryChange,
  onFocus,
  onProductSelect,
  onViewAll,
}) {
  const { addToCart } = useCart();
  const [catalog, setCatalog] = useState([]);
  const [loading, setLoading] = useState(true);
  const [addingProductId, setAddingProductId] = useState(null);
  const fuzzyResults = useMemo(
    () => fuzzyMatchProducts(catalog, query.trim()),
    [catalog, query]
  );

  const handlePurchase = async (event, product) => {
    event.preventDefault();
    event.stopPropagation();

    const purchase = productPurchaseState(product);
    if (purchase.disabled) return;
    if (!purchase.canAdd) {
      onProductSelect(product);
      return;
    }

    const productId = String(product?.id || '');
    if (!productId || addingProductId) return;

    setAddingProductId(productId);
    try {
      await addToCart(product, 1);
    } catch {
      // CartContext owns and renders the actionable cart error state.
    } finally {
      setAddingProductId(null);
    }
  };

  useEffect(() => {
    let active = true;

    getProducts()
      .then((products) => {
        if (active) setCatalog(Array.isArray(products) ? products : []);
      })
      .catch((error) => {
        if (active) {
          console.error('Catalog autocomplete load error:', error);
          setCatalog([]);
        }
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, []);

  return (
    <Autocomplete.Root
      items={fuzzyResults}
      value={query}
      open={open}
      onOpenChange={(nextOpen) => onOpenChange(nextOpen)}
      onValueChange={(nextValue, details) => {
        if (details.reason !== 'item-press') onQueryChange(nextValue);
      }}
      itemToStringValue={(product) => product?.name || ''}
      filter={null}
      limit={MAX_RESULTS}
      keepHighlight
    >
      <Autocomplete.InputGroup className="dtb-desktop-search-pill dtb-catalog-autocomplete__input-group">
        <span className="dtb-desktop-search-icon-wrap" aria-hidden="true">
          <Search className="dtb-desktop-search-icon" />
        </span>
        <Autocomplete.Input
          ref={inputRef}
          type="search"
          placeholder="Search products, brands, or SKU..."
          className="dtb-desktop-search-input"
          aria-label="Search products"
          autoComplete="off"
          autoCorrect="off"
          autoCapitalize="none"
          spellCheck={false}
          onFocus={onFocus}
          onKeyDown={(event) => {
            if (event.key !== 'Enter' || !query.trim()) return;

            // The autocomplete auto-highlights the first suggestion. Keep Enter
            // reserved for the full catalog search so a broad query (for example,
            // a brand name) cannot silently open one arbitrary product.
            event.preventDefault();
            event.stopPropagation();
            onViewAll();
          }}
        />
        {query.trim() ? (
          <Autocomplete.Clear className="dtb-catalog-autocomplete__clear" aria-label="Clear product search">
            <X size={15} aria-hidden="true" />
          </Autocomplete.Clear>
        ) : null}
      </Autocomplete.InputGroup>

      <Autocomplete.Portal>
        <Autocomplete.Positioner className="dtb-catalog-autocomplete__positioner" sideOffset={6} align="end">
          <Autocomplete.Popup className="dtb-catalog-autocomplete__popup" aria-busy={loading || undefined}>
            <div className="dtb-catalog-autocomplete__header">
              <span>Catalog results</span>
              <small>Search by product, brand, or SKU</small>
            </div>

            <Autocomplete.Status className="dtb-catalog-autocomplete__status">
              {loading ? 'Loading catalog…' : null}
            </Autocomplete.Status>

            {!loading ? (
              <>
                <Autocomplete.Empty className="dtb-catalog-autocomplete__empty">
                  No products matched &quot;{query.trim()}&quot;. Try a broader term.
                </Autocomplete.Empty>
                <Autocomplete.List className="dtb-catalog-autocomplete__list">
                  {(product) => {
                    const purchase = productPurchaseState(product);
                    const adding = addingProductId === String(product?.id || '');

                    return (
                      <div
                        key={product.id || product.slug || product.sku || product.name}
                        className="dtb-catalog-autocomplete__result-card"
                      >
                        <Autocomplete.Item
                          value={product}
                          className="dtb-catalog-autocomplete__item"
                          onClick={() => onProductSelect(product)}
                        >
                          <span className="dtb-catalog-autocomplete__thumb">
                            {product?.image ? <img src={product.image} alt="" loading="lazy" /> : <Search size={17} aria-hidden="true" />}
                          </span>
                          <span className="dtb-catalog-autocomplete__copy">
                            <strong>{highlightText(product?.name, query)}</strong>
                            <small>
                              {product?.brand ? <span>{highlightText(product.brand, query)}</span> : null}
                              {product?.sku ? <span>SKU {highlightText(product.sku, query)}</span> : null}
                            </small>
                          </span>
                        </Autocomplete.Item>
                        <span className="dtb-catalog-autocomplete__commerce">
                          <span className="dtb-catalog-autocomplete__price">{productPrice(product)}</span>
                          <AddToCartButton
                            className="dtb-catalog-autocomplete__add"
                            size="compact"
                            label={purchase.label}
                            pendingLabel="Adding…"
                            state={adding ? 'adding' : 'idle'}
                            productId={product?.id}
                            cartAction={purchase.canAdd}
                            disabled={purchase.disabled || adding}
                            onPointerDown={(event) => {
                              event.preventDefault();
                              event.stopPropagation();
                            }}
                            onClick={(event) => handlePurchase(event, product)}
                            aria-label={purchaseAriaLabel(product, purchase)}
                          />
                        </span>
                      </div>
                    );
                  }}
                </Autocomplete.List>
              </>
            ) : null}

            <button type="button" className="dtb-catalog-autocomplete__view-all" onClick={onViewAll}>
              View all results for &quot;{query.trim()}&quot;
            </button>
          </Autocomplete.Popup>
        </Autocomplete.Positioner>
      </Autocomplete.Portal>
    </Autocomplete.Root>
  );
}
