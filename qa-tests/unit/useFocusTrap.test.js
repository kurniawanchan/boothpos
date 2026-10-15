import { describe, it, expect } from 'vitest';
import { defineComponent, h, ref, toRef } from 'vue';
import { render } from '@testing-library/vue';
import { useFocusTrap } from '../../resources/js/composables/useFocusTrap';

/**
 * Focus trap dipakai semua modal/drawer. Fokus awal dipindahkan lewat
 * requestAnimationFrame SETELAH dialog terbuka, jadi tiga hal harus benar:
 * fokus awal wajar, elemen bertanda data-autofocus diutamakan (mis. kolom
 * kata konfirmasi), dan fokus yang SUDAH ada di dalam dialog tidak direbut.
 */
const Harness = defineComponent({
  props: { open: { type: Boolean, default: false } },
  setup(props) {
    const el = ref(null);
    useFocusTrap(el, toRef(props, 'open'));

    return () => h('div', { ref: el }, [
      h('button', { id: 'first' }, 'first'),
      h('input', { id: 'field', 'data-autofocus': '' }),
      h('button', { id: 'last' }, 'last'),
    ]);
  },
});

const nextFrames = () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

describe('useFocusTrap — initial focus', () => {
  it('focuses the element marked data-autofocus, not just the first control', async () => {
    const { container } = render(Harness, { props: { open: true } });

    await nextFrames();

    expect(document.activeElement).toBe(container.querySelector('#field'));
  });

  it('does not steal focus from an element inside the dialog that the user already focused', async () => {
    const { container, rerender } = render(Harness, { props: { open: false } });
    await rerender({ open: true });
    container.querySelector('#last').focus(); // pengguna sudah mengklik sesuatu di dalam dialog

    await nextFrames();

    expect(document.activeElement).toBe(container.querySelector('#last'));
  });
});
