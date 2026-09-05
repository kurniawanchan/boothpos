import client from './client';

export function getLicenseStatus() {
  return client.get('/license/status').then((r) => r.data);
}

export function activateLicense(licenseKey) {
  return client.post('/license/activate', { license_key: licenseKey }).then((r) => r.data);
}
