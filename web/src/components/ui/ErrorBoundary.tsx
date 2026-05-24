import { Component, type ErrorInfo, type ReactNode } from 'react';

interface State { error: Error | null; }
interface Props { fallback?: (error: Error, reset: () => void) => ReactNode; children: ReactNode; }

/**
 * Localized error boundary. Wraps a single tab / section so a runtime
 * error in one component doesn't blank out the rest of the page.
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    // eslint-disable-next-line no-console
    console.error('Helm ErrorBoundary:', error, info);
  }

  reset = () => this.setState({ error: null });

  render() {
    if (this.state.error) {
      if (this.props.fallback) return this.props.fallback(this.state.error, this.reset);
      return (
        <div className="banner banner-warning" style={{ marginTop: 16 }}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" width="16" height="16">
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="8" x2="12" y2="12" />
            <line x1="12" y1="16" x2="12.01" y2="16" />
          </svg>
          <span>
            Something went wrong rendering this section: <span className="mono">{this.state.error.message}</span>.
            <button
              onClick={this.reset}
              style={{
                marginLeft: 8,
                background: 'transparent',
                border: 0,
                color: 'inherit',
                textDecoration: 'underline',
                cursor: 'pointer',
              }}
            >
              Retry
            </button>
          </span>
        </div>
      );
    }
    return this.props.children;
  }
}
