import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { printElement } from '../../resources/js/utils/printElement';

describe('printElement', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
  });

  it('returns false and does nothing without an element', () => {
    expect(printElement(null)).toBe(false);
    expect(document.querySelector('iframe')).toBeNull();
  });

  it('prints ONLY the element inside a hidden iframe, then removes it', () => {
    const el = document.createElement('div');
    el.innerHTML = '<p>isi dokumen</p>';
    document.body.appendChild(el);
    const print = vi.fn();
    const originalAppend = document.body.appendChild.bind(document.body);
    vi.spyOn(document.body, 'appendChild').mockImplementation((node) => {
      const appended = originalAppend(node);
      if (node.tagName === 'IFRAME') {
        Object.defineProperty(node.contentWindow, 'print', { value: print, configurable: true });
        Object.defineProperty(node.contentWindow, 'focus', { value: vi.fn(), configurable: true });
      }
      return appended;
    });

    expect(printElement(el, 'bukti <1>')).toBe(true);
    const frame = document.querySelector('iframe');
    expect(frame.getAttribute('aria-hidden')).toBe('true');
    expect(frame.contentDocument.body.innerHTML).toContain('isi dokumen');
    expect(frame.contentDocument.title).toBe('bukti <1>');

    vi.advanceTimersByTime(400);
    expect(print).toHaveBeenCalledTimes(1);
    vi.advanceTimersByTime(1100);
    expect(document.querySelector('iframe')).toBeNull();
  });
});
