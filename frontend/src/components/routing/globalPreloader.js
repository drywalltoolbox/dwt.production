let activeRouteLoads = 0;

function syncRouteLoadingAttribute() {
  if (typeof document === 'undefined') return;

  const preloader = document.getElementById('dtb-global-preloader');
  if (activeRouteLoads > 0) {
    document.documentElement.setAttribute('data-dtb-global-preloader', 'true');
    preloader?.setAttribute('aria-hidden', 'false');
  } else {
    document.documentElement.removeAttribute('data-dtb-global-preloader');
    preloader?.setAttribute('aria-hidden', 'true');
  }
}

export function beginGlobalPreloader() {
  activeRouteLoads += 1;
  syncRouteLoadingAttribute();

  let released = false;
  return () => {
    if (released) return;
    released = true;
    activeRouteLoads = Math.max(0, activeRouteLoads - 1);
    syncRouteLoadingAttribute();
  };
}
