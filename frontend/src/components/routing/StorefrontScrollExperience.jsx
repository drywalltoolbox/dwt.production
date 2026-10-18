import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { ArrowUp } from 'lucide-react';
import '../../styles/storefront-scroll-experience.css';

const EXCLUDED = /^\/(checkout|schematics)(?:\/|$)/;
function allowed(pathname, search) {
  if (EXCLUDED.test(pathname)) return pathname !== '/schematics' || !new URLSearchParams(search).has('schematic');
  return true;
}
/** Single shared native-scroll enhancement. Never intercept wheel/touch events. */
export default function StorefrontScrollExperience() {
  const { pathname, search } = useLocation();
  const [state, setState] = useState({ progress: 0, showTop: false });
  const enabled = allowed(pathname, search);
  useEffect(() => {
    if (!enabled) return undefined;
    let frame = 0;
    const update = () => {
      frame = 0;
      const root = document.documentElement;
      const total = Math.max(0, root.scrollHeight - window.innerHeight);
      const top = Math.max(0, window.scrollY);
      const progress = total > 0 ? Math.min(1, top / total) : 0;
      const showTop = total > window.innerHeight * 1.25 && top > Math.max(700, window.innerHeight);
      setState(previous => previous.progress === progress && previous.showTop === showTop ? previous : { progress, showTop });
    };
    const schedule = () => { if (!frame) frame = window.requestAnimationFrame(update); };
    schedule();
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule, { passive: true });
    return () => {
      window.cancelAnimationFrame(frame);
      window.removeEventListener('scroll', schedule);
      window.removeEventListener('resize', schedule);
    };
  }, [pathname, search, enabled]);
  if (!enabled) return null;
  return (
    <>
      <div className="dtb-scroll-progress" aria-hidden="true"><div style={{ transform: `scaleX(${state.progress})` }} /></div>
      {state.showTop && <button type="button" className="dtb-scroll-to-top" aria-label="Back to top" onClick={() => {
        window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
      }}><ArrowUp size={18} aria-hidden="true" /></button>}
    </>
  );
}
