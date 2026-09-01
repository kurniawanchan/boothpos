import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import BaseSelect from '../../resources/js/components/ui/BaseSelect.vue';

// Regression coverage for the native <select> replacement — the popup
// list on a real <select> uses OS chrome that can't be restyled and
// looked completely out of place next to the rest of the app (see the
// commit this test landed with). This component is a custom listbox
// instead, teleported to <body> so it can't be silently clipped by an
// ancestor drawer/modal's `overflow`, which is a real trap: geometry
// and computed style all look correct even when nothing is visible.
const OPTIONS = [
  { value: 1, label: 'Ryu Illustration' },
  { value: 2, label: 'Yayi' },
];

describe('BaseSelect', () => {
  it('shows the placeholder when nothing is selected, and does not render a native <select>', () => {
    render(BaseSelect, { props: { options: OPTIONS, placeholder: 'Pilih…' } });
    expect(screen.getByRole('combobox')).toHaveTextContent('Pilih…');
    expect(document.querySelector('select')).toBeNull();
  });

  it('shows the selected option label when modelValue matches', () => {
    render(BaseSelect, { props: { options: OPTIONS, modelValue: 2 } });
    expect(screen.getByRole('combobox')).toHaveTextContent('Yayi');
  });

  it('opens the listbox on click and lists every option', async () => {
    const user = userEvent.setup();
    render(BaseSelect, { props: { options: OPTIONS } });
    await user.click(screen.getByRole('combobox'));
    expect(screen.getByRole('listbox')).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Ryu Illustration' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Yayi' })).toBeInTheDocument();
  });

  it('emits update:modelValue and closes when an option is clicked', async () => {
    const user = userEvent.setup();
    const { emitted } = render(BaseSelect, { props: { options: OPTIONS } });
    await user.click(screen.getByRole('combobox'));
    await user.click(screen.getByRole('option', { name: 'Yayi' }));
    expect(emitted()['update:modelValue'][0]).toEqual([2]);
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('opens on ArrowDown when closed, matching native <select> semantics', async () => {
    const user = userEvent.setup();
    render(BaseSelect, { props: { options: OPTIONS } });
    screen.getByRole('combobox').focus();
    await user.keyboard('{ArrowDown}');
    expect(screen.getByRole('listbox')).toBeInTheDocument();
  });

  it('once open, ArrowDown moves the active option and Enter commits it', async () => {
    const user = userEvent.setup();
    const { emitted } = render(BaseSelect, { props: { options: OPTIONS, modelValue: 1 } });
    await user.click(screen.getByRole('combobox'));
    await user.keyboard('{ArrowDown}');
    // Still open — the component must preventDefault so the page doesn't
    // scroll and inadvertently trigger the click-outside/scroll-close path.
    expect(screen.getByRole('listbox')).toBeInTheDocument();
    await user.keyboard('{Enter}');
    expect(emitted()['update:modelValue'][0]).toEqual([2]);
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('closes on Escape without changing the value', async () => {
    const user = userEvent.setup();
    const { emitted } = render(BaseSelect, { props: { options: OPTIONS, modelValue: 1 } });
    await user.click(screen.getByRole('combobox'));
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    expect(emitted()['update:modelValue']).toBeUndefined();
  });

  it('does not open when disabled', async () => {
    const user = userEvent.setup();
    render(BaseSelect, { props: { options: OPTIONS, disabled: true } });
    await user.click(screen.getByRole('combobox'));
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('renders the error message and marks the trigger invalid', () => {
    render(BaseSelect, { props: { options: OPTIONS, error: 'Wajib diisi' } });
    expect(screen.getByText('Wajib diisi')).toBeInTheDocument();
    expect(screen.getByRole('combobox')).toHaveAttribute('aria-invalid', 'true');
  });
});
