import axios from 'axios';

// The backend origin comes from VITE_API_URL (see .env.example). The literal
// below is only the local-development fallback, so a deployed build points at
// the real API without a code change.
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api',
});

api.interceptors.request.use((config) => {
  const userStr = localStorage.getItem('user');
  const user = userStr ? JSON.parse(userStr) : null;
  
  if (user && user.role) {
    config.headers['X-Mock-Role'] = user.role;
  }
  if (user && user.username) {
    config.headers['X-Mock-Username'] = user.username;
  }
  
  return config;
});

export default api;
