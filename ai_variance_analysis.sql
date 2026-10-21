-- file: sql/2026-10-21.ai_variance_analysis.sql
-- Tabel log untuk menyimpan hasil analisis varian anggaran proyek oleh AI
CREATE TABLE IF NOT EXISTS public.ai_variance_logs (
    id SERIAL PRIMARY KEY,
    project_id INT NOT NULL,
    total_budget NUMERIC(15, 2) NOT NULL DEFAULT 0,
    total_actual NUMERIC(15, 2) NOT NULL DEFAULT 0,
    variance_amount NUMERIC(15, 2) NOT NULL DEFAULT 0,
    variance_percentage NUMERIC(5, 2) NOT NULL DEFAULT 0,
    risk_level VARCHAR(20) CHECK (risk_level IN ('LOW', 'MEDIUM', 'HIGH', 'CRITICAL')),
    ai_summary TEXT,
    recommendations JSONB,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ai_variance_project FOREIGN KEY (project_id) REFERENCES public.projects(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_ai_variance_logs_project_id ON public.ai_variance_logs(project_id);
