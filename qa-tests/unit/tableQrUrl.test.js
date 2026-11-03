import { describe, it, expect } from 'vitest';
import { isReachableBaseUrl } from '../../resources/js/utils/selfServiceBaseUrl';
import { qrFilename } from '../../resources/js/utils/qrImage';
import { buildPrintHtml, layoutCells, PER_PAGE } from '../../resources/js/utils/qrSheetPdf';

describe('isReachableBaseUrl', () => {
  it('accepts LAN addresses and domains over http or https', () => {
    for (const ok of ['http://192.168.1.10:8010', 'https://toko.example.com', ' http://10.0.0.5 ', 'http://[2001:db8::1]:8080']) {
      expect(isReachableBaseUrl(ok), ok).toBe(true);
    }
  });

  it('refuses localhost, loopback, other schemes and garbage', () => {
    for (const bad of ['http://localhost:8010', 'http://LOCALHOST', 'http://app.localhost', 'http://127.0.0.1', 'http://127.1.2.3:80', 'http://0.0.0.0', 'http://[::1]:8000', 'ftp://toko.example', 'bukan url', '', '192.168.1.10']) {
      expect(isReachableBaseUrl(bad), bad).toBe(false);
    }
  });
});

describe('qrFilename', () => {
  it('keeps only safe characters', () => {
    expect(qrFilename(['Warkop WJM 3', 'Meja A/5'], 'png')).toBe('warkop-wjm-3-meja-a-5.png');
    expect(qrFilename(['../../etc', 'passwd'], 'pdf')).toBe('etc-passwd.pdf');
    expect(qrFilename([null, ''], 'png')).toBe('qr-meja.png');
  });
});

describe('layoutCells', () => {
  it('places six cards per A4 page in two columns and starts a new page after that', () => {
    const cells = layoutCells(PER_PAGE + 1);

    expect(PER_PAGE).toBe(6);
    expect(cells.slice(0, 6).every((c) => c.page === 0)).toBe(true);
    expect(cells[6].page).toBe(1);
    // Dua kolom: kartu kedua sebaris dengan yang pertama dan lebih ke kanan; kartu ketiga turun satu baris.
    expect(cells[1].y).toBe(cells[0].y);
    expect(cells[1].x).toBeGreaterThan(cells[0].x);
    expect(cells[2].y).toBeGreaterThan(cells[0].y);
    // Kartu pertama halaman baru kembali ke sel kiri-atas.
    expect(cells[6].x).toBe(cells[0].x);
    expect(cells[6].y).toBe(cells[0].y);
  });

  it('never lets a card leave the page', () => {
    for (const c of layoutCells(30)) {
      expect(c.x + c.w).toBeLessThanOrEqual(210);
      expect(c.y + c.h).toBeLessThanOrEqual(297);
    }
  });
});

describe('buildPrintHtml', () => {
  it('escapes names so a table called with markup cannot inject script into the print window', () => {
    const html = buildPrintHtml(
      [{ outlet_name: '<img src=x onerror=alert(1)>', table_name: '"><script>alert(1)</script>', qr: 'data:image/png;base64,AAAA' }],
      'Pindai untuk memesan',
      'QR & meja',
    );

    expect(html).not.toContain('<script>alert');
    expect(html).not.toContain('<img src=x');
    expect(html).toContain('&lt;script&gt;');
    expect(html).toContain('QR &amp; meja');
    expect(html).toContain('Pindai untuk memesan');
  });

  it('uses no hex colour literals', () => {
    expect(buildPrintHtml([{ table_name: '1', qr: 'data:' }], 'x', 'y')).not.toMatch(/#[0-9a-fA-F]{3,8}\b/);
  });
});
