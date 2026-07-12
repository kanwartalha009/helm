import { useParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { Breadcrumb } from '@/components/ui';
import { BrandSubnav } from '@/components/shell/BrandSubnav';
import { useBrandDetail } from '@/hooks/useApiData';
import { BrandAuditView } from '@/components/audit/BrandAuditView';

/**
 * Per-brand store audit page (`/brands/:slug/audit`). Brand chrome (breadcrumb +
 * header + sibling sub-nav) around the shared BrandAuditView, which owns the
 * rules-driven findings. The same view renders on the top-level Store audit hub
 * after a brand is chosen.
 */
export function BrandAuditPage() {
  const { slug } = useParams();
  const { data: detail } = useBrandDetail(slug);
  const brand = detail?.brand;
  const brandName = brand?.name ?? 'Brand';
  const brandInitials = brand?.initials ?? '··';

  return (
    <AppLayout title="Store audit">
      <Breadcrumb
        crumbs={[
          { label: 'Brands', to: '/brands' },
          { label: brandName, to: `/brands/${slug}` },
          { label: 'Store audit' },
        ]}
      />

      <div className="page-header">
        <div className="flex items-center gap-12">
          <span className="brand-avatar" style={{ width: 32, height: 32 }}>{brandInitials}</span>
          <div>
            <h2 className="page-title">{brandName} — store audit</h2>
            <p className="page-subtitle">
              Rules-driven findings from campaign verdicts, stock levels and data freshness
            </p>
          </div>
        </div>
      </div>

      <BrandSubnav slug={slug} />

      <BrandAuditView slug={slug} />
    </AppLayout>
  );
}
