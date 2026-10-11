import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';

vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn() }));
vi.mock('../../resources/js/api/kitchen', () => ({ getStationTickets: vi.fn(), setTicketItemStatus: vi.fn() }));

import { listStations } from '../../resources/js/api/stations';
import { getStationTickets, setTicketItemStatus } from '../../resources/js/api/kitchen';
import { useKitchenStore } from '../../resources/js/stores/kitchen';

const t = (id, statuses) => ({ id, items: statuses.map((status, i) => ({ id: id * 10 + i, status })) });

describe('useKitchenStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    listStations.mockResolvedValue({ data: [{ id: 1, name: 'Dapur' }, { id: 2, name: 'Bar' }] });
  });

  it('loads active stations and selects the first', async () => {
    const k = useKitchenStore();
    await k.loadStations();

    expect(listStations).toHaveBeenCalledWith({ active: 1 });
    expect(k.stationId).toBe(1);
  });

  it('keeps the selected station when it is still available, and falls back when it vanished', async () => {
    const k = useKitchenStore();
    await k.loadStations();
    k.stationId = 2;
    await k.loadStations();
    expect(k.stationId).toBe(2);

    k.stationId = 99;
    await k.loadStations();
    expect(k.stationId).toBe(1);
  });

  it('fetches with the remembered version and keeps the rendered tickets when unchanged', async () => {
    getStationTickets.mockResolvedValueOnce({ tickets: [t(1, ['new'])], version: 'v1' }).mockResolvedValueOnce({ unchanged: true, version: 'v1' });
    const k = useKitchenStore();
    k.stationId = 1;
    await k.fetch();
    const before = k.tickets;

    await k.fetch();

    expect(getStationTickets).toHaveBeenLastCalledWith(1, 'v1');
    expect(k.tickets).toBe(before);
    expect(k.loaded).toBe(true);
  });

  it('drops the version on a full fetch', async () => {
    getStationTickets.mockResolvedValue({ tickets: [], version: 'v2' });
    const k = useKitchenStore();
    k.stationId = 1;
    k.version = 'stale';

    await k.fetch({ full: true });

    expect(getStationTickets).toHaveBeenLastCalledWith(1, null);
  });

  it('does nothing without a selected station', async () => {
    const k = useKitchenStore();
    await k.fetch();
    expect(getStationTickets).not.toHaveBeenCalled();
  });

  it('resets tickets and version when the station changes, so the old station never flashes', () => {
    const k = useKitchenStore();
    k.stationId = 1; k.tickets = [t(1, ['new'])]; k.version = 'v1'; k.loaded = true;

    k.selectStation(2);

    expect(k.stationId).toBe(2);
    expect(k.tickets).toEqual([]);
    expect(k.version).toBeNull();
    expect(k.loaded).toBe(false);
  });

  it('ignores a response that arrives after the station was switched', async () => {
    let resolveFirst;
    getStationTickets.mockImplementationOnce(() => new Promise((r) => { resolveFirst = r; }));
    const k = useKitchenStore();
    k.stationId = 1;
    const pending = k.fetch();

    k.selectStation(2);
    resolveFirst({ tickets: [t(1, ['new'])], version: 'old' });
    await pending;

    expect(k.tickets).toEqual([]);
    expect(k.version).toBeNull();
  });

  it('splits tickets into in-progress and recently finished', () => {
    const k = useKitchenStore();
    k.tickets = [t(1, ['new']), t(2, ['served', 'cancelled']), t(3, ['served', 'ready']), t(4, ['cancelled'])];

    expect(k.activeTickets.map((x) => x.id)).toEqual([1, 3]);
    expect(k.recentTickets.map((x) => x.id)).toEqual([2, 4]);
  });

  it('sends a status change and then refetches in full', async () => {
    setTicketItemStatus.mockResolvedValue({});
    getStationTickets.mockResolvedValue({ tickets: [], version: 'v9' });
    const k = useKitchenStore();
    k.stationId = 1;
    k.version = 'v1';

    await k.setStatus({ id: 55 }, 'ready');

    expect(setTicketItemStatus).toHaveBeenCalledWith(55, 'ready');
    expect(getStationTickets).toHaveBeenLastCalledWith(1, null);
  });
});
