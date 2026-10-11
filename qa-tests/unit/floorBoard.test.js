import { describe, it, expect } from 'vitest';
import { buildBoard, OVER_LIMIT_MINUTES, formatElapsed, minutesSince } from '../../resources/js/utils/floorBoard';

// 044 (T008) — satu-satunya tempat angka papan Meja dan keadaan ubin dihitung. Angka dan ubin berasal dari fungsi
// yang sama, jadi "angka tidak pernah menyimpang dari ubin" (SC-004) dijaga di sini.

const NOW = Date.parse('2026-10-11T12:00:00Z');
const ago = (min) => new Date(NOW - min * 60_000).toISOString();

const tab = (id, over = {}) => ({
  id, order_number: `TRX-${id}`, total_amount: '10000.00', paid_amount: '0.00', opened_at: ago(10),
  session_id: 1, ready_count: 0, unsent_count: 0, ...over,
});
const table = (id, name, over = {}, tabOver = undefined) => ({
  id, name, seats: 2, is_active: true,
  state: tabOver === undefined ? 'free' : 'occupied',
  tab: tabOver === undefined ? null : tab(id * 100, tabOver),
  ...over,
});

describe('buildBoard — figures', () => {
  it('counts open orders, occupied tables and the open value from the same tiles', () => {
    const board = buildBoard({
      areas: [{ area: 'Indoor', tables: [table(1, 'A1'), table(2, 'A2', {}, { total_amount: '33000.00' }), table(3, 'A3', {}, { total_amount: '19800.00' })] }],
      takeaway_tabs: [{ ...tab(900, { total_amount: '5000.00' }), tab_label: 'Budi' }],
    }, NOW);

    expect(board.figures).toMatchObject({ openOrders: 3, occupiedTables: 2, totalTables: 3, occupancyPct: 67 });
    expect(board.figures.openValue).toBe('57800.00');
    const tiles = [...board.groups.flatMap((g) => g.tiles), ...board.takeaway];
    expect(tiles.filter((t) => t.state === 'occupied')).toHaveLength(board.figures.openOrders);
    expect(tiles.reduce((s, t) => s + (t.state === 'occupied' ? t.amountCents : 0), 0)).toBe(5_780_000);
  });

  it('sums money in integer cents (no float drift)', () => {
    const board = buildBoard({
      areas: [{ area: '', tables: [1, 2, 3].map((i) => table(i, `T${i}`, {}, { total_amount: '33000.10' })) }],
      takeaway_tabs: [],
    }, NOW);

    expect(board.figures.openValue).toBe('99000.30');
  });

  it('an empty floor gives zeros and no NaN', () => {
    const board = buildBoard({ areas: [], takeaway_tabs: [] }, NOW);

    expect(board.figures).toMatchObject({ openOrders: 0, occupiedTables: 0, totalTables: 0, occupancyPct: 0, overLimit: 0, openValue: '0.00' });
    expect(board.groups).toEqual([]);
    expect(board.takeaway).toEqual([]);
  });

  it('takeaway orders count as open orders and value but never as table occupancy', () => {
    const board = buildBoard({ areas: [{ area: 'Indoor', tables: [table(1, 'A1')] }], takeaway_tabs: [{ ...tab(7), tab_label: null }] }, NOW);

    expect(board.figures).toMatchObject({ openOrders: 1, occupiedTables: 0, totalTables: 1 });
    expect(board.takeaway[0]).toMatchObject({ kind: 'takeaway', name: 'TRX-7', state: 'occupied' });
  });
});

describe('buildBoard — the 45 minute rule', () => {
  it('flags strictly more than 45 minutes: 45 is not over, 46 is', () => {
    expect(OVER_LIMIT_MINUTES).toBe(45);
    const board = buildBoard({
      areas: [{ area: '', tables: [table(1, 'a', {}, { opened_at: ago(45) }), table(2, 'b', {}, { opened_at: ago(46) }), table(3, 'c', {}, { opened_at: ago(44) })] }],
      takeaway_tabs: [],
    }, NOW);

    const flags = board.groups[0].tiles.map((t) => t.overLimit);
    expect(flags).toEqual([false, true, false]);
    expect(board.figures.overLimit).toBe(1);
  });

  it('a long-open takeaway tile is flagged but is not a "table over 45 min"', () => {
    const board = buildBoard({ areas: [], takeaway_tabs: [{ ...tab(1, { opened_at: ago(90) }), tab_label: 'X' }] }, NOW);

    expect(board.takeaway[0].overLimit).toBe(true);
    expect(board.figures.overLimit).toBe(0);
  });

  it('uses real elapsed time across midnight, not the business date', () => {
    const opened = '2026-10-10T23:50:00Z';
    const board = buildBoard({ areas: [{ area: '', tables: [table(1, 'a', {}, { opened_at: opened })] }], takeaway_tabs: [] }, Date.parse('2026-10-11T00:20:00Z'));

    expect(board.groups[0].tiles[0].elapsedMinutes).toBe(30);
    expect(board.groups[0].tiles[0].overLimit).toBe(false);
  });
});

