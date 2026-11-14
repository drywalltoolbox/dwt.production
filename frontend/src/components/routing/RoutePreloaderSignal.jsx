import { useLayoutEffect } from 'react';
import { beginGlobalPreloader } from './globalPreloader.js';

/** Signals the shared document preloader while a lazy route module is pending. */
export default function RoutePreloaderSignal() {
  useLayoutEffect(() => beginGlobalPreloader(), []);
  return null;
}
