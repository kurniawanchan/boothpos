import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import TicketCard from '../../resources/js/components/restaurant/TicketCard.vue';

// 090 / FR-032 — tiket dapur menunjukkan cara layanan dan penanda pesanan mandiri; payload lama tanpa kunci itu tak berubah.
const ticket = (over = {}) => ({
  id: 10, round_no: 1, sent_at: new Date(Date.now() - 60000).toISOString(), station: { id: 1, name: 'Dapur' },
  order: { id: 3, order_number: 'TRX-3' }, table: { id: 7, name: '5' }, tab_label: null, waiting_seconds: 60,
  items: [{ id: 1, name: 'Nasi Goreng', qty: 2, modifiers: [], note: null, status: 'new', cancel_reason: null }], ...over,
});

describe('TicketCard — cara layanan dan pesanan mandiri', () => {
  it('shows Dine-In and the self-service tag next to the table', () => {
    render(TicketCard, { props: { ticket: ticket({ service_type: 'dine_in', self_service: true }) } });

    expect(screen.getByRole('heading', { name: 'Meja 5' })).toBeInTheDocument();
    expect(screen.getByTestId('ticket-service')).toHaveTextContent('Dine-In');
    expect(screen.getByTestId('ticket-self-service')).toHaveTextContent('Mandiri');
  });

  it('shows Take Away without the self-service tag for a cashier order', () => {
    render(TicketCard, { props: { ticket: ticket({ service_type: 'takeaway', self_service: false }) } });

    expect(screen.getByTestId('ticket-service')).toHaveTextContent('Take Away');
    expect(screen.queryByTestId('ticket-self-service')).not.toBeInTheDocument();
  });

  it('adds nothing for an older payload without these fields', () => {
    render(TicketCard, { props: { ticket: ticket() } });

    expect(screen.queryByTestId('ticket-service')).not.toBeInTheDocument();
    expect(screen.queryByTestId('ticket-self-service')).not.toBeInTheDocument();
  });
});
