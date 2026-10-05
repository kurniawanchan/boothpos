import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import BaseTooltip from '../../resources/js/components/ui/BaseTooltip.vue';

// 037 — tooltip yang bisa dicapai papan ketik (title native tidak bisa).
function renderTip() {
  return render(BaseTooltip, { props: { text: 'Apa itu BOM' }, slots: { default: '<button type="button">Buka BOM</button>' } });
}

describe('BaseTooltip (037)', () => {
  it('is hidden until the trigger is hovered or focused, and links the bubble via aria-describedby', async () => {
    const user = userEvent.setup();
    renderTip();
    const tip = screen.getByRole('tooltip', { hidden: true });

    expect(tip).toHaveTextContent('Apa itu BOM');
    expect(tip).toHaveAttribute('aria-hidden', 'true');
    expect(screen.getByRole('button', { name: 'Buka BOM' }).parentElement).toHaveAttribute('aria-describedby', tip.id);

    await user.hover(screen.getByRole('button', { name: 'Buka BOM' }));
    expect(screen.getByRole('tooltip')).toBeVisible();
  });

  it('shows on keyboard focus and Escape hides it', async () => {
    const user = userEvent.setup();
    renderTip();

    await user.tab();
    expect(screen.getByRole('button', { name: 'Buka BOM' })).toHaveFocus();
    expect(screen.getByRole('tooltip')).toBeVisible();

    await user.keyboard('{Escape}');
    expect(screen.getByRole('tooltip', { hidden: true })).toHaveAttribute('aria-hidden', 'true');
  });
});

// 038 — bubble di-teleport ke body dengan posisi fixed: DataTable membungkus tabel dalam `overflow-auto`,
// jadi bubble `absolute` di dalam sel akan terpotong (dan menambah scrollbar di baris terakhir).
function rect(overrides = {}) {
  return { top: 100, bottom: 130, left: 50, right: 200, width: 150, height: 30, x: 50, y: 100, ...overrides };
}

async function hoverWith(r, props = {}, { w = 1200, h = 800 } = {}) {
  Object.defineProperty(window, 'innerWidth', { value: w, configurable: true });
  Object.defineProperty(window, 'innerHeight', { value: h, configurable: true });
  const user = userEvent.setup();
  const view = render(BaseTooltip, { props: { text: 'Nama varian', ...props }, slots: { default: '<button type="button">SKU</button>' } });
  const trigger = screen.getByRole('button', { name: 'SKU' }).parentElement;
  trigger.getBoundingClientRect = () => r;
  await user.hover(screen.getByRole('button', { name: 'SKU' }));

  return { user, view, tip: screen.getByRole('tooltip', { hidden: true }) };
}

describe('BaseTooltip — fixed, teleported bubble (038)', () => {
  it('renders the bubble in <body> (outside the table/scroll container) with fixed positioning', async () => {
    const { view, tip } = await hoverWith(rect());

    expect(view.container.contains(tip)).toBe(false);
    expect(document.body.contains(tip)).toBe(true);
    expect(tip.style.position).toBe('fixed');
    expect(tip).toBeVisible();
  });

  it('opens below the trigger by default, left-aligned to it', async () => {
    const { tip } = await hoverWith(rect());

    expect(tip.style.top).toBe('136px'); // bottom 130 + 6
    expect(tip.style.left).toBe('50px');
    expect(tip.style.bottom).toBe('');
  });

  it('flips ABOVE the trigger when there is no room below and more room above', async () => {
    const { tip } = await hoverWith(rect({ top: 760, bottom: 790 }));

    expect(tip.style.bottom).toBe('46px'); // innerHeight 800 - top 760 + 6
    expect(tip.style.top).toBe('');
  });

  it('right-aligns to the trigger with align="right", and clamps into the viewport', async () => {
    const right = await hoverWith(rect({ left: 300, right: 500, width: 200 }), { align: 'right' });
    expect(right.tip.style.left).toBe('244px'); // right 500 - 256
  });

  it('clamps a trigger near the right edge and one near the left edge', async () => {
    const nearRight = await hoverWith(rect({ left: 1150, right: 1200, width: 50 }));
    expect(nearRight.tip.style.left).toBe('936px'); // 1200 - 8 - 256
  });

  it('clamps to 8px from the left edge', async () => {
    const { tip } = await hoverWith(rect({ left: 2, right: 40, width: 38 }));

    expect(tip.style.left).toBe('8px');
  });

  it('closes when the page scrolls or resizes, so a fixed bubble never floats over the wrong spot', async () => {
    const scroll = await hoverWith(rect());
    await fireEvent.scroll(document.body);
    expect(scroll.tip).toHaveAttribute('aria-hidden', 'true');
  });

  it('closes on resize', async () => {
    const { tip } = await hoverWith(rect());

    window.dispatchEvent(new Event('resize'));
    await Promise.resolve();

    expect(tip).toHaveAttribute('aria-hidden', 'true');
  });

  it('never shows a bubble for blank text', async () => {
    const { tip } = await hoverWith(rect(), { text: '   ' });

    expect(tip).toHaveAttribute('aria-hidden', 'true');
    expect(tip).not.toBeVisible();
  });
});

