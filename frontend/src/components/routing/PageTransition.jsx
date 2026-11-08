/**
 * Keeps the route content wrapper stable while allowing a brief entrance motion
 * after pathname changes. Pending route modules render no fallback surface.
 */
export default function PageTransition({ children, animate = true }) {
  return (
    <div className="dtb-page-transition">
      <div className="dtb-page-transition__content" data-route-enter={animate ? 'true' : undefined}>
        {children}
      </div>
    </div>
  );
}
