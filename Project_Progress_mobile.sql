-- 2026-10-13 : Project Progress (mobile, per proyek + foto + offline sync)
-- client_uuid dibuat di HP; UNIQUE agar kiriman ulang (sinyal putus) tidak membuat data ganda.

BEGIN;

CREATE TABLE IF NOT EXISTS public.project_progress (
    id            bigserial PRIMARY KEY,
    project_id    integer      NOT NULL REFERENCES public.projects(id),
    progress_date date         NOT NULL DEFAULT CURRENT_DATE,
    percent       numeric(5,2) NOT NULL CHECK (percent >= 0 AND percent <= 100),
    notes         text,
    latitude      numeric(9,6),
    longitude     numeric(9,6),
    client_uuid   varchar(64)  NOT NULL UNIQUE,
    created_by    integer,
    created_at    timestamp without time zone NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_project_progress_prj_date
    ON public.project_progress (project_id, progress_date DESC, id DESC);

CREATE TABLE IF NOT EXISTS public.project_progress_photos (
    id          bigserial PRIMARY KEY,
    progress_id bigint       NOT NULL REFERENCES public.project_progress(id) ON DELETE CASCADE,
    file_name   varchar(100) NOT NULL,   -- nama file di disk (acak), bukan nama asli
    file_size   integer,
    created_at  timestamp without time zone NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_project_progress_photos_prg
    ON public.project_progress_photos (progress_id);

COMMIT;