describe('buildBoard — tile states', () => {
  it('flags a partly paid order and not an unpaid or fully paid one', () => {
    const board = buildBoard({
      areas: [{ area: '', tables: [
        table(1, 'none', {}, { total_amount: '100.00', paid_amount: '0.00' }),
        table(2, 'part', {}, { total_amount: '100.00', paid_amount: '40.00' }),
        table(3, 'full', {}, { total_amount: '100.00', paid_amount: '100.00' }),
      ] }],
      takeaway_tabs: [],
    }, NOW);

    expect(board.groups[0].tiles.map((t) => t.partial)).toEqual([false, true, false]);
  });

  it('keeps an inactive table that still holds an order, marked inactive and counted', () => {
    const board = buildBoard({ areas: [{ area: '', tables: [table(1, 'old', { is_active: false }, { total_amount: '7000.00' })] }], takeaway_tabs: [] }, NOW);

    expect(board.groups[0].tiles[0]).toMatchObject({ inactive: true, state: 'occupied' });
    expect(board.figures).toMatchObject({ occupiedTables: 1, totalTables: 1, openOrders: 1 });
  });

  it('an empty tile carries no order data', () => {
    const board = buildBoard({ areas: [{ area: '', tables: [table(1, 'free')] }], takeaway_tabs: [] }, NOW);

    expect(board.groups[0].tiles[0]).toMatchObject({ state: 'empty', orderId: null, orderNumber: null, elapsedMinutes: null, partial: false, overLimit: false });
  });

  it('a missing paid_amount (older server) means not partial', () => {
    const t = table(1, 'a', {}, { total_amount: '100.00' });
    delete t.tab.paid_amount;

    expect(buildBoard({ areas: [{ area: '', tables: [t] }], takeaway_tabs: [] }, NOW).groups[0].tiles[0].partial).toBe(false);
  });
});

describe('buildBoard — grouping', () => {
  it('keeps the server order, labels per group and counts occupied/total per area; an unnamed area stays last', () => {
    const board = buildBoard({
      areas: [
        { area: 'Indoor', tables: [table(1, 'a', {}, {}), table(2, 'b')] },
        { area: '', tables: [table(3, 'bar')] },
      ],
      takeaway_tabs: [],
    }, NOW);

    expect(board.groups.map((g) => [g.area, g.occupied, g.total])).toEqual([['Indoor', 1, 2], ['', 0, 1]]);
  });

  it('handles 60 tables in one pass', () => {
    const tables = Array.from({ length: 60 }, (_, i) => table(i + 1, `T${i + 1}`, {}, i % 2 ? {} : undefined));
    const board = buildBoard({ areas: [{ area: 'Hall', tables }], takeaway_tabs: [] }, NOW);

    expect(board.groups[0].tiles).toHaveLength(60);
    expect(board.figures.totalTables).toBe(60);
  });
});

describe('minutesSince / formatElapsed', () => {
  it('floors to whole minutes, never negative, null for an invalid date', () => {
    expect(minutesSince(ago(19.9), NOW)).toBe(19);
    expect(minutesSince(new Date(NOW + 60_000).toISOString(), NOW)).toBe(0);
    expect(minutesSince('garbage', NOW)).toBeNull();
    expect(minutesSince(null, NOW)).toBeNull();
  });

  it('uses minutes under an hour and hours + minutes above', () => {
    const t = (key, p) => `${key}:${JSON.stringify(p)}`;

    expect(formatElapsed(19, t)).toBe('sales.elapsed_min:{"n":19}');
    expect(formatElapsed(59, t)).toBe('sales.elapsed_min:{"n":59}');
    expect(formatElapsed(65, t)).toBe('sales.elapsed_hm:{"h":1,"m":5}');
    expect(formatElapsed(null, t)).toBe('');
  });
});
