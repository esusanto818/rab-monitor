-- 1. Hapus tabel backup / temporary
DROP TABLE IF EXISTS warehouses00 CASCADE;
DROP TABLE IF EXISTS m_contact_no_00 CASCADE;

-- 2. Hapus tabel master redundan
DROP TABLE IF EXISTS m_bin_location CASCADE;
DROP TABLE IF EXISTS rab_materials CASCADE;

ALTER TABLE employee_absence ALTER COLUMN employee_id TYPE BIGINT;
ALTER TABLE employee_wages ALTER COLUMN employee_id TYPE BIGINT;
ALTER TABLE employee_payroll ALTER COLUMN employee_id TYPE BIGINT;

-- Hapus tabel-tabel modul Payroll
DROP TABLE IF EXISTS employee_payroll_dtl CASCADE;
DROP TABLE IF EXISTS employee_payroll CASCADE;
DROP TABLE IF EXISTS employee_loan_payment CASCADE;
DROP TABLE IF EXISTS employee_loan CASCADE;
DROP TABLE IF EXISTS employee_incentive CASCADE;
DROP TABLE IF EXISTS employee_deduction CASCADE;
DROP TABLE IF EXISTS employee_allowance CASCADE;
DROP TABLE IF EXISTS employee_overtime CASCADE;
DROP TABLE IF EXISTS employee_wages CASCADE;
DROP TABLE IF EXISTS employee_absence_dtl CASCADE;
DROP TABLE IF EXISTS employee_absence CASCADE;


