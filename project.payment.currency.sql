-- Migrasi: mata uang pada Pembayaran Termin (project_payment.js / project_paymentCRD.php)
-- Aman dijalankan ulang (IF NOT EXISTS). Jalankan di dalam transaksi.
BEGIN;

-- Header pembayaran: mata uang, kurs ke IDR, dan nilai asli yang diterima
ALTER TABLE public.project_payments
    ADD COLUMN IF NOT EXISTS currency_id      bigint REFERENCES public.m_currency(id),
    ADD COLUMN IF NOT EXISTS exch_rate        numeric(15,4) NOT NULL DEFAULT 1.0000,
    ADD COLUMN IF NOT EXISTS total_amount_cur numeric(18,2);

-- Item pembayaran: nilai bayar asli dalam mata uang pembayaran (amount_paid tetap IDR)
ALTER TABLE public.project_payment_items
    ADD COLUMN IF NOT EXISTS amount_cur numeric(18,2);

-- Backfill data lama: semua pembayaran lama dianggap IDR, kurs 1
UPDATE public.project_payments
   SET currency_id      = (SELECT id FROM public.m_currency WHERE UPPER(TRIM(init)) = 'IDR' LIMIT 1),
       total_amount_cur = total_amount
 WHERE currency_id IS NULL;

UPDATE public.project_payment_items
   SET amount_cur = amount_paid
 WHERE amount_cur IS NULL;

CREATE INDEX IF NOT EXISTS idx_project_payments_currency ON public.project_payments (currency_id);

COMMIT;
