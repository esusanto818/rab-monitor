-- SPK berbasis Task Proyek: migration idempotent untuk PostgreSQL.
-- Jalankan pada database target setelah backup. Tidak menghapus data SPK/material yang sudah ada.

ALTER TABLE public.spk
    ADD COLUMN IF NOT EXISTS workflow_status VARCHAR(40) NOT NULL DEFAULT 'Draft',
    ADD COLUMN IF NOT EXISTS approved_at TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS approved_by INTEGER NULL;

-- Pisahkan status alur kerja dari status material lama agar warehouse issuing tetap kompatibel.
UPDATE public.spk
SET workflow_status = CASE
    WHEN status = 'Diterbitkan' THEN 'Pelaksanaan'
    WHEN status = 'Selesai' THEN 'Selesai'
    WHEN status = 'Dibatalkan' THEN 'Dibatalkan'
    ELSE COALESCE(workflow_status, 'Draft')
END
WHERE workflow_status IS NULL OR workflow_status = 'Draft';

CREATE TABLE IF NOT EXISTS public.spk_tasks (
    id BIGSERIAL PRIMARY KEY,
    spk_id INTEGER NOT NULL REFERENCES public.spk(id) ON DELETE CASCADE,
    task_id INTEGER NOT NULL REFERENCES public.project_tasks(id) ON DELETE RESTRICT,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_spk_tasks UNIQUE (spk_id, task_id)
);

CREATE INDEX IF NOT EXISTS ix_spk_tasks_task_id ON public.spk_tasks(task_id);
CREATE INDEX IF NOT EXISTS ix_spk_workflow_status ON public.spk(workflow_status);

CREATE TABLE IF NOT EXISTS public.spk_workflow_log (
    id BIGSERIAL PRIMARY KEY,
    spk_id INTEGER NOT NULL REFERENCES public.spk(id) ON DELETE CASCADE,
    from_status VARCHAR(40) NULL,
    to_status VARCHAR(40) NOT NULL,
    action VARCHAR(60) NOT NULL,
    note TEXT NULL,
    user_id INTEGER NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_spk_workflow_log_spk ON public.spk_workflow_log(spk_id, created_at);
