// Dead route. /team/invite is redirected to /team in App.tsx — the actual
// invite flow lives in the InviteUserDrawer triggered from the Team page.
// Kept as an explicit export so anyone with a stale import gets a redirect
// instead of a build error.
import { Navigate } from 'react-router-dom';

export function InviteUserPage() {
  return <Navigate to="/team" replace />;
}
