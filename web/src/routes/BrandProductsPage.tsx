import { useParams } from 'react-router-dom';
import { AppLayout } from '@/components/shell/AppLayout';
import { Breadcrumb } from '@/components/ui';
import { useBrandDetail } from '@/hooks/useApiData';
import { BrandProductsView } from '@/components/products/BrandProductsView';

/**
 * Per-brand product performance page (`/brands/:slug/products`). Brand chrome
 * (breadcrumb + header) around the shared BrandProductsView, which owns the
 * table, controls and Phase-5 ad spend / ROAS. The same view renders on the
 * top-level Products hub after a brand is chosen.
 */
export function BrandProductsPage() {
  const { slug } = useParams();
  const { data: detail } = useBrandDetail(slug);
  const brand = detail?.brand;
  const brandName = brand?.name ?? 'Brand';
  const brandInitials = brand?.initials ?? '··';

  return (
    <AppLayout title="Product performance">
      <Breadcrumb
        crumbs={[
          { label: 'Brands', to: '/brands' },
          { label: brandName, to: `/brands/${slug}` },
          { label: 'Products' },
        ]}
      />

      <div className="page-header">
        <div className="flex items-center gap-12">
          <span className="brand-avatar" style={{ width: 32, height: 32 }}>{brandInitials}</span>
          <div>
            <h2 className="page-title">{brandName} — products</h2>
            <p className="page-subtitle">Revenue, units, refunds, ad spend and ROAS by product</p>
          </div>
        </div>
      </div>

      <BrandProductsView slug={slug} />
    </AppLayout>
  );
}
