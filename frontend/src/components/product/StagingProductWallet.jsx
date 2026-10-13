import { useEffect, useMemo, useRef, useState } from 'react';

/**
 * Staging-only native WooPayments proof. Intentionally does not perform cart
 * mutations or make payment requests; WooPayments owns all provider interaction.
 * Remains hidden unless the authenticated native product iframe reports that
 * an actual provider-owned Express Checkout Element mounted.
 */
export default function StagingProductWallet({ productId, variationId, quantity = 1, selectedAttributes = {} }) {
  const frameRef = useRef(null);
  const [visible, setVisible] = useState(false);
  const staging = typeof window !== 'undefined' && window.location.pathname.startsWith('/staging/products/');
  const preview = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('dtbWalletPreview') === '1';
  const id = Number(productId);
  const selectedId = Number(variationId);
  const qty = Math.max(1, Math.min(99, Number(quantity) || 1));
  const attrs = useMemo(() => {
    if (!selectedAttributes || typeof selectedAttributes !== 'object') return '';
    const params = new URLSearchParams();
    Object.entries(selectedAttributes).forEach(([key, value]) => {
      if (!value || typeof value !== 'string') return;
      // WooCommerce variation fields originate with attribute_ and stay in the
      // native product context. Do not accept arbitrary client-chosen parameters.
      const name = key.startsWith('attribute_') ? key : `attribute_${key}`;
      if (/^attribute_(?:pa_)?[a-z0-9_-]+$/i.test(name)) params.set(name, value);
    });
    return params.toString();
  }, [selectedAttributes]);
  const allowed = staging && preview && Number.isSafeInteger(id) && id > 0;
  const source = allowed ? `/staging/__native-wallet/${id}/?dtb_wallet_preview=1${selectedId > 0 ? `&variation_id=${selectedId}` : ''}${attrs ? `&${attrs}` : ''}` : '';
  useEffect(() => {
    setVisible(false);
  }, [source]);
  useEffect(() => {
    if (!allowed) return undefined;
    const onMessage = (event) => {
      if (event.origin !== window.location.origin || event.source !== frameRef.current?.contentWindow) return;
      const message = event.data;
      if (message?.type === 'dtb-native-wallet-mounted' && message.productId === id) {
        setVisible(true);
      }
      if (message?.type === 'dtb-native-wallet-unavailable') setVisible(false);
    };
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, [allowed, id]);
  useEffect(() => {
    if (!allowed || !source) return;
    frameRef.current?.contentWindow?.postMessage(
      { type: 'dtb-native-wallet-quantity', productId: id, quantity: qty },
      window.location.origin
    );
  }, [allowed, id, qty, source, visible]);

  if (!allowed) return null;
  return (
    <section className="dtb-native-wallet-preview" aria-label="Express checkout preview" hidden={!visible}>
      <iframe
        key={source}
        ref={frameRef}
        src={source}
        title="WooPayments secure express checkout"
        loading="eager"
        allow="payment"
        scrolling="no"
        className="dtb-native-wallet-preview__frame"
        onLoad={() => {
          frameRef.current?.contentWindow?.postMessage(
            { type: 'dtb-native-wallet-quantity', productId: id, quantity: qty },
            window.location.origin
          );
        }}
      />
    </section>
  );
}
