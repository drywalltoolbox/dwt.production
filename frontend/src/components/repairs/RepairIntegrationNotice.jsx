/**
 * frontend/src/components/repairs/RepairIntegrationNotice.jsx
 *
 * Customer-safe informational notice for processing states.
 * Never exposes raw integration errors or internal system details.
 *
 * Props:
 *   status           string    — current repair status key
 *   integrationState object    — optional; presence triggers additional display
 *                                (content is always sanitised — never raw errors)
 */

import { m as motion } from 'framer-motion';
import { dtbTransition } from '../../motion/dtbMotion.js';

// ─── Per-status customer messaging ───────────────────────────────────────────

const STATUS_MESSAGES = {
  reviewed: {
    title: 'Under Review',
    body:  "Our technicians are reviewing your repair request. We'll be in touch soon.",
    color: 'blue',
  },
  awaiting_customer: {
    title: 'Your Input Needed',
    body:  'We need a bit more information from you. Please check your email for details.',
    color: 'yellow',
  },
  approved: {
    title: 'Repair Approved',
    body:  'Your repair has been approved and is being scheduled.',
    color: 'green',
  },
  parts_allocated: {
    title: 'Parts Being Prepared',
    body:  'Parts allocation is being processed. Your repair will begin shortly.',
    color: 'blue',
  },
  in_progress: {
    title: 'Repair In Progress',
    body:  "Our technicians are actively working on your tool. We'll notify you when it's ready.",
    color: 'yellow',
  },
  ready_to_ship: {
    title: 'Ready to Ship',
    body:  'Your repaired tool is packaged and ready. Shipping details will be sent to your email.',
    color: 'green',
  },
};

const COLOR_CLASSES = {
  blue:   { wrapper: 'bg-blue-50 border-blue-100',   title: 'text-blue-800',   body: 'text-blue-700'   },
  green:  { wrapper: 'bg-green-50 border-green-100', title: 'text-green-800',  body: 'text-green-700'  },
  yellow: { wrapper: 'bg-yellow-50 border-yellow-100', title: 'text-yellow-800', body: 'text-yellow-700' },
  neutral:{ wrapper: 'bg-neutral-50 border-neutral-200', title: 'text-neutral-700', body: 'text-neutral-600' },
};

export default function RepairIntegrationNotice( { status, integrationState } ) {
  const msg = STATUS_MESSAGES[ status ];

  // Only render when there's something customer-relevant to show
  if ( ! msg && ! integrationState ) return null;

  const display = msg || {
    title: 'Processing',
    body:  "Your repair request is being processed. We'll update you as soon as there's news.",
    color: 'neutral',
  };

  const colors = COLOR_CLASSES[ display.color ] || COLOR_CLASSES.neutral;

  return (
    <motion.div
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0 }}
      transition={dtbTransition.standard}
      className={ `rounded-xl border px-4 py-3 ${ colors.wrapper }` }
      role="status"
      aria-live="polite"
    >
      <div className="border-l-2 border-current/35 pl-3" style={{ color: 'inherit' }}>
        <p className={ `text-sm font-semibold ${ colors.title }` }>{ display.title }</p>
        <p className={ `text-sm mt-0.5 ${ colors.body }` }>{ display.body }</p>
      </div>
    </motion.div>
  );
}
