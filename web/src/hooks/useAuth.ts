import { useQuery } from '@tanstack/react-query';
import * as mockApi from '@/lib/mockApi';

// Returns the current user. In the real API this hits /api/auth/me.
export function useAuth() {
  return useQuery({
    queryKey: ['auth', 'me'],
    queryFn: mockApi.getCurrentUser,
    staleTime: 5 * 60_000,
  });
}
