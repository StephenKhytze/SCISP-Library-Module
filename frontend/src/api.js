import axios from 'axios';

const api = axios.create({
  baseURL: 'http://localhost:8000/api', // Laravel API endpoint
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
