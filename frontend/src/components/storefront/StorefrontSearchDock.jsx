import { Search } from 'lucide-react';
import { useId } from 'react';
import '../../styles/storefront-mobile-search-dock.css';

export default function StorefrontSearchDock({
  value,
  onChange,
  onFocus,
  onKeyDown,
  inputRef,
  placeholder = 'Search products, brands, SKU...',
  active = false,
  endAdornment = null,
}) {
  const inputId = useId();

  return (
    <label className={`storefront-search-dock${active ? ' is-active' : ''}`} htmlFor={inputId}>
      <Search size={16} aria-hidden="true" />
      <input
        id={inputId}
        ref={inputRef}
        type="search"
        value={value}
        onChange={onChange}
        onFocus={onFocus}
        onKeyDown={onKeyDown}
        placeholder={placeholder}
        aria-label="Search for products"
        aria-autocomplete="list"
        aria-controls={active ? 'storefront-search-results' : undefined}
        autoComplete="off"
        autoCorrect="off"
        autoCapitalize="none"
        spellCheck={false}
        enterKeyHint="search"
      />
      {endAdornment ? <span className="storefront-search-dock__action">{endAdornment}</span> : null}
    </label>
  );
}
