\pset format unaligned
\pset tuples_only on
\pset footer off

WITH table_info AS (
    SELECT 
        t.table_schema,
        t.table_name,
        c.column_name,
        c.ordinal_position,
        c.is_nullable,
        c.column_default,
        c.data_type,
        CASE 
            WHEN c.data_type IN ('character varying', 'character') THEN c.data_type || '(' || c.character_maximum_length || ')'
            WHEN c.data_type = 'numeric' AND c.numeric_precision IS NOT NULL THEN 'numeric(' || c.numeric_precision || ',' || c.numeric_scale || ')'
            ELSE c.data_type
        END AS full_data_type,
        pgd.description AS column_comment
    FROM information_schema.tables t
    JOIN information_schema.columns c 
        ON t.table_schema = c.table_schema AND t.table_name = c.table_name
    LEFT JOIN pg_catalog.pg_statio_all_tables st 
        ON st.schemaname = t.table_schema AND st.relname = t.table_name
    LEFT JOIN pg_catalog.pg_description pgd 
        ON pgd.objoid = st.relid AND pgd.objsubid = c.ordinal_position
    WHERE t.table_schema = 'public' 
      AND t.table_type = 'BASE TABLE'
),
pk_info AS (
    SELECT 
        kcu.table_schema,
        kcu.table_name,
        kcu.column_name
    FROM information_schema.table_constraints tc
    JOIN information_schema.key_column_usage kcu 
        ON tc.constraint_name = kcu.constraint_name 
       AND tc.table_schema = kcu.table_schema
    WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = 'public'
),
fk_info AS (
    SELECT 
        kcu.table_schema,
        kcu.table_name,
        kcu.column_name,
        ccu.table_schema AS foreign_table_schema,
        ccu.table_name AS foreign_table_name,
        ccu.column_name AS foreign_column_name
    FROM information_schema.table_constraints AS tc 
    JOIN information_schema.key_column_usage AS kcu 
        ON tc.constraint_name = kcu.constraint_name 
       AND tc.table_schema = kcu.table_schema
    JOIN information_schema.constraint_column_usage AS ccu 
        ON ccu.constraint_name = tc.constraint_name 
       AND ccu.table_schema = tc.table_schema
    WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'
),
markdown_tables AS (
    SELECT 
        ti.table_name,
        '## Tabel: `' || ti.table_name || '`' || chr(10) || chr(10) ||
        '| Kolom | Tipe Data | Nullable | Key | Default | Keterangan |' || chr(10) ||
        '|---|---|:---:|:---:|---|---|' || chr(10) ||
        STRING_AGG(
            '| `' || ti.column_name || '` | `' || ti.full_data_type || '` | ' || 
            CASE WHEN ti.is_nullable = 'YES' THEN 'YES' ELSE '**NO**' END || ' | ' || 
            CASE 
                WHEN pk.column_name IS NOT NULL AND fk.column_name IS NOT NULL THEN '**PK, FK**'
                WHEN pk.column_name IS NOT NULL THEN '**PK**'
                WHEN fk.column_name IS NOT NULL THEN 'FK'
                ELSE '-'
            END || ' | ' || 
            COALESCE('`' || ti.column_default || '`', '-') || ' | ' || 
            COALESCE(ti.column_comment, '-') || ' |',
            chr(10) ORDER BY ti.ordinal_position
        ) || chr(10) || chr(10) AS table_md
    FROM table_info ti
    LEFT JOIN pk_info pk 
        ON ti.table_schema = pk.table_schema AND ti.table_name = pk.table_name AND ti.column_name = pk.column_name
    LEFT JOIN fk_info fk 
        ON ti.table_schema = fk.table_schema AND ti.table_name = fk.table_name AND ti.column_name = fk.column_name
    GROUP BY ti.table_schema, ti.table_name
),
mermaid_er AS (
    SELECT 
        '## Diagram Relasi (ERD)' || chr(10) || chr(10) ||
        '```mermaid' || chr(10) ||
        'erDiagram' || chr(10) ||
        COALESCE(
            STRING_AGG(
                '    ' || foreign_table_name || ' ||--o{ ' || table_name || ' : "' || column_name || ' -> ' || foreign_column_name || '"',
                chr(10)
            ),
            '    %% Tidak ada relasi Foreign Key'
        ) || chr(10) ||
        '```' || chr(10) || chr(10) AS mermaid_md
    FROM fk_info
)
SELECT 
    '# Dokumentasi Database: `' || current_database() || '`' || chr(10) || chr(10) ||
    '**Tanggal Digenerate:** ' || TO_CHAR(NOW(), 'YYYY-MM-DD HH24:MI:SS') || chr(10) || chr(10) ||
    '---' || chr(10) || chr(10) ||
    (SELECT mermaid_md FROM mermaid_er) ||
    '---' || chr(10) || chr(10) ||
    '## Daftar Tabel' || chr(10) || chr(10) ||
    STRING_AGG(table_md, chr(10) || '---' || chr(10) || chr(10) ORDER BY table_name)
FROM markdown_tables;
