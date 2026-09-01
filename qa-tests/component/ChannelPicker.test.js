import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import ChannelPicker from '../../resources/js/components/payment/ChannelPicker.vue';

/**
 * Regression test for the QRIS-with-one-channel bug: with exactly one
 * channel of a type (e.g. one Gopay qr_ewallet channel), the chip list
 * (rendered only when channels.length > 1) never gave the user anything to
 * click, and there was no auto-select fallback — checkout showed nothing
 * but "Pilih kanal pembayaran di atas." forever.
 */
function channel(overrides = {}) {
  return { id: 1, type: 'qr_ewallet', provider: 'Gopay', account_name: 'Toko A', account_number: null, qr_image_url: null, ...overrides };
}

describe('ChannelPicker', () => {
  it('auto-selects the single channel when only one is available', async () => {
    const { emitted } = render(ChannelPicker, { props: { channels: [channel()], modelValue: null } });
    expect(emitted('update:modelValue')[0]).toEqual([1]);
  });

  it('renders the selected channel details once auto-selected', () => {
    render(ChannelPicker, { props: { channels: [channel({ id: 7 })], modelValue: 7 } });
    expect(screen.getByText('Gopay')).toBeInTheDocument();
    expect(screen.queryByText(/pilih kanal pembayaran/i)).not.toBeInTheDocument();
  });

  it('still shows selectable chips and does not auto-select when there are 2+ channels', () => {
    const channels = [channel({ id: 1, provider: 'Gopay' }), channel({ id: 2, provider: 'OVO' })];
    const { emitted } = render(ChannelPicker, { props: { channels, modelValue: null } });
    expect(screen.getByRole('button', { name: 'Gopay' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'OVO' })).toBeInTheDocument();
    expect(emitted('update:modelValue')).toBeUndefined();
  });

  it('clears the selection when the channel list becomes empty (method switched away)', async () => {
    const { rerender, emitted } = render(ChannelPicker, { props: { channels: [channel()], modelValue: 1 } });
    await rerender({ channels: [], modelValue: 1 });
    const events = emitted('update:modelValue');
    expect(events[events.length - 1]).toEqual([null]);
  });
});
