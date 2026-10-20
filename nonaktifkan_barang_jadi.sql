-- nonaktifkan_barang_jadi.sql
BEGIN;
DO $$
DECLARE v_id bigint; v_n int;
BEGIN
  SELECT id INTO v_id FROM chart_of_accounts WHERE account_code = '1300';
  IF v_id IS NULL THEN RAISE NOTICE 'Akun 1300 tidak ditemukan'; RETURN; END IF;
  SELECT COUNT(*) INTO v_n FROM journal_lines WHERE account_id = v_id;
  IF v_n > 0 THEN RAISE EXCEPTION 'Akun 1300 dipakai % baris jurnal; tidak dinonaktifkan.', v_n; END IF;
  -- aturan posting yang memakai akun ini ikut dinonaktifkan (mis. COGS: Dr 5100 / Cr 1300)
  UPDATE fiscal_config SET is_active = false WHERE debit_account_id = v_id OR credit_account_id = v_id;
  UPDATE chart_of_accounts SET is_active = false WHERE id = v_id;
END $$;
COMMIT;

-- Untuk membatalkan (mengaktifkan lagi):
--   UPDATE chart_of_accounts SET is_active = true WHERE account_code = '1300';
--   UPDATE fiscal_config SET is_active = true WHERE rule_code = 'COGS';
