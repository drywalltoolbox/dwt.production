/**
 * frontend/src/components/repairs/RepairQuoteReview.jsx
 *
 * Shown when a repair is in the `quoted` state.
 * Allows the customer to accept or decline the quote.
 *
 * Props:
 *   repairId    number|string
 *   token       string           Public repair token
 *   onAccepted  () => void       Called after successful accept
 *   onDeclined  () => void       Called after successful decline
 */

import { useState } from 'react';
import { m as motion } from 'framer-motion';
import { Handshake, ClipboardList, FileText, Check, X } from 'lucide-react';
import { acceptRepairQuote, declineRepairQuote } from '../../api/repairs.js';
import { dtbTransition } from '../../motion/dtbMotion.js';

export default function RepairQuoteReview( { repairId, token, onAccepted, onDeclined } ) {
  const [ acting,    setActing    ] = useState( null ); // 'accept' | 'decline' | null
  const [ error,     setError     ] = useState( null );
  const [ confirmed, setConfirmed ] = useState( null ); // 'accepted' | 'declined'

  const handleAccept = async () => {
    setActing( 'accept' );
    setError( null );
    try {
      await acceptRepairQuote( repairId, token );
      setConfirmed( 'accepted' );
      if ( onAccepted ) onAccepted();
    } catch ( err ) {
      setError( err.message || 'Could not accept the quote. Please try again.' );
    } finally {
      setActing( null );
    }
  };

  const handleDecline = async () => {
    setActing( 'decline' );
    setError( null );
    try {
      await declineRepairQuote( repairId, token );
      setConfirmed( 'declined' );
      if ( onDeclined ) onDeclined();
    } catch ( err ) {
      setError( err.message || 'Could not decline the quote. Please try again.' );
    } finally {
      setActing( null );
    }
  };

  if ( confirmed === 'accepted' ) {
    return (
      <motion.div
        initial={{ opacity: 0, scale: 0.96 }}
        animate={{ opacity: 1, scale: 1 }}
        transition={dtbTransition.standard}
        className="bg-green-50 border border-green-200 rounded-2xl p-6 text-center"
      >
        <motion.div
          initial={{ scale: 0.5, opacity: 0 }}
          animate={{ scale: 1, opacity: 1 }}
          transition={{ ...dtbTransition.emphasized, delay: 0.1 }}
          className="w-12 h-12 rounded-xl bg-green-100 flex items-center justify-center mx-auto mb-3"
        >
          <Handshake size={ 24 } className="text-green-600" strokeWidth={ 1.75 } />
        </motion.div>
        <h3 className="text-base font-semibold text-green-800 mb-1">Quote Accepted</h3>
        <p className="text-sm text-green-700">
          We'll begin work on your repair shortly. You'll receive an update when work starts.
        </p>
      </motion.div>
    );
  }

  if ( confirmed === 'declined' ) {
    return (
      <motion.div
        initial={{ opacity: 0, scale: 0.96 }}
        animate={{ opacity: 1, scale: 1 }}
        transition={dtbTransition.standard}
        className="bg-neutral-50 border border-neutral-200 rounded-2xl p-6 text-center"
      >
        <motion.div
          initial={{ scale: 0.5, opacity: 0 }}
          animate={{ scale: 1, opacity: 1 }}
          transition={{ ...dtbTransition.emphasized, delay: 0.1 }}
          className="w-12 h-12 rounded-xl bg-neutral-100 flex items-center justify-center mx-auto mb-3"
        >
          <ClipboardList size={ 24 } className="text-neutral-500" strokeWidth={ 1.75 } />
        </motion.div>
        <h3 className="text-base font-semibold text-neutral-800 mb-1">Quote Declined</h3>
        <p className="text-sm text-neutral-600">
          Your repair request has been updated. If you change your mind or have questions,
          please contact us directly.
        </p>
      </motion.div>
    );
  }

  return (
    <motion.div
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={dtbTransition.standard}
      className="bg-yellow-50 border border-yellow-200 rounded-2xl p-6 space-y-4"
    >
      <div className="flex items-center gap-3">
        <div className="w-10 h-10 rounded-xl bg-yellow-100 flex items-center justify-center">
          <FileText size={ 20 } className="text-yellow-600" strokeWidth={ 1.75 } />
        </div>
        <div>
          <h3 className="text-base font-semibold text-yellow-900">Quote Sent</h3>
          <p className="text-xs text-yellow-700">Action required — please review and respond</p>
        </div>
      </div>

      <p className="text-sm text-yellow-800">
        We've sent a repair quote to your email address. Please review the details there,
        then use the buttons below to accept or decline.
      </p>

      <p className="text-sm text-yellow-700">
        Questions? Contact us at{' '}
        <a
          href="mailto:info@drywalltoolbox.com"
          className="font-medium underline hover:text-yellow-900"
        >
          info@drywalltoolbox.com
        </a>
        {' '}and reference your repair ID.
      </p>

      { error && (
        <motion.div
          initial={{ opacity: 0, height: 0 }}
          animate={{ opacity: 1, height: 'auto' }}
          className="overflow-hidden"
          role="alert"
        >
          <div className="p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-xs">
            { error }
          </div>
        </motion.div>
      ) }

      <div className="flex gap-3 pt-1">
        <button
          type="button"
          onClick={ handleAccept }
          disabled={ acting !== null }
          className="flex-1 px-4 py-2.5 bg-green-600 hover:bg-green-700 active:scale-[0.97] disabled:bg-green-400 text-white text-sm font-semibold rounded-xl transition-all"
        >
        { acting === 'accept' ? 'Accepting…' : <><Check size={ 14 } className="inline mr-1" />Accept Quote</> }
        </button>
        <button
          type="button"
          onClick={ handleDecline }
          disabled={ acting !== null }
          className="flex-1 px-4 py-2.5 border border-red-300 hover:bg-red-50 disabled:opacity-50 text-red-700 text-sm font-semibold rounded-xl transition-colors"
        >
          { acting === 'decline' ? 'Declining…' : <><X size={ 14 } className="inline mr-1" />Decline Quote</> }
        </button>
      </div>
    </motion.div>
  );
}
