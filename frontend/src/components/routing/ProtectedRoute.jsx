/**
 * frontend/src/components/routing/ProtectedRoute.jsx
 *
 * Route guard for pages that require authentication.
 *
 * Behaviour:
 *   - While the session check is still in progress (isLoading === true):
 *       Renders no placeholder and does not redirect until session validation completes.
 *   - Session check complete, user is NOT authenticated:
 *       Redirects to /login, preserving the originally requested path in
 *       router location state ({ from: location }) so Login.jsx can return
 *       the user to their destination after a successful sign-in.
 *   - Session check complete, user IS authenticated:
 *       Renders children normally.
 *
 * Usage:
 *   <Route
 *     path="/dashboard"
 *     element={
 *       <ProtectedRoute>
 *         <Dashboard />
 *       </ProtectedRoute>
 *     }
 *   />
 */

import { Navigate, useLocation } from 'react-router-dom';
import { useAuthContext } from '../../auth/AuthContext.js';

export default function ProtectedRoute( { children } ) {
  const { isAuthenticated, isLoading } = useAuthContext();
  const location                       = useLocation();

  // Still waiting for the /validate round-trip — don't redirect or render a
  // placeholder while session ownership is being established.
  if ( isLoading ) {
    return null;
  }

  // Session confirmed invalid → send to login, remember where we came from.
  if ( ! isAuthenticated ) {
    return <Navigate to="/login" state={ { from: location } } replace />;
  }

  return children;
}
