import SmartBackButton from '../components/navigation/SmartBackButton.jsx';
import { useEffect, useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { getProductById } from '../services/catalog';
import { resolveProductBySku } from '../api/products.js';
import { useCart } from '../context/CartContext';
import { useWorkflowTransition } from '../context/WorkflowTransitionContext.jsx';
import ProductDetail from '../components/product/ProductDetail';
import Toast from '../components/ui/Toast';
import LoadingSpinner from '../components/shared/LoadingSpinner';
import SEOHead from '../components/shared/SEOHead';
import { buildProductSchema, buildBreadcrumbSchema, stripHtml } from '../utils/schema';

export default function Product() {
  const { slug, partNumber } = useParams();
  const navigate = useNavigate();
  const { addToCart } = useCart();
  const { runWorkflow } = useWorkflowTransition();
  const [product, setProduct] = useState(null);
  const [loading, setLoading] = useState(true);
  const [toast, setToast] = useState(null);

  const showToast = (message, type = 'cart') => {
    setToast({ message, type });
  };

  const handleAddToCart = async (product, quantity = 1) => {
    try {
      await runWorkflow(
        {
          label: 'Adding to cart…',
          sublabel: product?.name || 'Updating your cart securely.',
          blocking: false,
        },
        () => addToCart(product, quantity),
      );
    } catch (err) {
      showToast(err?.message || 'Could not add item to cart. Please try again.', 'error');
      throw err;
    }
  };

  useEffect(() => {
    let mounted = true;
    // Support both /products/:slug (current) and /product/:partNumber (legacy)
    const key = slug || partNumber;

    async function loadProduct() {
      try {
        // catalog service resolves by numeric ID, slug, SKU, or part_number
        // using the live WooCommerce REST API.
        const found = await getProductById(key);
        if (!mounted) return;

        if (found) {
          setProduct(found);
        } else {
          // Not found as a parent product — check if this is a variation SKU.
          // If so, redirect to the canonical /products/{slug}?variant={id} URL.
          const resolved = await resolveProductBySku(key);
          if (!mounted) return;

          if (resolved?.type === 'variation' && resolved.parentSlug) {
            navigate(`/products/${resolved.parentSlug}?variant=${resolved.id}`, { replace: true });
            return; // navigation is taking over, skip setLoading
          }

          setProduct(null);
        }
      } catch {
        if (mounted) setProduct(null);
      } finally {
        if (mounted) setLoading(false);
      }
    }

    loadProduct();

    return () => { mounted = false; };
  }, [slug, partNumber, navigate]);

  if (loading) {
    return (
      <>
        <SEOHead noindex title="Loading product…" />
        <LoadingSpinner fullPage size="lg" label="Loading product" />
      </>
    );
  }

  if (!product) {
    return (
      <div className="min-h-screen dtb-container dtb-container--wide py-16">
        <SEOHead noindex title="Product not found" />
        <div className="text-center">
          <h2 className="text-2xl font-bold mb-4">Product not found</h2>
          <p className="text-gray-600 mb-6">We couldn't find the product you're looking for.</p>
          <div className="flex items-center justify-center gap-3">
            <SmartBackButton fallbackTo="/products" label="Go back" />
            <Link to="/products" className="px-4 py-2 bg-primary-600 text-white rounded">Browse Products</Link>
          </div>
        </div>
      </div>
    );
  }

  // Read _dtb_seo_* overrides from WooCommerce product meta_data
  const metaMap = {};
  if (Array.isArray(product.meta_data)) {
    product.meta_data.forEach(({ key: metaKey, value: metaValue }) => { metaMap[metaKey] = metaValue; });
  }

  const seoTitle    = metaMap['_dtb_seo_title']       || product.name || '';
  const seoDesc     = metaMap['_dtb_seo_description'] || stripHtml(product.short_description || product.description || '');
  const seoCanon    = metaMap['_dtb_seo_canonical']   || '';
  const seoNoindex  = !!metaMap['_dtb_seo_noindex'];

  const heroImage = (Array.isArray(product.images) && product.images[0])
    ? (typeof product.images[0] === 'string' ? product.images[0] : product.images[0].src)
    : product.image || '';

  const productSchema   = buildProductSchema(product);
  // Canonical slug for the breadcrumb: prefer WooCommerce slug, then SKU, then
  // the URL param we resolved with — keeps breadcrumb links consistent with
  // the canonical URLs emitted by the schema builder and the route definition.
  const productSlug     = product.slug || product.sku || slug || partNumber || String(product.id);
  const breadcrumbSchema = buildBreadcrumbSchema([
    { label: 'Home',       path: '/'         },
    { label: 'Products',   path: '/products' },
    { label: product.name, path: `/products/${productSlug}` },
  ]);

  return (
    <div className="min-h-screen page-wrapper">
      <SEOHead
        title={seoTitle}
        description={seoDesc}
        canonical={seoCanon}
        noSuffix={!!metaMap['_dtb_seo_title']}
        noindex={seoNoindex}
        og={{ type: 'product', image: heroImage, imageAlt: product.name }}
        schema={[productSchema, breadcrumbSchema].filter(Boolean)}
        links={heroImage ? [{ rel: 'preload', href: heroImage, as: 'image' }] : []}
      />
      <div className="dtb-container dtb-container--wide py-12">
        <ProductDetail
          product={product} 
          onAddToCart={handleAddToCart}
          onClose={() => navigate(-1)}
        />
      </div>
      
      {/* Toast Notification */}
      {toast && (
        <Toast
          message={toast.message}
          type={toast.type}
          onClose={() => setToast(null)}
        />
      )}
    </div>
  );
}

