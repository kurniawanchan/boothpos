import { getCurrentInstance, h, ref, render } from 'vue';
import { bulkReceipts } from '../api/orders';
import { captureElementCanvas } from '../utils/pdfCapture';
import { saveBlob } from '../utils/saveBlob';
import ReceiptDocument from '../components/receipt/ReceiptDocument.vue';

/**
 * 054 (US9) — "Unduh semua struk": satu zip berisi satu PDF per penjualan. Isi struk datang dari server
 * (POST /orders/bulk-receipts, perakit yang SAMA dengan struk satuan); PDF dirender di browser dari
 * komponen ReceiptDocument yang sama dengan struk di layar, dipasang di luar layar satu per satu
 * (berurutan, bukan paralel: merasterisasi ratusan kanvas sekaligus akan membekukan tab). jszip/jspdf
 * dimuat dinamis supaya tak membengkakkan bundel awal. Pola yang sama dengan unduh massal invoice pre-order.
 *
 * Satu struk yang gagal dirender TIDAK menggagalkan sisanya dan tidak dibuang diam-diam: jumlahnya
 * dilaporkan. Hasil `{ ok, failed, missing }` dikembalikan supaya pemanggil memutuskan pesannya.
 */
export const MAX_BULK_RECEIPTS = 200;
// Lebar struk di layar (modal 430px); dokumen membawa paddingnya sendiri.
const RECEIPT_WIDTH = 430;

export function useBulkReceipts() {
  const appContext = getCurrentInstance()?.appContext;
  const running = ref(false);
  const progress = ref({ done: 0, total: 0 });

  function mount(receipt) {
    const container = document.createElement('div');
    container.style.position = 'fixed';
    container.style.left = '-9999px';
    container.style.width = `${RECEIPT_WIDTH}px`;
    document.body.appendChild(container);
    const vnode = h(ReceiptDocument, { receipt });
    if (appContext) vnode.appContext = appContext; // supaya komponen anak mendapat plugin (mis. i18n)
    render(vnode, container);
    return { container, dispose: () => { render(null, container); container.remove(); } };
  }

  /** @returns {Promise<{ok:number, failed:number, missing:number}>} */
  async function download(orderIds, filename) {
    running.value = true;
    progress.value = { done: 0, total: orderIds.length };
    try {
      const { data: receipts, missing = [] } = await bulkReceipts(orderIds);
      const [{ default: JSZip }, { jsPDF }] = await Promise.all([import('jszip'), import('jspdf')]);
      const zip = new JSZip();
      let ok = 0;
      let failed = 0;

      for (const receipt of receipts) {
        const { container, dispose } = mount(receipt);
        try {
          const canvas = await captureElementCanvas(container);
          const widthPt = (canvas.width * 72) / 96;
          const heightPt = (canvas.height * 72) / 96;
          const pdf = new jsPDF({ orientation: heightPt >= widthPt ? 'portrait' : 'landscape', unit: 'pt', format: [widthPt, heightPt] });
          pdf.addImage(canvas.toDataURL('image/png'), 'PNG', 0, 0, widthPt, heightPt);
          zip.file(`struk-${receipt.order_number}.pdf`, pdf.output('blob'));
          ok += 1;
        } catch {
          failed += 1;
        } finally {
          dispose();
          progress.value = { done: progress.value.done + 1, total: progress.value.total };
        }
      }

      if (ok > 0) saveBlob(await zip.generateAsync({ type: 'blob' }), filename);

      return { ok, failed, missing: missing.length };
    } finally {
      running.value = false;
    }
  }

  return { running, progress, download };
}
