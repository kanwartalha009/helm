// Dead route. /add-brand* is redirected to /dashboard in App.tsx — the
// real brand-create flow lives in AddBrandDrawer triggered from the topbar.
import { Navigate } from 'react-router-dom';

export function AddBrandStep2Page() {
  return <Navigate to="/dashboard" replace />;
}
