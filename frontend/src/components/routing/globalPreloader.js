let activeRouteLoads = 0;

function syncRouteLoadingAttribute() {
  if (typeof document === 'undefined') return;

  if (activeRouteLoads > 0) {
    document.documentElement.setAttribute('data-dtb-global-preloader', 'true');
  } else {
    document.documentElement.removeAttribute('data-dtb-global-preloader');
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
