/**
 * Canonical account history adapter.
 * Single backend read model; normalizes the payload for the full account page
 * and compact header account drawer. No second history orchestration.
 */
import { getAccountHistory } from './accountHistory.js';
import { normalizeOrders, normalizeRepairs, normalizeReturns, normalizeSupportTickets } from '../utils/accountActivity.js';

export async function getAccountWorkspaceHistory({ perPage = 20 } = {}) {
  const payload = await getAccountHistory({ perPage });
  return {
    summary: payload.summary || null,
    orders: normalizeOrders(payload),
    repairs: normalizeRepairs(payload),
    returns: normalizeReturns(payload),
    supportTickets: normalizeSupportTickets(payload),
  };
}
