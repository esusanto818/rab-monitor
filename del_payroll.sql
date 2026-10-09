-- 1. Hapus Modul Penggajian & THR
DROP TABLE IF EXISTS employee_payroll_dtl_close CASCADE;
DROP TABLE IF EXISTS employee_payroll_hdr_close CASCADE;
DROP TABLE IF EXISTS employee_payroll_dtl CASCADE;
DROP TABLE IF EXISTS employee_payroll_hdr CASCADE;
DROP TABLE IF EXISTS employee_thr_dtl CASCADE;
DROP TABLE IF EXISTS employee_thr CASCADE;

-- 2. Hapus Modul Absensi & Kehadiran
DROP TABLE IF EXISTS employee_absence_dtl CASCADE;
DROP TABLE IF EXISTS employee_absence_hdr CASCADE;
DROP TABLE IF EXISTS employee_attendance CASCADE;
DROP TABLE IF EXISTS employee_overtime CASCADE;
DROP TABLE IF EXISTS employee_leave CASCADE;
BEGIN;

-- 1. Hapus tabel terkait Presensi & Penggajian Karyawan
DROP TABLE IF EXISTS employee_wages_periode CASCADE;
DROP TABLE IF EXISTS employee_slip_periode CASCADE;
DROP TABLE IF EXISTS employee_absence_detail_period CASCADE;
DROP TABLE IF EXISTS employee_absence_header_period CASCADE;

-- 2. Hapus tabel terkait Tunjangan, Potongan, & Lembur Tambahan
DROP TABLE IF EXISTS employee_addtn_allowance CASCADE;
DROP TABLE IF EXISTS employee_addtn_instalment CASCADE;
DROP TABLE IF EXISTS employee_addtn_ot CASCADE;

-- 3. Hapus tabel terkait Riwayat Gaji & Struktur Jabatan/Departemen
DROP TABLE IF EXISTS employee_sal CASCADE;
DROP TABLE IF EXISTS employee_department CASCADE;
DROP TABLE IF EXISTS employee_position CASCADE;
DROP TABLE IF EXISTS m_employee_position CASCADE;
DROP TABLE IF EXISTS department_section CASCADE;
DROP TABLE IF EXISTS m_department CASCADE;

DROP TABLE IF EXISTS m_employee_status CASCADE;
DROP TABLE IF EXISTS m_employee_instalment CASCADE;
DROP TABLE IF EXISTS m_employee_allowance CASCADE;
DROP TABLE IF EXISTS employee_status CASCADE;
DROP TABLE IF EXISTS employee_section CASCADE;
DROP TABLE IF EXISTS employee_section_line CASCADE;
DROP TABLE IF EXISTS employee_rekening_mayapada CASCADE;
DROP TABLE IF EXISTS employee_premi_062018 CASCADE;
DROP TABLE IF EXISTS employee_new_rek1 CASCADE;
DROP TABLE IF EXISTS employee_instalment CASCADE;
DROP TABLE IF EXISTS employee_addtn_ot_h CASCADE;
DROP TABLE IF EXISTS backup_employee_section CASCADE;
DROP TABLE IF EXISTS backup_employee_department CASCADE;







COMMIT;
/*
ALTER TABLE m_employee 
  DROP COLUMN IF EXISTS basic_salary,
  DROP COLUMN IF EXISTS salary_type,
  DROP COLUMN IF EXISTS bank_account_no,
  DROP COLUMN IF EXISTS bank_name,
  DROP COLUMN IF EXISTS allowance_fixed,
  DROP COLUMN IF EXISTS tax_status;
*/
