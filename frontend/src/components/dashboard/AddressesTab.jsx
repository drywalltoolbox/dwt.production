/**
 * frontend/src/components/dashboard/AddressesTab.jsx
 *
 * Dashboard Addresses tab — billing and shipping address cards.
 */

import { m as Motion } from 'framer-motion';
import { Home, Truck, MapPin, Plus, Edit3 } from 'lucide-react';
import { dashboardRevealVariants } from '../../motion/dtbMotion.js';

const CARD = {
  background:   'white',
  border:       '1px solid rgba(15,23,42,0.08)',
  borderRadius: '12px',
  boxShadow:    '0 2px 12px rgba(15,23,42,0.05)',
  overflow:     'hidden',
};

const TYPE_CFG = {
  billing:  { Icon: Home,  color: '#2255ee', bg: '#eff6ff', label: 'Billing Address'  },
  shipping: { Icon: Truck, color: '#16a34a', bg: '#f0fdf4', label: 'Shipping Address' },
};

function AddressCard( { type, address, delay } ) {
  const { Icon, color, bg, label } = TYPE_CFG[ type ];
  const isEmpty = ! address?.first_name && ! address?.address_1;

  return (
    <Motion.div custom={ delay } variants={ dashboardRevealVariants } initial="hidden" animate="visible" style={ CARD }>
      <div style={ { display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '16px 18px', borderBottom: '1px solid rgba(15,23,42,0.07)' } }>
        <div style={ { display: 'flex', alignItems: 'center', gap: '10px' } }>
          <div style={ { width: '34px', height: '34px', borderRadius: '8px', background: bg, display: 'flex', alignItems: 'center', justifyContent: 'center' } }>
            <Icon size={ 15 } style={ { color } } />
          </div>
          <span style={ { fontSize: '0.9rem', fontWeight: 700, color: '#0f172a' } }>{ label }</span>
        </div>
        { ! isEmpty && (
          <button
            type="button"
            style={ { display: 'inline-flex', alignItems: 'center', gap: '4px', padding: '5px 10px', borderRadius: '7px', border: '1px solid rgba(15,23,42,0.12)', background: 'transparent', fontSize: '0.73rem', fontWeight: 650, color: 'rgba(15,23,42,0.5)', cursor: 'pointer', transition: 'background 0.12s' } }
            onMouseEnter={ ( e ) => { e.currentTarget.style.background = '#f1f5f9'; } }
            onMouseLeave={ ( e ) => { e.currentTarget.style.background = 'transparent'; } }
          >
            <Edit3 size={ 11 } /> Edit
          </button>
        ) }
      </div>

      <div style={ { padding: '16px 18px' } }>
        { isEmpty ? (
          <div style={ { textAlign: 'center', padding: '16px 0' } }>
            <MapPin size={ 24 } style={ { color: 'rgba(15,23,42,0.18)', display: 'block', margin: '0 auto 10px' } } />
            <p style={ { margin: '0 0 12px', fontSize: '0.82rem', color: 'rgba(15,23,42,0.4)' } }>No { label.toLowerCase() } saved.</p>
            <button
              type="button"
              style={ { display: 'inline-flex', alignItems: 'center', gap: '5px', padding: '7px 14px', borderRadius: '8px', border: '1.5px dashed rgba(15,23,42,0.18)', background: 'transparent', fontSize: '0.78rem', fontWeight: 650, color: 'rgba(15,23,42,0.45)', cursor: 'pointer', transition: 'border-color 0.15s, color 0.15s' } }
              onMouseEnter={ ( e ) => { e.currentTarget.style.borderColor = color; e.currentTarget.style.color = color; } }
              onMouseLeave={ ( e ) => { e.currentTarget.style.borderColor = 'rgba(15,23,42,0.18)'; e.currentTarget.style.color = 'rgba(15,23,42,0.45)'; } }
            >
              <Plus size={ 12 } /> Add address
            </button>
          </div>
        ) : (
          <address style={ { fontStyle: 'normal', lineHeight: 1.7, fontSize: '0.86rem', color: '#334155' } }>
            { [ address.first_name, address.last_name ].filter( Boolean ).join( ' ' ) && (
              <p style={ { margin: '0 0 2px', fontWeight: 700, color: '#0f172a' } }>
                { [ address.first_name, address.last_name ].filter( Boolean ).join( ' ' ) }
              </p>
            ) }
            { address.company   && <p style={ { margin: '0 0 2px' } }>{ address.company }</p> }
            { address.address_1 && <p style={ { margin: '0 0 2px' } }>{ address.address_1 }</p> }
            { address.address_2 && <p style={ { margin: '0 0 2px' } }>{ address.address_2 }</p> }
            { ( address.city || address.state || address.postcode ) && (
              <p style={ { margin: '0 0 2px' } }>{ [ address.city, address.state, address.postcode ].filter( Boolean ).join( ', ' ) }</p>
            ) }
            { address.country && <p style={ { margin: 0 } }>{ address.country }</p> }
            { address.phone   && <p style={ { margin: '5px 0 0', fontSize: '0.78rem', color: 'rgba(15,23,42,0.45)' } }>{ address.phone }</p> }
          </address>
        ) }
      </div>
    </Motion.div>
  );
}

export default function AddressesTab( { user } ) {
  const billing  = user?.billing  || {};
  const shipping = user?.shipping || {};

  return (
    <div style={ { display: 'flex', flexDirection: 'column', gap: '12px' } }>
      <AddressCard type="billing"  address={ billing }  delay={ 0 } />
      <AddressCard type="shipping" address={ shipping } delay={ 0.06 } />

      {/* Coming-soon multi-address placeholder */}
      <Motion.div custom={ 0.12 } variants={ dashboardRevealVariants } initial="hidden" animate="visible"
        style={ { display: 'flex', alignItems: 'center', gap: '10px', padding: '14px 18px', background: '#f8fafc', border: '1.5px dashed rgba(15,23,42,0.1)', borderRadius: '10px' } }
      >
        <Plus size={ 16 } style={ { color: 'rgba(15,23,42,0.28)', flexShrink: 0 } } />
        <div>
          <p style={ { margin: '0 0 1px', fontSize: '0.84rem', fontWeight: 650, color: 'rgba(15,23,42,0.45)' } }>Multiple saved addresses</p>
          <p style={ { margin: 0, fontSize: '0.73rem', color: 'rgba(15,23,42,0.35)' } }>Support for saving multiple addresses is coming soon.</p>
        </div>
      </Motion.div>
    </div>
  );
}
