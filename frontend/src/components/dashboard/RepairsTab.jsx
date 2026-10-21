/**
 * frontend/src/components/dashboard/RepairsTab.jsx
 *
 * Dashboard Repairs tab — quick access + authenticated repair-service history.
 */

import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { m as Motion } from 'framer-motion';
import { Wrench, Loader, ArrowRight, Package } from 'lucide-react';
import { getCustomerRepairs, REPAIR_STATUS_LABELS } from '../../api/repairs.js';
import { dashboardRevealVariants } from '../../motion/dtbMotion.js';

const CARD = {
  background:   'white',
  border:       '1px solid rgba(15,23,42,0.08)',
  borderRadius: '12px',
  boxShadow:    '0 2px 12px rgba(15,23,42,0.05)',
};

function normalizeRepairsResponse( data ) {
  if ( Array.isArray( data ) ) return data;
  if ( Array.isArray( data?.repairs ) ) return data.repairs;
  if ( Array.isArray( data?.data?.repairs ) ) return data.data.repairs;
  return [];
}

async function fetchRepairs() {
  return normalizeRepairsResponse( await getCustomerRepairs( 1, 50 ) );
}

function formatRepairNumber( repair ) {
  const number = repair?.number || repair?.repair_id || repair?.id;
  return number ? `Repair #${ number }` : 'Repair request';
}

function formatStatus( repair ) {
  const status = String( repair?.status || 'submitted' );
  return repair?.label || REPAIR_STATUS_LABELS[ status ] || status.replace( /_/g, ' ' ).replace( /\b\w/g, ( char ) => char.toUpperCase() );
}

function formatDate( value ) {
  if ( ! value ) return '';
  try {
    return new Date( value ).toLocaleDateString( 'en-US', { month: 'short', day: 'numeric', year: 'numeric' } );
  } catch {
    return '';
  }
}

function repairStatusPath( repair ) {
  const repairId = repair?.repair_id || repair?.id || repair?.number;
  if ( ! repairId ) return '/dashboard?tab=repairs';
  const token = repair?.public_token || repair?.token;
  return token
    ? `/repairs/status/${ encodeURIComponent( repairId ) }?token=${ encodeURIComponent( token ) }`
    : `/repairs/status/${ encodeURIComponent( repairId ) }`;
}

export default function RepairsTab() {
  const [ repairs, setRepairs ] = useState( [] );
  const [ loading, setLoading ] = useState( true );
  const [ error, setError ] = useState( null );

  useEffect( () => {
    let cancelled = false;

    fetchRepairs()
      .then( ( items ) => {
        if ( cancelled ) return;
        setRepairs( items );
        setError( null );
      } )
      .catch( ( err ) => {
        if ( cancelled ) return;
        setError( err?.message || 'Unable to load repair requests.' );
      } )
      .finally( () => {
        if ( ! cancelled ) setLoading( false );
      } );

    return () => { cancelled = true; };
  }, [] );

  return (
    <div style={ { display: 'flex', flexDirection: 'column', gap: '14px' } }>
      <Motion.div custom={ 0.05 } variants={ dashboardRevealVariants } initial="hidden" animate="visible" style={ { ...CARD, padding: '18px 20px' } }>
        <div style={ { display: 'flex', flexWrap: 'wrap', gap: '10px', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px' } }>
          <div style={ { display: 'flex', alignItems: 'center', gap: '10px' } }>
            <div style={ { width: '34px', height: '34px', borderRadius: '8px', background: '#ecfeff', display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
              <Wrench size={ 16 } style={ { color: '#0284c7' } } />
            </div>
            <span style={ { fontSize: '0.92rem', fontWeight: 700, color: '#0f172a' } }>Repair Center</span>
          </div>
          <Link to="/repairs" style={ { display: 'inline-flex', alignItems: 'center', gap: '6px', textDecoration: 'none', fontSize: '0.78rem', fontWeight: 700, color: '#0284c7' } }>
            New repair request <ArrowRight size={ 12 } />
          </Link>
        </div>

        { loading && (
          <div style={ { display: 'flex', alignItems: 'center', gap: '8px', padding: '8px 0' } }>
            <Loader size={ 15 } className="animate-spin" style={ { color: '#0284c7' } } />
            <span style={ { fontSize: '0.82rem', color: 'rgba(15,23,42,0.45)' } }>Loading repair requests…</span>
          </div>
        ) }

        { ! loading && error && (
          <div style={ { fontSize: '0.82rem', color: '#dc2626', background: '#fef2f2', border: '1px solid #fecaca', borderRadius: '8px', padding: '10px 12px' } }>
            { error }
          </div>
        ) }

        { ! loading && ! error && repairs.length === 0 && (
          <div style={ { textAlign: 'center', padding: '20px 14px', borderRadius: '10px', background: '#f8fafc' } }>
            <Package size={ 24 } style={ { color: 'rgba(15,23,42,0.2)', display: 'block', margin: '0 auto 8px' } } />
            <p style={ { margin: '0 0 12px', fontSize: '0.82rem', color: 'rgba(15,23,42,0.45)' } }>
              No repair requests yet.
            </p>
            <Link to="/repairs" style={ { textDecoration: 'none', display: 'inline-flex', alignItems: 'center', gap: '6px', fontSize: '0.78rem', fontWeight: 700, color: '#0284c7', background: '#ecfeff', padding: '7px 12px', borderRadius: '7px' } }>
              <Wrench size={ 12 } /> Start a repair
            </Link>
          </div>
        ) }

        { ! loading && ! error && repairs.length > 0 && (
          <div style={ { display: 'flex', flexDirection: 'column', gap: '8px' } }>
            { repairs.slice( 0, 5 ).map( ( repair ) => (
              <Link key={ repair.repair_id || repair.id } to={ repairStatusPath( repair ) } style={ { textDecoration: 'none' } }>
                <div style={ { border: '1px solid rgba(15,23,42,0.08)', borderRadius: '9px', padding: '10px 12px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '10px' } }>
                  <div style={ { minWidth: 0 } }>
                    <p style={ { margin: 0, fontSize: '0.82rem', fontWeight: 700, color: '#0f172a' } }>{ formatRepairNumber( repair ) }</p>
                    <p style={ { margin: '2px 0 0', fontSize: '0.72rem', color: 'rgba(15,23,42,0.45)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' } }>
                      { repair.tool_label || 'Repair request' }{ repair.submitted_at ? ` • ${ formatDate( repair.submitted_at ) }` : '' }
                    </p>
                  </div>
                  <span style={ { fontSize: '0.72rem', fontWeight: 700, color: '#0284c7', background: '#ecfeff', borderRadius: '999px', padding: '3px 8px', textTransform: 'capitalize', whiteSpace: 'nowrap' } }>
                    { formatStatus( repair ) }
                  </span>
                </div>
              </Link>
            ) ) }
          </div>
        ) }
      </Motion.div>
    </div>
  );
}
