import { describe, it, expect } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import InheritedModifierList from '../../resources/js/components/product/InheritedModifierList.vue';
import id from '../../resources/js/locales/id.json';
import { createI18n } from 'vue-i18n';

const P = id.products;
const i18n = createI18n({ legacy: false, locale: 'id', messages: { id } });
const SUHU = { group_id: 9, name: 'Suhu', source: 'category', is_required: false, min_select: 0, max_select: 1, display_order: 1, options: ['Panas', 'Dingin'] };
const ES = { group_id: 4, name: 'Es', source: 'category', is_required: true, min_select: 1, max_select: 1, display_order: 2, options: ['Banyak'] };

const draw = (props) => render(InheritedModifierList, { props, global: { plugins: [i18n] } });

// 086 — grup warisan kategori: Lepas / Dilepas untuk item ini / Pulihkan.
describe('InheritedModifierList', () => {
  it('lists the inherited groups with their rule text and a Lepas button each when editable', () => {
    draw({ inherited: [SUHU, ES], editable: true });

    const rows = screen.getAllByTestId('im-inherited-row');
    expect(rows).toHaveLength(2);
    expect(rows[0]).toHaveTextContent('Suhu');
    expect(rows[0]).toHaveTextContent('Opsional · 0–1');
    expect(screen.getAllByTestId('im-exclude')).toHaveLength(2);
    expect(screen.getByText(P.ml_from_category_hint)).toBeInTheDocument();
  });

  it('emits exclude with the group id of the clicked row', async () => {
    const { emitted } = draw({ inherited: [SUHU, ES], editable: true });
    const { default: userEvent } = await import('@testing-library/user-event');

    await userEvent.setup().click(screen.getByRole('button', { name: P.ml_exclude_aria.replace('{name}', 'Es') }));

    expect(emitted().exclude).toEqual([[4]]);
  });

  it('shows NO control at all when not editable (hidden, never disabled)', () => {
    draw({ inherited: [SUHU], excluded: [ES], editable: false });

    expect(screen.getByText('Suhu')).toBeInTheDocument();
    expect(screen.getByText('Es')).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.queryByText(P.ml_from_category_hint)).not.toBeInTheDocument();
  });

  it('lists released groups in their own section with Pulihkan, and emits restore', async () => {
    const { emitted } = draw({ inherited: [], excluded: [SUHU], editable: true });
    const { default: userEvent } = await import('@testing-library/user-event');

    const section = screen.getByTestId('im-excluded');
    expect(within(section).getByText(P.ml_excluded_title)).toBeInTheDocument();
    expect(within(section).getByText('Suhu')).toBeInTheDocument();
    await userEvent.setup().click(within(section).getByRole('button', { name: P.ml_restore_aria.replace('{name}', 'Suhu') }));

    expect(emitted().restore).toEqual([[9]]);
  });

  it('renders neither section when both lists are empty', () => {
    draw({ inherited: [], excluded: [], editable: true });

    expect(screen.queryByTestId('im-inherited')).not.toBeInTheDocument();
    expect(screen.queryByTestId('im-excluded')).not.toBeInTheDocument();
  });

  it('carries the testid it is given, so existing surfaces keep their selectors', () => {
    draw({ inherited: [SUHU], editable: true, testid: 'ml-inherited' });

    expect(screen.getByTestId('ml-inherited')).toHaveTextContent('Suhu');
  });
});
