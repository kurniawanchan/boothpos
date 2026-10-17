# Contract: Printed Documents and the Printer Link

**Feature**: 050-android-thermal-printing | **Classification**: INTERNAL

This is the interface between the server's data, the tablet, and the paper. It has two parts: what each document must contain (and where that data comes from), and what the tablet sends to the printer.

## Part A — Document content

Every document is 58 mm wide. Lines longer than the column count **wrap**; they are never cut (FR-012). Indonesian text (FR-016). No phone or e-mail (FR-015).

### A1. STRUK (customer receipt)

**Source**: `GET /orders/{id}/receipt`.

```text
[store name, centred, bold]
[store address, if present]
--------------------------------
No. struk   <receipt_no>
Tanggal     <paid_at, store time zone>
Kasir       <cashier_name>
--------------------------------
<qty> x <name>                <line_total>
   <modifiers, if any>
   Catatan: <note, if any>
--------------------------------
Subtotal                      <subtotal>
Diskon                       -<discount>     (only if > 0)
<tax_label> <tax_rate>%       <tax>
TOTAL                         <total>        (bold)
--------------------------------
<payment method>              <amount>
Bayar                         <paid>
Kembali                       <change>       (only if > 0)
--------------------------------
SALINAN <copy_no>             (only if copy_no > 1)
```

Notes: `queue_no` is printed only if the server has assigned one (open item 2). Money is shown as whole rupiah, exactly as the server sent it (`ReceiptMapper` does no arithmetic).

### A2. KOT (kitchen order ticket)

**Source**: one `TicketResource` per station per send.

```text
KOT                                  (bold, large, capitals)
<station name>
<table name or tab_label>   <order_number>
Ronde <round_no>   <sent_at, HH:mm>
Dibuat oleh <signed-in PIN user, open item 3>
--------------------------------
<qty> x <name>
   <modifiers, if any>
   Catatan: <note, if any>
--------------------------------
```

**Forbidden on a KOT**: any price, any total, any payment field (FR-014).

If a station is absent (`station: null`), the ticket is labelled "Tanpa stasiun", matching the server's own "langsung siap saji" case.

### A3. PRE-BILL

**Source**: the tab returned by `POST /tabs/{id}/prebill`.

Same layout as A1, with the header line `PRA-BILL — BELUM LUNAS` in place of the receipt number, and with **no** payment or change lines. The tablet's existing wording "tagihan sementara dengan tanda BELUM LUNAS" is kept.

### A4. BATAL slip (kitchen)

**Source**: the ticket of the voided item, and the item snapshot (D9 and research D7).

```text
BATAL                                (bold, large, capitals)
<station name>
<table name or tab_label>   <order_number>
--------------------------------
BATAL  <qty> x <name>
   Alasan: <reason, if any>
--------------------------------
```

## Part B — Printer link

**Transport**: Bluetooth RFCOMM, Serial Port Profile UUID `00001101-0000-1000-8000-00805F9B34FB`. **UNVERIFIED** against the TM-58V manual.

**Byte stream**: ESC/POS. Initialise (`ESC @`), set bold on/off (`ESC E n`), set double height for the KOT/BATAL heading (`GS ! n`), line feed (`LF`), and a feed-and-cut at the end of each document (`GS V`). **UNVERIFIED** that the printer supports the cut command; if not, the tablet feeds a fixed number of lines instead.

**Character set**: the Indonesian text used here is ASCII-safe except for the store's own name, which may contain other characters. Characters outside the printer's code page are replaced with `?`, never sent as raw UTF-8 bytes that could print as garbage.

**Timeouts**: a connect attempt times out after 5 s; a write that stalls for 5 s marks the job `FAILED` with `write_error`. Both values are **to be tuned** on the real device.

## Part C — Error mapping

| Condition | `lastError` | User message (Indonesian) |
|---|---|---|
| No printer chosen | `not_configured` | "Printer belum dipasang" |
| Bluetooth switched off | `bluetooth_off` | "Bluetooth mati" |
| Device not reachable | `out_of_range` | "Printer di luar jangkauan" |
| Printer reports no paper | `paper_out` | "Kertas habis" |
| Write failed mid-job | `write_error` | "Gagal mencetak, coba lagi" |

These messages are the only ones shown; raw Bluetooth exception text is written to the log only, never to the screen (Constitution IV: no internal detail leaks to the user).
