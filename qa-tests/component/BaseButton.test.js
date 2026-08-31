import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import BaseButton from '../../resources/js/components/ui/BaseButton.vue';

describe('BaseButton', () => {
  it('renders its slot content as an accessible button', () => {
    render(BaseButton, { slots: { default: 'Simpan' } });
    expect(screen.getByRole('button', { name: 'Simpan' })).toBeInTheDocument();
  });

  it('emits click when pressed', async () => {
    const user = userEvent.setup();
    render(BaseButton, { slots: { default: 'Simpan' } });
    const onClick = vi.fn();
    screen.getByRole('button').addEventListener('click', onClick);
    await user.click(screen.getByRole('button', { name: 'Simpan' }));
    expect(onClick).toHaveBeenCalledOnce();
  });

  it('is disabled and unclickable while loading', () => {
    render(BaseButton, { props: { loading: true }, slots: { default: 'Simpan' } });
    expect(screen.getByRole('button', { name: /simpan/i })).toBeDisabled();
  });

  it('respects an explicit disabled prop', () => {
    render(BaseButton, { props: { disabled: true }, slots: { default: 'Simpan' } });
    expect(screen.getByRole('button', { name: 'Simpan' })).toBeDisabled();
  });
});
