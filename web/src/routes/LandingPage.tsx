import { Link } from 'react-router-dom';
import { Avatar, Dot, Tag, Wordmark } from '@/components/ui';

export function LandingPage() {
  return (
    <>
      <header className="top-nav">
        <div className="container top-nav-inner">
          <Wordmark />
          <nav className="flex items-center gap-12">
            <a href="#features" className="text-sm muted">
              Features
            </a>
            <a href="#how" className="text-sm muted">
              How it works
            </a>
            <Link to="/login" className="btn btn-secondary btn-sm">
              Sign in
            </Link>
          </nav>
        </div>
      </header>

      <main>
        <section className="container hero">
          <span className="hero-eyebrow">
            <Dot variant="success" />
            Now syncing 100+ brands
          </span>
          <h1>One dashboard for every brand you run.</h1>
          <p className="lede">
            Helm pulls revenue from Shopify, spend from Meta, Google, and TikTok, and shows you
            blended ROAS across every store you manage — in the currency you choose, on the day you
            choose.
          </p>
          <div className="hero-cta">
            <Link to="/login" className="btn btn-primary btn-lg">
              Sign in to Helm
            </Link>
            <a href="#features" className="btn btn-ghost btn-lg">
              See what it does →
            </a>
          </div>
        </section>

        <section className="container" id="features">
          <div className="feature-grid">
            <div className="feature">
              <div className="feature-label">Unified revenue</div>
              <h3>Shopify, net of refunds.</h3>
              <p>
                Gross and net revenue per brand, recomputed daily with a rolling 7-day backfill so
                late refunds restate the right day.
              </p>
            </div>
            <div className="feature">
              <div className="feature-label">Ad spend</div>
              <h3>Meta, Google, TikTok.</h3>
              <p>
                One manager-level token per platform covers every brand. New stores connect through
                an in-app picker, not a developer ticket.
              </p>
            </div>
            <div className="feature">
              <div className="feature-label">Trust signals</div>
              <h3>No silent zeroes.</h3>
              <p>
                Failed syncs render amber, never as €0. Currency and timezone are stored per brand,
                never inferred at read time.
              </p>
            </div>
          </div>
        </section>

        <section className="container" id="how" style={{ marginTop: 96 }}>
          <div style={{ maxWidth: 580 }}>
            <h2>Built like a control tower, not a slideshow.</h2>
            <p className="lede mt-16">
              Helm is an internal tool for one agency. No SaaS marketing, no upsell. It loads in
              under a second across a hundred stores, and every number has a date, a timezone, and
              an FX rate behind it.
            </p>
          </div>
        </section>
      </main>

      <footer className="footer">
        <div className="container footer-inner">
          <div>© 2026 Nova Solution</div>
          <div className="flex gap-12">
            <span>Helm v1.0</span>
            <span>·</span>
            <Link to="/sitemap" className="muted">
              Site map
            </Link>
            <span>·</span>
            <Link to="/login" className="muted">
              Sign in
            </Link>
          </div>
        </div>
      </footer>
    </>
  );
}
