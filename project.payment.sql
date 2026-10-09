-- ============================================================
-- project.payment.sql
-- Pendukung modul Pembayaran Termin per Proyek.
-- Tabel project_invoices / project_payments / project_payment_items sudah ada (tidak perlu DDL baru).
-- ============================================================

-- 1) Index untuk query detail / reverse pembayaran
CREATE INDEX IF NOT EXISTS idx_ppi_payment ON public.project_payment_items (payment_id);
CREATE INDEX IF NOT EXISTS idx_ppi_invoice ON public.project_payment_items (invoice_id);
CREATE INDEX IF NOT EXISTS idx_pinv_project ON public.project_invoices (project_id);

-- 2) Nomor unik (jalankan hanya bila belum ada duplikat; bila error, cek data lama dulu)
CREATE UNIQUE INDEX IF NOT EXISTS ux_project_payments_no ON public.project_payments (payment_no);
CREATE UNIQUE INDEX IF NOT EXISTS ux_project_invoices_no ON public.project_invoices (inv_no);

-- 3) Registrasi menu (SESUAIKAN <GROUP_ID> dengan id grup menu Keuangan/Proyek di m_form_group)
INSERT INTO public.m_form_name (group_id, seq, title, php_url, js_url, main_table, active)
VALUES (<GROUP_ID>, '99', 'Pembayaran Termin', 'project.payment.php', 'project.payment.js', 'project_payments', true);

-- 4) Hak akses: 2 = boleh input, 3 = boleh hapus. Ganti <USER_ID>.
-- INSERT INTO public.m_user_access_menu (user_id, form_id, access_level)
-- SELECT <USER_ID>, id, 3 FROM public.m_form_name WHERE php_url = 'project.payment.php';
