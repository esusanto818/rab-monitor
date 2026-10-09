#!/bin/bash

FILE="checklist.md"

if [ ! -f "$FILE" ]; then
    echo "Error: $FILE not found!"
    exit 1
fi

CURRENT_FILTER="all" # Pilihan: all, pending, completed

show_list() {
    clear
    echo "=== INTERACTIVE CHECKLIST ==="
    echo "Filter: [ $CURRENT_FILTER ]"
    echo "============================="
    
    local i=1
    local display_count=0
    
    while IFS= read -r line; do
        if [[ "$line" =~ ^-\ \[([x\ ])\]\ (.*)$ ]]; then
            status="${BASH_REMATCH[1]}"
            full_content="${BASH_REMATCH[2]}"
            
            task="$full_content"
            target=""
            finish=""

            # Ekstrak Target dan Finish
            if [[ "$full_content" =~ (.*)[[:space:]]+\|[[:space:]]+Target:[[:space:]]+([0-9\-]+)[[:space:]]+\|[[:space:]]+Finish:[[:space:]]+([0-9\-]+) ]]; then
                task="${BASH_REMATCH[1]}"
                target="${BASH_REMATCH[2]}"
                finish="${BASH_REMATCH[3]}"
            elif [[ "$full_content" =~ (.*)[[:space:]]+\|[[:space:]]+Target:[[:space:]]+([0-9\-]+) ]]; then
                task="${BASH_REMATCH[1]}"
                target="${BASH_REMATCH[2]}"
            elif [[ "$full_content" =~ (.*)[[:space:]]+\|[[:space:]]+Finish:[[:space:]]+([0-9\-]+) ]]; then
                task="${BASH_REMATCH[1]}"
                finish="${BASH_REMATCH[2]}"
            fi
            
            task=$(echo "$task" | sed 's/[[:space:]]*$//')

            # Tentukan apakah baris ini akan ditampilkan berdasarkan filter
            local skip=0
            if [ "$CURRENT_FILTER" == "pending" ] && [ "$status" == "x" ]; then
                skip=1
            elif [ "$CURRENT_FILTER" == "completed" ] && [ "$status" == " " ]; then
                skip=1
            fi

            if [ $skip -eq 0 ]; then
                target_str=""
                [ -n "$target" ] && target_str=" | Target: $target"
                
                finish_str=""
                diff_str=""
                if [ -n "$finish" ]; then
                    finish_str=" | Finish: $finish"
                    if [ -n "$target" ]; then
                        t_sec=$(date -d "$target" +%s 2>/dev/null)
                        f_sec=$(date -d "$finish" +%s 2>/dev/null)
                        if [ -n "$t_sec" ] && [ -n "$f_sec" ]; then
                            diff=$(( (f_sec - t_sec) / 86400 ))
                            if [ $diff -eq 0 ]; then
                                diff_str=" \e[36m(On time)\e[0m"
                            elif [ $diff -lt 0 ]; then
                                diff_str=" \e[32m($(( -diff ))d early)\e[0m"
                            else
                                diff_str=" \e[31m(${diff}d late)\e[0m"
                            fi
                        fi
                    fi
                fi

                if [ "$status" == "x" ]; then
                    echo -e "$i) \e[32m[✓] $task\e[0m\e[90m${target_str}${finish_str}\e[0m${diff_str}"
                else
                    echo -e "$i) \e[31m[ ] $task\e[0m\e[90m${target_str}\e[0m"
                fi
                ((display_count++))
            fi
            ((i++))
        fi
    done < "$FILE"
    
    if [ $display_count -eq 0 ]; then
        echo -e "\e[90m(Tidak ada task pada filter ini)\e[0m"
    fi
    echo "============================="
}

toggle_task() {
    local target_num=$1
    local current_num=1
    local updated_content=""
    local today
    today=$(date +%Y-%m-%d)

    while IFS= read -r line; do
        if [[ "$line" =~ ^-\ \[([x\ ])\]\ (.*)$ ]]; then
            if [ "$current_num" -eq "$target_num" ]; then
                status="${BASH_REMATCH[1]}"
                full_content="${BASH_REMATCH[2]}"
                
                task="$full_content"
                target=""

                if [[ "$full_content" =~ (.*)[[:space:]]+\|[[:space:]]+Target:[[:space:]]+([0-9\-]+) ]]; then
                    task="${BASH_REMATCH[1]}"
                    target="${BASH_REMATCH[2]}"
                fi
                
                task=$(echo "$task" | sed 's/[[:space:]]*$//')
                target_part=""
                [ -n "$target" ] && target_part=" | Target: $target"

                if [ "$status" == "x" ]; then
                    updated_content+="- [ ] $task${target_part}\n"
                else
                    updated_content+="- [x] $task${target_part} | Finish: $today\n"
                fi
            else
                updated_content+="$line\n"
            fi
            ((current_num++))
        else
            updated_content+="$line\n"
        fi
    done < "$FILE"

    echo -e -n "$updated_content" > "$FILE"
}

add_task() {
    echo -n "Masukkan nama task baru: "
    read -r new_task
    if [ -z "$new_task" ]; then
        echo "Task tidak boleh kosong!"
        sleep 1
        return
    fi

    echo -n "Masukkan Target Date (YYYY-MM-DD) [Kosongkan jika tidak ada]: "
    read -r new_target

    local new_line="- [ ] $new_task"
    if [[ "$new_target" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
        new_line="$new_line | Target: $new_target"
    fi

    echo -e "$new_line" >> "$FILE"
    echo "Task berhasil ditambahkan!"
    sleep 1
}

delete_task() {
    local target_num=$1
    local current_num=1
    local updated_content=""
    local found=0

    while IFS= read -r line; do
        if [[ "$line" =~ ^-\ \[([x\ ])\]\ (.*)$ ]]; then
            if [ "$current_num" -eq "$target_num" ]; then
                found=1
            else
                updated_content+="$line\n"
            fi
            ((current_num++))
        else
            updated_content+="$line\n"
        fi
    done < "$FILE"

    if [ $found -eq 1 ]; then
        echo -e -n "$updated_content" > "$FILE"
        echo "Task nomor $target_num berhasil dihapus!"
    else
        echo "Nomor task tidak valid!"
    fi
    sleep 1
}

while true; do
    show_list
    echo "Menu: [Nomor] Toggle | [a] Tambah | [d Nomor] Hapus | [f] Filter | [q] Keluar"
    echo -n "Pilihan Anda: "
    read -r choice

    if [ "$choice" == "q" ]; then
        echo "Keluar..."
        break
    elif [ "$choice" == "a" ]; then
        add_task
    elif [ "$choice" == "f" ]; then
        if [ "$CURRENT_FILTER" == "all" ]; then
            CURRENT_FILTER="pending"
        elif [ "$CURRENT_FILTER" == "pending" ]; then
            CURRENT_FILTER="completed"
        else
            CURRENT_FILTER="all"
        fi
    elif [[ "$choice" =~ ^d[[:space:]]+([0-9]+)$ ]]; then
        delete_task "${BASH_REMATCH[1]}"
    elif [[ "$choice" =~ ^[0-9]+$ ]]; then
        toggle_task "$choice"
    else
        echo "Input tidak valid. Tekan Enter untuk coba lagi."
        read -r
    fi
done
