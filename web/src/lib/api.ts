import axios from 'axios';

// Axios instance that will talk to the Laravel API once it exists.
// In development, Vite proxies /api to http://localhost:8000 (see vite.config.ts).
export const api = axios.create({
  baseURL: '/api',
  // No cookies — auth is pure bearer token, set by the request interceptor below.
  // Setting withCredentials would trigger CORS preflight requirements we don't need.
  withCredentials: false,
  headers: {
    Accept: 'application/json',
  },
});

// Sanctum bearer-token interceptor — picks up the token saved by the auth lib.
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('helm.auth.token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Auto-unwrap Laravel's `{ data: ... }` envelope so frontend code can treat
// responses as the resource directly. Belt-and-suspenders alongside the
// JsonResource::withoutWrapping() call in the backend AppServiceProvider —
// if either side reverts, the SPA keeps working.
//
// Heuristic: if the response is an object whose ONLY top-level key is `data`,
// unwrap it. Anything else (paginated lists with { data, meta, links }, etc.)
// is left alone.
api.interceptors.response.use(
  (response) => {
    const body = response.data;
    if (
      body &&
      typeof body === 'object' &&
      !Array.isArray(body) &&
      Object.keys(body).length === 1 &&
      'data' in body
    ) {
      response.data = body.data;
    }
    return response;
  },
  (error) => {
    if (error?.response?.status === 401) {
      window.dispatchEvent(new CustomEvent('helm:auth:unauthenticated'));
    }
    return Promise.reject(error);
  }
);
