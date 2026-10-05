import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
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
