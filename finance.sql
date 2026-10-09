-- =========================================================
-- 1. TABEL FINANCIAL_INCOMES & TRIGGER
-- =========================================================
CREATE TABLE IF NOT EXISTS public.financial_incomes (
    id SERIAL PRIMARY KEY,
    income_no VARCHAR(50) NOT NULL UNIQUE,
    income_date DATE NOT NULL DEFAULT CURRENT_DATE,
    project_id INT REFERENCES public.projects(id) ON DELETE SET NULL,
    customer_id INT REFERENCES public.m_customer(id) ON DELETE SET NULL,
    account_id INT,
    category VARCHAR(100) DEFAULT 'Pendapatan Proyek',
    amount NUMERIC(18, 2) NOT NULL DEFAULT 0.00,
    reference_no VARCHAR(100),
    payment_method VARCHAR(50) DEFAULT 'BANK_TRANSFER',
    status VARCHAR(30) DEFAULT 'PAID',
    notes TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Function untuk Update Modtime
CREATE OR REPLACE FUNCTION update_financial_incomes_modtime()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Drop Trigger jika ada (Abaikan Notice jika belum pernah dibuat)
DROP TRIGGER IF EXISTS trg_update_financial_incomes_modtime ON public.financial_incomes;

-- Buat Trigger dengan Sintaks EXECUTE PROCEDURE (Kompatibel untuk PostgreSQL)
CREATE TRIGGER trg_update_financial_incomes_modtime
BEFORE UPDATE ON public.financial_incomes
FOR EACH ROW
EXECUTE PROCEDURE update_financial_incomes_modtime();


-- =========================================================
-- 2. TABEL FINANCIAL_EXPENSES & TRIGGER
-- =========================================================
CREATE TABLE IF NOT EXISTS public.financial_expenses (
    id SERIAL PRIMARY KEY,
    expense_no VARCHAR(50) NOT NULL UNIQUE,
    expense_date DATE NOT NULL DEFAULT CURRENT_DATE,
    project_id INT REFERENCES public.projects(id) ON DELETE SET NULL,
    supplier_id INT REFERENCES public.m_suppliers(id) ON DELETE SET NULL,
    account_id INT,
    category VARCHAR(100) DEFAULT 'Beban Operasional',
    amount NUMERIC(18, 2) NOT NULL DEFAULT 0.00,
    reference_no VARCHAR(100),
    payment_method VARCHAR(50) DEFAULT 'BANK_TRANSFER',
    status VARCHAR(30) DEFAULT 'PAID',
    notes TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Function untuk Update Modtime
CREATE OR REPLACE FUNCTION update_financial_expenses_modtime()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Drop Trigger jika ada
DROP TRIGGER IF EXISTS trg_update_financial_expenses_modtime ON public.financial_expenses;

-- Buat Trigger
CREATE TRIGGER trg_update_financial_expenses_modtime
BEFORE UPDATE ON public.financial_expenses
FOR EACH ROW
EXECUTE PROCEDURE update_financial_expenses_modtime();
