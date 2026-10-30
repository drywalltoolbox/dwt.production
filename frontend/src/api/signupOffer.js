import { apiClient } from './client.js';

/** Fetch the public signup-campaign state without storing it in browser caches. */
export function getSignupOffer() {
  return apiClient('/wp-json/dtb/v1/marketing/signup-offer', {
    cache: 'no-store',
  });
}
