// Dead route. /add-brand* is redirected to /dashboard in App.tsx — the
// real brand-create flow lives in AddBrandDrawer triggered from the topbar
// and empty states.
import { Navigate } from 'react-router-dom';

export function AddBrandStep1Page() {
  return <Navigate to="/dashboard" replace />;
}
