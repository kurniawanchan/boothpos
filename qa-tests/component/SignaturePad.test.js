import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/vue';
import SignaturePad from '../../resources/js/components/settlement/SignaturePad.vue';

// 076 (US3) — kanvas tanda tangan. jsdom tidak punya canvas 2D, jadi
// getContext/toBlob dipalsukan; yang diuji: status "ada goresan", sinyal
// change, hapus, dan hasil PNG hanya bila ada goresan.
function stubCanvas() {
  const ctx = {
    scale: vi.fn(), beginPath: vi.fn(), moveTo: vi.fn(), lineTo: vi.fn(), stroke: vi.fn(),
    save: vi.fn(), restore: vi.fn(), setTransform: vi.fn(), clearRect: vi.fn(),
  };
  vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(ctx);
  vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((cb, type) => cb(new Blob(['png-bytes'], { type })));
  return ctx;
}

function renderPad() {
  let exposed;
  const result = render(SignaturePad, {
    attrs: {},
    global: {
      config: {},
    },
  });
  exposed = result;
  return exposed;
}

describe('SignaturePad', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it('starts empty: nothing to export and the placeholder hint is shown', async () => {
    stubCanvas();
    const { container, emitted } = renderPad();

    expect(screen.getByText(/tanda tangan di sini/i)).toBeInTheDocument();
    expect(container.querySelector('canvas')).toBeTruthy();
    expect(emitted().change).toBeUndefined();
  });

  it('drawing marks ink, emits change(true) once, and hides the hint', async () => {
    const ctx = stubCanvas();
    const { emitted } = renderPad();
    const canvas = screen.getByTestId('signature-canvas');

    await fireEvent.pointerDown(canvas, { clientX: 10, clientY: 10, pointerId: 1 });
    await fireEvent.pointerMove(canvas, { clientX: 40, clientY: 30, pointerId: 1 });
    await fireEvent.pointerMove(canvas, { clientX: 70, clientY: 20, pointerId: 1 });
    await fireEvent.pointerUp(canvas, { pointerId: 1 });

    expect(emitted().change).toEqual([[true]]);
    expect(ctx.stroke).toHaveBeenCalled();
    expect(screen.queryByText(/tanda tangan di sini/i)).not.toBeInTheDocument();
  });

  it('moving the pointer without pressing draws nothing', async () => {
    const ctx = stubCanvas();
    const { emitted } = renderPad();

    await fireEvent.pointerMove(screen.getByTestId('signature-canvas'), { clientX: 5, clientY: 5 });

    expect(emitted().change).toBeUndefined();
    expect(ctx.stroke).not.toHaveBeenCalled();
  });

  it('clear resets the ink and emits change(false)', async () => {
    stubCanvas();
    const { emitted } = renderPad();
    const canvas = screen.getByTestId('signature-canvas');
    await fireEvent.pointerDown(canvas, { clientX: 1, clientY: 1, pointerId: 1 });

    await fireEvent.click(screen.getByRole('button', { name: /hapus coretan/i }));

    expect(emitted().change).toEqual([[true], [false]]);
    expect(screen.getByText(/tanda tangan di sini/i)).toBeInTheDocument();
  });
});
