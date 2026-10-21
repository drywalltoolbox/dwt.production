import type { ClaimStrength } from '../types';

export const CLAIM_POLICY: Record<ClaimStrength, string> = {
  factual: 'Use when directly supported by authoritative product evidence.',
  interpretive: 'Allowed only when it explains the ordinary function of a documented mechanism without adding an unverified outcome.',
  performance: 'Requires explicit evidence connecting the product or mechanism to the claimed performance result.',
  comparative: 'Requires a defined comparator and authoritative support for the comparison.',
  quantitative: 'Requires exact sourced measurements, percentages, rates, counts, or test conditions.',
  superlative: 'Do not use fastest, best, strongest, lightest, most durable, superior, or equivalent language without explicit authoritative substantiation.',
  warranty_certification: 'Use only exact current warranty, certification, compliance, or safety language from authoritative evidence.'
};
