import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ApprovalList from '../../resources/js/components/product/ApprovalList.vue';
import { listApprovals, approveApproval, rejectApproval } from '../../resources/js/api/menu';
import { listReasonCodes } from '../../resources/js/api/reasons';
import { useAuthStore } from '../../resources/js/stores/auth';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/menu', () => ({ listApprovals: vi.fn(), approveApproval: vi.fn(), rejectApproval: vi.fn() }));
vi.mock('../../resources/js/api/reasons', () => ({ listReasonCodes: vi.fn() }));

const ROW = (id, extra = {}) => ({
  id, decision: 'pending', difference_pct: 25, amount: '2500.00', product_name: `Item ${id}`, variant_name: 'Besar',
  old_price: '10000.00', new_price: '12500.00', requested_by: 'Rina', requested_by_id: 3, change_id: id + 100, ...extra,
});

function renderList() {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 7, name: 'Budi', permissions: ['menu.price.approve'] };
  const handlers = { onClose: vi.fn(), onChanged: vi.fn() };
  const view = render(ApprovalList, { props: { open: true, ...handlers }, global: { plugins: [pinia] } });
  return { ...view, ...handlers };
}
async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

// 043 (T066) — daftar persetujuan harga.
describe('ApprovalList (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listApprovals.mockResolvedValue({ threshold_pct: 15, data: [ROW(1), ROW(2, { requested_by: 'Budi', requested_by_id: 7 })] });
    listReasonCodes.mockResolvedValue({ data: [{ id: 5, code: 'too_high', label: 'Terlalu tinggi' }] });
  });

  it('asks only pending menu_price approvals and shows item, old → new, difference and requester', async () => {
    renderList();
    const rows = await screen.findAllByTestId('approval-row');
    expect(listApprovals).toHaveBeenCalledWith({ kind: 'menu_price', status: 'pending' });
    expect(rows[0]).toHaveTextContent('Item 1 · Besar');
    expect(rows[0]).toHaveTextContent(/10\.000/);
    expect(rows[0]).toHaveTextContent(/12\.500/);
    expect(rows[0]).toHaveTextContent('Selisih 25%');
    expect(rows[0]).toHaveTextContent('Diajukan oleh Rina');
    expect(screen.getByRole('dialog')).toHaveTextContent('15%');
  }, 30000);

  it('hides Approve and Reject on the user\'s own request (requester ≠ approver) and says why', async () => {
    renderList();
    const [others, own] = await screen.findAllByTestId('approval-row');
    expect(within(others).getByRole('button', { name: /^Setujui/ })).toBeInTheDocument();
    expect(within(others).getByRole('button', { name: /^Tolak/ })).toBeInTheDocument();
    expect(within(own).queryByRole('button', { name: /^Setujui/ })).not.toBeInTheDocument();
    expect(within(own).queryByRole('button', { name: /^Tolak/ })).not.toBeInTheDocument();
    expect(within(own).getByTestId('approval-own')).toBeInTheDocument();
  }, 30000);

  it('compares the requester ID, never the name: a different user with the same name still gets the buttons, and a different name with my id does not', async () => {
    listApprovals.mockResolvedValue({ threshold_pct: 15, data: [
      ROW(1, { requested_by: 'Budi', requested_by_id: 99 }), // senama tetapi orang lain
      ROW(2, { requested_by: 'Rina', requested_by_id: 7 }), // nama lain tetapi id saya
    ] });
    renderList();
    const [namesake, mine] = await screen.findAllByTestId('approval-row');
    expect(within(namesake).getByRole('button', { name: /^Setujui/ })).toBeInTheDocument();
    expect(within(namesake).queryByTestId('approval-own')).not.toBeInTheDocument();
    expect(within(mine).getByTestId('approval-own')).toBeInTheDocument();
    expect(within(mine).queryByRole('button', { name: /^Setujui/ })).not.toBeInTheDocument();
  }, 30000);

  it('approves, reloads the list and tells the page', async () => {
    approveApproval.mockResolvedValue({ id: 1, decision: 'approved' });
    const user = await userEv();
    const { onChanged } = renderList();
    const [first] = await screen.findAllByTestId('approval-row');
    await user.click(within(first).getByRole('button', { name: /^Setujui/ }));
    await waitFor(() => expect(approveApproval).toHaveBeenCalledWith(1));
    await waitFor(() => expect(listApprovals).toHaveBeenCalledTimes(2));
    expect(onChanged).toHaveBeenCalled();
  }, 30000);

  it('reloads the truth when the server refuses with 409 (already decided / self approval) and does not report success', async () => {
    approveApproval.mockRejectedValue(new ApiError('Sudah diputuskan.', { status: 409 }));
    const user = await userEv();
    const { onChanged } = renderList();
    const [first] = await screen.findAllByTestId('approval-row');
    await user.click(within(first).getByRole('button', { name: /^Setujui/ }));
    await waitFor(() => expect(listApprovals).toHaveBeenCalledTimes(2));
    expect(onChanged).not.toHaveBeenCalled();
  }, 30000);

  it('rejects with a coded reason from the approval_reject category', async () => {
    rejectApproval.mockResolvedValue({ id: 1, decision: 'rejected' });
    const user = await userEv();
    const { onChanged } = renderList();
    const [first] = await screen.findAllByTestId('approval-row');
    await user.click(within(first).getByRole('button', { name: /^Tolak/ }));

    await waitFor(() => expect(listReasonCodes).toHaveBeenCalledWith({ category: 'approval_reject' }));
    const combo = await screen.findByRole('combobox');
    await user.selectOptions(combo, '5');
    const dialogs = screen.getAllByRole('dialog');
    await user.click(within(dialogs.at(-1)).getByRole('button', { name: 'Tolak' }));

    await waitFor(() => expect(rejectApproval).toHaveBeenCalledWith(1, { reason_code_id: 5, note: null }));
    await waitFor(() => expect(onChanged).toHaveBeenCalled());
  }, 30000);

  it('shows an error state with Retry when the list fails, and an empty message when nothing is pending', async () => {
    listApprovals.mockRejectedValueOnce(new ApiError('boom', { status: 500 }));
    const user = await userEv();
    renderList();
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.queryByTestId('approvals-none')).not.toBeInTheDocument();
    listApprovals.mockResolvedValue({ threshold_pct: 15, data: [] });
    await user.click(screen.getByRole('button', { name: 'Coba lagi' }));
    expect(await screen.findByTestId('approvals-none')).toBeInTheDocument();
  }, 30000);
});
