import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/vue';
import SettlementAcknowledgeModal from '../../resources/js/components/settlement/SettlementAcknowledgeModal.vue';

function mount(extra = {}) {
  return render(SettlementAcknowledgeModal, { props: { open: true, ...extra } });
}

describe('SettlementAcknowledgeModal', () => {
  it('typed: requires a name, then emits method + name only', async () => {
    const { emitted } = mount();
    await fireEvent.click(screen.getByRole('tab', { name: /ketik nama/i }));

    await fireEvent.click(screen.getByRole('button', { name: /simpan pengakuan/i }));
    expect(screen.getByRole('alert')).toHaveTextContent(/nama wajib diisi/i);
    expect(emitted().save).toBeUndefined();

    await fireEvent.update(screen.getByLabelText(/nama penanda tangan/i), '  Budi Seller ');
    await fireEvent.click(screen.getByRole('button', { name: /simpan pengakuan/i }));

    await waitFor(() => expect(emitted().save).toHaveLength(1));
    expect(emitted().save[0][0]).toEqual({ method: 'typed', signerName: 'Budi Seller', note: null, signature: null });
  });

  it('external: requires a note and sends no name or signature', async () => {
    const { emitted } = mount();
    await fireEvent.click(screen.getByRole('tab', { name: /dicatat di luar sistem/i }));

    await fireEvent.click(screen.getByRole('button', { name: /simpan pengakuan/i }));
    expect(screen.getByRole('alert')).toHaveTextContent(/catatan wajib diisi/i);

    await fireEvent.update(screen.getByLabelText(/catatan/i), 'Setuju via WhatsApp');
    await fireEvent.click(screen.getByRole('button', { name: /simpan pengakuan/i }));

    await waitFor(() => expect(emitted().save).toHaveLength(1));
    expect(emitted().save[0][0]).toEqual({ method: 'external', signerName: null, note: 'Setuju via WhatsApp', signature: null });
  });

  it('signature: refuses to save without a name, and without a drawing', async () => {
    const { emitted } = mount();

    await fireEvent.click(screen.getByRole('button', { name: /simpan pengakuan/i }));
    expect(screen.getByRole('alert')).toHaveTextContent(/nama wajib diisi/i);

    await fireEvent.update(screen.getByLabelText(/nama penanda tangan/i), 'Budi');
    await fireEvent.click(screen.getByRole('button', { name: /simpan pengakuan/i }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent(/gambarkan tanda tangan/i));
    expect(emitted().save).toBeUndefined();
  });

  it('shows server-side field errors', () => {
    mount({ errors: { signer_name: 'Nama terlalu panjang' } });

    expect(screen.getByText('Nama terlalu panjang')).toBeInTheDocument();
  });
});
