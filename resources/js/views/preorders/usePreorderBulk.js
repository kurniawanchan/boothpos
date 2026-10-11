import { h, render, ref, getCurrentInstance } from 'vue';
import { useI18n } from 'vue-i18n';
import { bulkPreorderInvoices, bulkEmailPreorderInvoices } from '../../api/preorders';
import { useToastStore } from '../../stores/toast';
import { captureElementCanvas } from '../../utils/pdfCapture';
import { buildShippingSlipHtml } from '../../utils/invoiceDocument';
import PreorderInvoiceDocument from '../../components/preorder/PreorderInvoiceDocument.vue';
import PreorderPaymentDocument from '../../components/preorder/PreorderPaymentDocument.vue';

/**
 * Aksi massal pre-order (022-preorder-invoice-crud-overhaul US5): unduh invoice / invoice pembayaran / surat
 * jalan sebagai ZIP berisi PDF, dan kirim email. Dipindahkan apa adanya dari PreordersView.vue (041) —
 * tidak ada perubahan perilaku. Harus dipanggil di dalam setup() karena memerlukan appContext vue-i18n.
 *
 * @param {import('vue').Ref<number[]>} selectedIds id pre-order terpilih (dimiliki pemanggil)
 */
export function usePreorderBulk(selectedIds) {
  const toast = useToastStore();
  const { t } = useI18n();

  const bulkDocumentType = ref('invoice');
  const bulkDownloading = ref(false);
  const bulkEmailing = ref(false);

  // 029-fix-bulk-invoice-logo — unduh massal merender KOMPONEN yang sama dengan
  // modalnya (PreorderInvoiceDocument / PreorderPaymentDocument), jadi PDF-nya
  // identik dengan dokumen di layar (logo/identitas toko, header dua kolom,
  // kartu "Cara pembayaran"). appContext diteruskan agar vue-i18n tersedia di
  // render terpisah ini. Lebar = lebar dokumen di modal 720px: invoice berada
  // di dalam padding px-6 (672px), payment invoice membawa paddingnya sendiri.
  const appContext = getCurrentInstance().appContext;
  const BULK_DOCUMENTS = {
    invoice: { component: PreorderInvoiceDocument, width: 672 },
    payment_invoice: { component: PreorderPaymentDocument, width: 720 },
  };
  function mountBulkDocument(invoice, documentType) {
    const { component, width } = BULK_DOCUMENTS[documentType];
    const container = document.createElement('div');
    container.style.position = 'fixed';
    container.style.left = '-9999px';
    container.style.width = `${width}px`;
    document.body.appendChild(container);
    const vnode = h(component, { invoice });
    vnode.appContext = appContext;
    render(vnode, container);
    return { container, dispose: () => { render(null, container); container.remove(); } };
  }

  function saveZip(zipBlob, filename) {
    const url = URL.createObjectURL(zipBlob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  async function doBulkDownload() {
    const ids = [...selectedIds.value];
    if (!ids.length) return;
    bulkDownloading.value = true;
    try {
      const { data: invoices } = await bulkPreorderInvoices(ids, bulkDocumentType.value);
      const [{ default: JSZip }, { jsPDF }] = await Promise.all([
        import('jszip'), import('jspdf'),
      ]);
      const zip = new JSZip();

      // Sequential, not parallel — rendering N canvases at once would freeze
      // the tab for a realistic batch size (plan.md Performance Goals).
      for (const invoice of invoices) {
        const { container, dispose } = mountBulkDocument(invoice, bulkDocumentType.value);
        let canvas;
        try {
          canvas = await captureElementCanvas(container);
        } finally {
          dispose();
        }

        const imgData = canvas.toDataURL('image/png');
        const widthPt = (canvas.width * 72) / 96;
        const heightPt = (canvas.height * 72) / 96;
        const pdf = new jsPDF({ orientation: heightPt >= widthPt ? 'portrait' : 'landscape', unit: 'pt', format: [widthPt, heightPt] });
        pdf.addImage(imgData, 'PNG', 0, 0, widthPt, heightPt);
        zip.file(`${bulkDocumentType.value}-${invoice.preorder_number}.pdf`, pdf.output('blob'));
      }

      saveZip(await zip.generateAsync({ type: 'blob' }), `preorder-${bulkDocumentType.value}s.zip`);
    } catch (err) {
      toast.error(err.message || t('preorders.bulk_download_failed'));
    } finally {
      bulkDownloading.value = false;
    }
  }

  async function doBulkEmail() {
    const ids = [...selectedIds.value];
    if (!ids.length) return;
    bulkEmailing.value = true;
    try {
      const { data: results } = await bulkEmailPreorderInvoices(ids, bulkDocumentType.value);
      const sentCount = results.filter((r) => r.status === 'sent').length;
      const skippedCount = results.length - sentCount;
      toast.success(t('preorders.bulk_email_summary', { sent: sentCount, skipped: skippedCount }));
    } catch (err) {
      toast.error(err.message || t('preorders.bulk_email_failed'));
    } finally {
      bulkEmailing.value = false;
    }
  }

  // 024-invoice-layout-shipping-slip (US7) — bulk unduh surat jalan (ZIP).
  async function doBulkShippingSlips() {
    const ids = [...selectedIds.value];
    if (!ids.length) return;
    bulkDownloading.value = true;
    try {
      const { data: invoices } = await bulkPreorderInvoices(ids, 'invoice');
      const [{ default: JSZip }, { jsPDF }] = await Promise.all([
        import('jszip'), import('jspdf'),
      ]);
      const zip = new JSZip();
      for (const invoice of invoices) {
        const container = document.createElement('div');
        container.style.position = 'fixed';
        container.style.left = '-9999px';
        container.innerHTML = buildShippingSlipHtml(invoice);
        document.body.appendChild(container);
        const canvas = await captureElementCanvas(container);
        document.body.removeChild(container);

        const imgData = canvas.toDataURL('image/png');
        const widthPt = (canvas.width * 72) / 96;
        const heightPt = (canvas.height * 72) / 96;
        const pdf = new jsPDF({ orientation: heightPt >= widthPt ? 'portrait' : 'landscape', unit: 'pt', format: [widthPt, heightPt] });
        pdf.addImage(imgData, 'PNG', 0, 0, widthPt, heightPt);
        zip.file(`surat-jalan-${invoice.preorder_number}.pdf`, pdf.output('blob'));
      }

      saveZip(await zip.generateAsync({ type: 'blob' }), 'surat-jalan.zip');
    } catch (err) {
      toast.error(err.message || t('preorders.bulk_download_failed'));
    } finally {
      bulkDownloading.value = false;
    }
  }

  return { bulkDocumentType, bulkDownloading, bulkEmailing, doBulkDownload, doBulkEmail, doBulkShippingSlips };
}
