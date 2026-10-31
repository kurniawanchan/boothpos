import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { deleteVariantPermanent } from '../api/products';
import { useToastStore } from '../stores/toast';

/**
 * 2026-10-31 (permintaan pemilik) — hapus varian PERMANEN dari laci Ubah item dan laci Varian & harga. Server hanya
 * menghapus varian tanpa riwayat; 409 (`variant_in_use`, `last_variant`) ditoast handler galat pusat dan barisnya
 * tetap ada (pengguna masih bisa menonaktifkannya). Satu alur untuk dua laci, supaya konfirmasi dan pesannya sama.
 *
 * @param {(variant: object) => void} onDeleted dipanggil setelah server menghapus (buang baris dari tabel)
 */
export function useVariantDelete(onDeleted) {
  const { t } = useI18n();
  const toast = useToastStore();
  const target = ref(null);
  const deleting = ref(false);

  async function confirm() {
    const variant = target.value;
    if (!variant) return;
    deleting.value = true;
    try {
      await deleteVariantPermanent(variant.id);
      toast.success(t('products.variant_deleted'));
      onDeleted(variant);
    } catch {
      // 409/403 sudah ditoast handler pusat; dialog ditutup supaya pengguna tidak terjebak.
    } finally {
      target.value = null;
      deleting.value = false;
    }
  }

  return { target, deleting, ask: (variant) => { target.value = variant; }, cancel: () => { target.value = null; }, confirm };
}
