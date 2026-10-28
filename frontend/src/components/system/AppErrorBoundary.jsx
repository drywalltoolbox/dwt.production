import React from 'react';
import CustomerErrorPage from '../errors/CustomerErrorPage.jsx';

/**
 * frontend/src/components/system/AppErrorBoundary.jsx
 *
 * Production ecommerce global error boundary.
 *
 * Captures catastrophic render/runtime failures and prevents total storefront
 * unmounts during:
 * - checkout
 * - cart mutations
 * - product rendering
 * - account workflows
 */

export default class AppErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = {
      hasError: false,
      error: null,
      errorInfo: null,
    };
  }

  static getDerivedStateFromError(error) {
    return {
      hasError: true,
      error,
    };
  }

  componentDidCatch(error, errorInfo) {
    this.setState({ errorInfo });

    // Centralized logging hook.
    // Future integrations:
    // - Sentry
    // - Datadog
    // - NewRelic
    // - internal telemetry endpoint
    if (typeof window !== 'undefined') {
      console.error('[DTB Frontend Error Boundary]', {
        error: {
          name: error?.name || 'Error',
          message: String(error?.message || error || 'Unknown render error'),
          stack: error?.stack || '',
        },
        componentStack: errorInfo?.componentStack || '',
        path: window.location.pathname,
      });
    }
  }

  render() {
    if (!this.state.hasError) {
      return this.props.children;
    }

    return <CustomerErrorPage
      code={500}
      showDebug={false}
    />;
  }
}
