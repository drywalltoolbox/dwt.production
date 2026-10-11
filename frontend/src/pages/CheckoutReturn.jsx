import { Link } from 'react-router-dom';
import { CheckCircle, Info, ShoppingBag } from 'lucide-react';

import SEOHead from '../components/shared/SEOHead.jsx';
import '../styles/order-pages.css';

export default function CheckoutReturn({ fallbackState = 'complete' }) {
	const failed = fallbackState === 'failed' || fallbackState === 'cancelled';
	const title = failed ? 'Checkout was not completed' : 'Checkout status';
	const description = failed
		? 'Return to checkout to try your payment again.'
		: 'Use your account dashboard or order email for final order details.';

	const StatusIcon = failed ? CheckCircle : Info;

	return (
		<div className="min-h-screen flex items-center justify-center py-16 page-wrapper" style={{ paddingInline: 'var(--dtb-viewport-gutter)' }}>
			<SEOHead noindex title={title} />
			<div className="w-full max-w-lg rounded-3xl border border-slate-200 bg-white p-7 text-center shadow-[0_18px_48px_rgba(15,23,42,0.10)] sm:p-10">
				<div className="mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-full bg-slate-50">
					<StatusIcon className="h-12 w-12 text-primary-600" strokeWidth={1.8} />
				</div>
				<p className="mb-2 text-[10px] font-black uppercase tracking-[0.18em] text-primary-600">Secure checkout</p>
				<h1 className="mb-3 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">{title}</h1>
				<p className="mx-auto mb-6 max-w-md text-sm leading-relaxed text-slate-600">{description}</p>

				<div className="flex flex-col gap-3 sm:flex-row sm:justify-center">
					{failed ? (
						<Link to="/checkout" reloadDocument className="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-primary-600 px-5 py-3 text-sm font-black text-white transition-colors hover:bg-primary-700">
							Return to Checkout
						</Link>
					) : (
						<Link to="/dashboard?tab=orders" className="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-primary-600 px-5 py-3 text-sm font-black text-white transition-colors hover:bg-primary-700">
							View Orders
						</Link>
					)}
					<Link to="/products" className="inline-flex min-h-12 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 transition-colors hover:bg-slate-50">
						<ShoppingBag size={14} /> Continue Shopping
					</Link>
				</div>
			</div>
		</div>
	);
}
