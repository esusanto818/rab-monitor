-- DROP FUNCTION public.if_modified_func();

CREATE OR REPLACE FUNCTION public.if_modified_func()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
begin
    if tg_op = 'UPDATE' then
        insert into data_log_update (table_name, user_name, app_user_name, action, old_values, new_values, updated_cols, query)
        values (tg_table_name::text, current_user::text, current_setting('app.username')
, 'u', hstore(old.*), hstore(new.*),
               akeys(hstore(new.*) - hstore(old.*)), current_query());
        return new;
    elsif tg_op = 'DELETE' then
        insert into data_log_delete (table_name, user_name, app_user_name, action, old_values, query)
        values (tg_table_name::text, current_user::text, current_setting('app.username')
, 'd', hstore(old.*), current_query());
        return old;
    elsif tg_op = 'INSERT' then
        insert into data_log_insert (table_name, user_name, app_user_name, action, new_values,query)
        values (tg_table_name::text, current_user::text, current_setting('app.username')
, 'i', hstore(new.*), current_query());
        return new;
    end if;
end;
$function$
;
