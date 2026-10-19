/**
 * PageTransition
 *
 * Gives committed route changes a short opacity entrance. The key is supplied
 * by AppRoutes and changes only with the pathname, so query-driven UI state
 * does not replay a page transition.
 */
export default function PageTransition({ children, animate = true }) {
  return (
    <div
      className="dtb-page-transition"
      data-route-enter={animate ? 'true' : undefined}
      style={{ width: '100%', minHeight: '100%', position: 'relative' }}
    >
      {children}
    </div>
  );
}
