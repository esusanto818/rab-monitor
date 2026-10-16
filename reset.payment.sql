-- ============================================================
-- Reset data trial pembayaran termin PPAY-2610-00001
-- Mengembalikan paid_amount termin, menghapus alokasi, jurnal (bila ada), lalu header pembayaran.
-- Jalankan sekali; semua dalam satu transaksi.
-- ============================================================
BEGIN;

-- 1) Kembalikan paid_amount tiap termin sesuai alokasi pembayaran ini
UPDATE public.project_invoices v
   SET paid_amount = GREATEST(v.paid_amount - i.amount_paid, 0)
  FROM public.project_payment_items i
  JOIN public.project_payments p ON p.id = i.payment_id
 WHERE i.invoice_id = v.id AND p.payment_no = 'PPAY-2610-00001';

-- 2) Hapus jurnal yang terkait (general_ledger -> journal_lines -> journal_entries); aman bila tidak ada
DELETE FROM public.general_ledger
 WHERE journal_line_id IN (
       SELECT l.id FROM public.journal_lines l
         JOIN public.journal_entries j ON j.id = l.journal_id
        WHERE j.reference_type::text = 'project_payment'
          AND j.reference_id IN (SELECT id FROM public.project_payments WHERE payment_no = 'PPAY-2610-00001'));

DELETE FROM public.journal_lines
 WHERE journal_id IN (
       SELECT j.id FROM public.journal_entries j
        WHERE j.reference_type::text = 'project_payment'
          AND j.reference_id IN (SELECT id FROM public.project_payments WHERE payment_no = 'PPAY-2610-00001'));

DELETE FROM public.journal_entries
 WHERE reference_type::text = 'project_payment'
   AND reference_id IN (SELECT id FROM public.project_payments WHERE payment_no = 'PPAY-2610-00001');

-- 3) Hapus alokasi & header pembayaran
DELETE FROM public.project_payment_items
 WHERE payment_id IN (SELECT id FROM public.project_payments WHERE payment_no = 'PPAY-2610-00001');
DELETE FROM public.project_payments WHERE payment_no = 'PPAY-2610-00001';

-- 4) Verifikasi (harus: pembayaran 0 baris, paid_amount semua termin 0)
SELECT COUNT(*) AS sisa_pembayaran FROM public.project_payments WHERE payment_no = 'PPAY-2610-00001';
SELECT inv_no, amount, paid_amount FROM public.project_invoices ORDER BY id;

COMMIT;   -- bila hasil verifikasi tidak sesuai, ganti dengan ROLLBACK;
