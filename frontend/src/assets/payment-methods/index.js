import americanExpressLogo from './american-express.svg';
import mastercardLogo from './mastercard.svg';
import visaLogo from './visa.svg';

/** Card network marks shown as accepted card brands; express method eligibility
 * is determined by WooPayments at the native checkout for each customer. */
export const PAYMENT_CARD_NETWORK_ASSETS = Object.freeze({
  americanExpress: Object.freeze({ id: 'american-express', label: 'American Express', src: americanExpressLogo }),
  mastercard: Object.freeze({ id: 'mastercard', label: 'Mastercard', src: mastercardLogo }),
  visa: Object.freeze({ id: 'visa', label: 'Visa', src: visaLogo }),
});
