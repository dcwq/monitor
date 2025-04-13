#!/bin/bash

# CronitorClone - klon klienta Cronitor CLI wysyłający dane do własnego API
# Autor: Claude
# Data: 2025-04-13

# ========== KONFIGURACJA ==========
VERSION="0.3.0"
CONFIG_FILE="${HOME}/.cronitor-clone.json"
API_URL="https://cronitorex.local/server" # URL Twojego własnego API
API_PING_ENDPOINT="/cronitor-ping.php"
API_KEY="" # Opcjonalnie: klucz API do autentykacji
TELEMETRY_ENABLED=true # Czy włączyć telemetrię
DEFAULT_PROJECT="" # Domyślna nazwa projektu

# Domyślne wartości limitu czasu (w sekundach)
DEFAULT_TIMEOUT=30
DEFAULT_PING_TIMEOUT=5

# ========== FUNKCJE POMOCNICZE ==========

# Logowanie komunikatów
log() {
    local level="$1"
    local message="$2"
    local timestamp=$(date "+%Y-%m-%d %H:%M:%S")

    case "$level" in
        info)
            echo -e "\033[0;32m[INFO]\033[0m $message" ;;
        warn)
            echo -e "\033[0;33m[WARN]\033[0m $message" >&2 ;;
        error)
            echo -e "\033[0;31m[ERROR]\033[0m $message" >&2 ;;
        debug)
            if [[ "$DEBUG" == "true" ]]; then
                echo -e "\033[0;36m[DEBUG]\033[0m $message" >&2
            fi
            ;;
        *)
            echo "$message" ;;
    esac
}

# Obsługa błędów
handle_error() {
    local exit_code=$1
    local message="${2:-Wystąpił nieoczekiwany błąd}"

    log "error" "$message"
    exit $exit_code
}

# Sprawdzenie zależności
check_dependencies() {
    for cmd in curl jq; do
        if ! command -v $cmd &> /dev/null; then
            handle_error 1 "Brak wymaganej zależności: $cmd. Zainstaluj ją przed kontynuowaniem."
        fi
    done
}

# Wczytanie konfiguracji
load_config() {
    if [[ -f "$CONFIG_FILE" ]]; then
        if ! API_URL=$(jq -r '.api_url // empty' "$CONFIG_FILE" 2>/dev/null); then
            log "warn" "Nie można wczytać URL API z pliku konfiguracyjnego"
        fi

        if ! API_KEY=$(jq -r '.api_key // empty' "$CONFIG_FILE" 2>/dev/null); then
            log "warn" "Nie można wczytać klucza API z pliku konfiguracyjnego"
        fi

        if ! TELEMETRY_ENABLED=$(jq -r '.telemetry_enabled // true' "$CONFIG_FILE" 2>/dev/null); then
            TELEMETRY_ENABLED=true
        fi

        if ! DEFAULT_PROJECT=$(jq -r '.default_project // empty' "$CONFIG_FILE" 2>/dev/null); then
            DEFAULT_PROJECT=""
        fi

        log "debug" "Konfiguracja wczytana z $CONFIG_FILE"
    else
        log "debug" "Plik konfiguracyjny nie istnieje, używam domyślnych ustawień"
    fi
}

# Zapisanie konfiguracji
save_config() {
    mkdir -p "$(dirname "$CONFIG_FILE")"

    echo '{
  "api_url": "'$API_URL'",
  "api_ping_endpoint": "'$API_PING_ENDPOINT'",
  "api_key": "'$API_KEY'",
  "telemetry_enabled": '$TELEMETRY_ENABLED',
  "default_project": "'$DEFAULT_PROJECT'"
}' > "$CONFIG_FILE"

    if [[ $? -eq 0 ]]; then
        log "info" "Konfiguracja zapisana do $CONFIG_FILE"
    else
        log "error" "Nie można zapisać konfiguracji do $CONFIG_FILE"
    fi
}

# Wykonanie żądania HTTP do API
api_request() {
    local method="$1"
    local endpoint="$2"
    local data="$3"
    local url="${API_URL}${endpoint}"

    local curl_opts=(
        -X "$method"
        -s
        --insecure
        -S
        -H "Content-Type: application/json"
        --connect-timeout "$DEFAULT_PING_TIMEOUT"
        --max-time "$DEFAULT_TIMEOUT"
    )

    # Dodaj nagłówek autoryzacji, jeśli klucz API jest dostępny
    if [[ -n "$API_KEY" ]]; then
        curl_opts+=(-H "Authorization: Bearer $API_KEY")
    fi

    # Dodaj dane dla żądań POST/PUT
    if [[ -n "$data" && ("$method" == "POST" || "$method" == "PUT") ]]; then
        curl_opts+=(--data "$data")
    fi

    # Wykonaj żądanie
    local response=$(curl "${curl_opts[@]}" "$url" 2>&1)
    local status=$?

    if [[ $status -ne 0 ]]; then
        log "error" "Błąd podczas komunikacji z API: $response"
        return 1
    fi

    # Sprawdź, czy odpowiedź jest poprawnym JSON
    if ! echo "$response" | jq '.' &>/dev/null; then
        log "error" "Otrzymano nieprawidłową odpowiedź od API: $response"
        return 1
    fi

    # Sprawdź kod statusu z odpowiedzi
    local api_status=$(echo "$response" | jq -r '.status // "error"')
    if [[ "$api_status" != "success" ]]; then
        local error_message=$(echo "$response" | jq -r '.message // "Nieznany błąd API"')
        log "error" "API zwróciło błąd: $error_message"
        return 1
    fi

    # Zwróć treść odpowiedzi
    echo "$response"
    return 0
}

# Generuj unikalny identyfikator
generate_uuid() {
    cat /proc/sys/kernel/random/uuid 2>/dev/null ||
    python -c "import uuid; print(uuid.uuid4())" 2>/dev/null ||
    (date +%s%N | md5sum | head -c 32)
}

# Parsowanie argumentów tagów
parse_tags() {
    local tags_arg="$1"
    local tags_json="["

    if [[ -n "$tags_arg" ]]; then
        # Podziel tagi po przecinku
        IFS=',' read -ra TAG_ARRAY <<< "$tags_arg"

        # Buduj tablicę JSON tagów
        local first=true
        for tag in "${TAG_ARRAY[@]}"; do
            # Usuń białe znaki
            tag=$(echo "$tag" | xargs)

            # Dodaj przecinek, jeśli to nie pierwszy element
            if [[ "$first" != "true" ]]; then
                tags_json="${tags_json},"
            fi

            # Dodaj tag w cudzysłowiu
            tags_json="${tags_json}\"$tag\""
            first=false
        done
    fi

    # Zamknij tablicę JSON
    tags_json="${tags_json}]"

    echo "$tags_json"
}

# Pomiar czasu wykonania
measure_execution() {
    local start_time=$SECONDS
    local unique_id=$(generate_uuid)
    local monitor_name="$1"
    local tags="$2"
    local project="$3"
    local cmd="${@:4}"
    local exit_code
    local output_file=$(mktemp)

    log "info" "Uruchamianie zadania '$monitor_name' (ID: $unique_id)"

    # Wyślij ping "run"
    send_ping "$monitor_name" "run" "$unique_id" "0" "0" "" "$tags" "$project" || true

    # Wykonaj polecenie i zapisz kod wyjścia oraz wyjście
    { $cmd > >(tee -a "$output_file") 2> >(tee -a "$output_file" >&2); }
    exit_code=$?

    # Oblicz czas trwania
    local duration=$((SECONDS - start_time))

    log "debug" "Zadanie '$monitor_name' zakończone z kodem: $exit_code (czas: ${duration}s)"

    # Wyślij ping "complete" lub "fail" w zależności od kodu wyjścia
    if [[ $exit_code -eq 0 ]]; then
        send_ping "$monitor_name" "complete" "$unique_id" "$duration" "0" "" "$tags" "$project" || true
        log "info" "Zadanie '$monitor_name' zakończone pomyślnie (czas: ${duration}s)"
    else
        # Pobierz pierwsze 1000 znaków z wyjścia jako informację o błędzie
        local error_snippet=$(head -c 1000 "$output_file")
        send_ping "$monitor_name" "fail" "$unique_id" "$duration" "$exit_code" "$error_snippet" "$tags" "$project" || true
        log "error" "Zadanie '$monitor_name' zakończone z błędem (kod: $exit_code, czas: ${duration}s)"
    fi

    # Wyczyść plik tymczasowy
    rm -f "$output_file"

    return $exit_code
}

# Wysłanie pingu do API
send_ping() {
    local monitor="$1"
    local state="$2"
    local unique_id="$3"
    local duration="${4:-0}"
    local exit_code="${5:-0}"
    local error="${6:-}"
    local tags_arg="${7:-}"
    local project="${8:-$DEFAULT_PROJECT}"

    # Nie wysyłaj pingów, jeśli telemetria jest wyłączona
    if [[ "$TELEMETRY_ENABLED" != "true" ]]; then
        log "debug" "Telemetria wyłączona, pomijam ping '$state' dla '$monitor'"
        return 0
    fi

    local hostname=$(hostname)
    local timestamp=$(date +%s)

    # Parsuj tagi do formatu JSON
    local tags_json=$(parse_tags "$tags_arg")

    # Przygotuj dane do wysłania
    local ping_data='{
        "monitor": "'$monitor'",
        "state": "'$state'",
        "unique_id": "'$unique_id'",
        "duration": '$duration',
        "exit_code": '$exit_code',
        "host": "'$hostname'",
        "timestamp": '$timestamp',
        "tags": '$tags_json

    # Dodaj project name, jeśli istnieje
    if [[ -n "$project" ]]; then
        ping_data="${ping_data}"',
        "project": "'"$project"'"'
    fi

    # Dodaj informację o błędzie, jeśli istnieje
    if [[ -n "$error" ]]; then
        # Escape specjalnych znaków dla JSON
        error=$(echo "$error" | sed 's/"/\\"/g' | sed 's/\\/\\\\/g' | tr '\n' ' ')
        ping_data="${ping_data}"',
        "error": "'"$error"'"'
    fi

    # Zamknij JSON
    ping_data="${ping_data}"'}'

    log "debug" "Wysyłanie pingu '$state' dla monitora '$monitor'"

    # Wysłanie pingu do API
    if ! api_request "POST" "$API_PING_ENDPOINT" "$ping_data" &>/dev/null; then
        log "warn" "Nie udało się wysłać pingu do API"
        return 1
    fi

    log "debug" "Ping '$state' dla '$monitor' wysłany pomyślnie"
    return 0
}

# ========== KOMENDY ==========

# Komenda: help
cmd_help() {
    echo "CronitorClone v$VERSION - klon klienta Cronitor wysyłający dane do własnego API"
    echo ""
    echo "Użycie:"
    echo "  $0 <komenda> [opcje]"
    echo ""
    echo "Dostępne komendy:"
    echo "  run <nazwa_monitora> [--tags <tagi>] [--project <projekt>] <polecenie...>   Uruchom polecenie i monitoruj jego wykonanie"
    echo "  ping <nazwa_monitora> <stan> [--tags <tagi>] [--project <projekt>] [message]   Wyślij ping o określonym stanie (run/complete/fail)"
    echo "  discover                                           Wykryj zadania cron i wygeneruj konfigurację monitoringu"
    echo "  configure                                          Skonfiguruj połączenie z API"
    echo "  help                                               Wyświetl tę pomoc"
    echo "  version                                            Wyświetl informacje o wersji"
    echo ""
    echo "Opcje:"
    echo "  --tags <tag1,tag2,...>                             Lista tagów oddzielonych przecinkami"
    echo "  --project <nazwa_projektu>                         Nazwa projektu do którego należy zadanie"
    echo ""
    echo "Przykłady:"
    echo "  $0 run backup-database --tags produkcja,nightly --project backend pg_dump -U postgres database > /backups/db.sql"
    echo "  $0 ping daily-backup run --tags cron,backup --project system"
    echo "  $0 discover --crontab /etc/crontab --output /etc/cronitor-clone/crontab.conf"
    echo "  $0 configure"
    echo ""
    echo "Więcej informacji na stronie projektu."
}

# Komenda: version
cmd_version() {
    echo "CronitorClone v$VERSION"
}

# Komenda: configure
cmd_configure() {
    echo "Konfiguracja CronitorClone"
    echo "--------------------------"

    # Pobierz aktualną konfigurację lub ustaw domyślne wartości
    local current_api_url="${API_URL:-https://api.example.com/v1}"
    local current_api_ping_endpoint="${API_PING_ENDPOINT:-/cronitor-ping.php}"
    local current_api_key="${API_KEY:-}"
    local current_telemetry="${TELEMETRY_ENABLED:-true}"
    local current_project="${DEFAULT_PROJECT:-}"

    # Pobierz nowe wartości od użytkownika
    read -p "URL API [$current_api_url]: " new_api_url
    API_URL=${new_api_url:-$current_api_url}

    read -p "URL API PING ENDPOINT [$current_api_ping_endpoint]: " new_api_ping_endpoint
    API_PING_ENDPOINT=${new_api_ping_endpoint:-$$current_api_ping_endpoint}

    read -p "Klucz API [$current_api_key]: " new_api_key
    API_KEY=${new_api_key:-$current_api_key}

    read -p "Włączyć telemetrię (true/false) [$current_telemetry]: " new_telemetry
    TELEMETRY_ENABLED=${new_telemetry:-$current_telemetry}

    read -p "Domyślna nazwa projektu [$current_project]: " new_project
    DEFAULT_PROJECT=${new_project:-$current_project}

    # Zapisz konfigurację
    save_config

    # Sprawdź połączenie z API
    echo "Sprawdzanie połączenia z API..."
    if api_request "GET" "$API_PING_ENDPOINT" > /dev/null; then
        log "info" "Połączenie z API nawiązane pomyślnie"
    else
        log "warn" "Nie udało się nawiązać połączenia z API. Sprawdź ustawienia."
    fi
}

# Komenda: ping
cmd_ping() {
    local monitor_name=""
    local state=""
    local message=""
    local tags=""
    local project="$DEFAULT_PROJECT"

    # Sprawdź minimum wymaganych argumentów
    if [[ $# -lt 2 ]]; then
        log "error" "Brak wymaganych parametrów"
        echo "Użycie: $0 ping <nazwa_monitora> <stan> [--tags <tagi>] [--project <projekt>] [message]"
        echo "Stan: run, complete, fail"
        return 1
    fi

    monitor_name="$1"
    state="$2"
    shift 2

    # Parsuj pozostałe argumenty
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --tags)
                tags="$2"
                shift 2
                ;;
            --project)
                project="$2"
                shift 2
                ;;
            *)
                # Zakładamy, że wszystko po stanie, tagach i projekcie to wiadomość
                message="$*"
                break
                ;;
        esac
    done

    if [[ ! "$state" =~ ^(run|complete|fail)$ ]]; then
        log "error" "Nieprawidłowy stan. Dozwolone: run, complete, fail"
        return 1
    fi

    local unique_id=$(generate_uuid)

    if send_ping "$monitor_name" "$state" "$unique_id" "0" "0" "$message" "$tags" "$project"; then
        log "info" "Ping '$state' dla monitora '$monitor_name' wysłany pomyślnie"
        return 0
    else
        log "error" "Nie udało się wysłać pingu dla monitora '$monitor_name'"
        return 1
    fi
}

# Komenda: run
cmd_run() {
    local monitor_name=""
    local tags=""
    local project="$DEFAULT_PROJECT"

    # Sprawdź minimum wymaganych argumentów
    if [[ $# -lt 1 ]]; then
        log "error" "Brak wymaganych parametrów"
        echo "Użycie: $0 run <nazwa_monitora> [--tags <tagi>] [--project <projekt>] <polecenie...>"
        return 1
    fi

    monitor_name="$1"
    shift

    # Parsuj pozostałe argumenty
    while [[ $# -gt 0 && "$1" =~ ^-- ]]; do
        case "$1" in
            --tags)
                tags="$2"
                shift 2
                ;;
            --project)
                project="$2"
                shift 2
                ;;
            *)
                # Nieznana opcja
                break
                ;;
        esac
    done

    # Sprawdź, czy mamy polecenie do wykonania
    if [[ $# -lt 1 ]]; then
        log "error" "Brak polecenia do wykonania"
        echo "Użycie: $0 run <nazwa_monitora> [--tags <tagi>] [--project <projekt>] <polecenie...>"
        return 1
    fi

    # Wykonaj polecenie i zmierz czas wykonania
    measure_execution "$monitor_name" "$tags" "$project" "$@"
    return $?
}

# Komenda: discover
cmd_discover() {
    local crontab_file=""
    local output_file=""
    local include_disabled=false
    local verbose=false
    local use_name_hash=false
    local default_project="$DEFAULT_PROJECT"
    local template="CRON_JOB=\"%s\" %s run %s %s"
    local discovered=0

    # Parsowanie opcji
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --crontab)
                crontab_file="$2"
                shift 2
                ;;
            --output)
                output_file="$2"
                shift 2
                ;;
            --include-disabled)
                include_disabled=true
                shift
                ;;
            --verbose|-v)
                verbose=true
                shift
                ;;
            --use-name-hash)
                use_name_hash=true
                shift
                ;;
            --project)
                default_project="$2"
                shift 2
                ;;
            --help|-h)
                echo "Użycie: $0 discover [opcje]"
                echo ""
                echo "Opcje:"
                echo "  --crontab <plik>          Plik crontab do analizy (domyślnie: crontab bieżącego użytkownika)"
                echo "  --output <plik>           Plik wyjściowy (domyślnie: stdout)"
                echo "  --include-disabled        Uwzględnij wykomentowane zadania cron"
                echo "  --verbose, -v             Wyświetl szczegółowe informacje"
                echo "  --use-name-hash           Używaj hashu jako nazwy monitora"
                echo "  --project <nazwa>         Ustaw domyślną nazwę projektu dla wykrytych zadań"
                echo "  --help, -h                Wyświetl tę pomoc"
                return 0
                ;;
            *)
                log "error" "Nieznana opcja: $1"
                return 1
                ;;
        esac
    done

    log "info" "Wykrywanie zadań cron..."

    # Przygotuj narzędzia do tymczasowego zapisu
    local temp_file=$(mktemp)

    # Ustal źródło crontab - domyślnie używamy crontab bieżącego użytkownika
    if [[ -z "$crontab_file" ]]; then
        # Sprawdź, czy użytkownik ma crontab
        if ! crontab -l &>/dev/null; then
            log "error" "Nie można uzyskać dostępu do crontab użytkownika. Sprawdź, czy masz jakiekolwiek zadania cron."
            rm -f "$temp_file"
            return 1
        fi

        log "debug" "Używam crontab bieżącego użytkownika"
        local content=$(crontab -l)
    else
        if [[ ! -f "$crontab_file" ]]; then
            log "error" "Podany plik crontab nie istnieje: $crontab_file"
            rm -f "$temp_file"
            return 1
        fi
        local content=$(cat "$crontab_file")
    fi

    # Funkcja do generowania nazwy monitora na podstawie komendy
    generate_monitor_name() {
        local cmd="$1"
        local name

        if [[ "$use_name_hash" == "true" ]]; then
            # Wygeneruj hash komendy
            name=$(echo "$cmd" | md5sum | cut -d' ' -f1)
        else
            # Użyj pierwszego słowa jako podstawy nazwy
            name=$(echo "$cmd" | awk '{print $1}' | sed 's/.*\///' | tr '[:upper:]' '[:lower:]')

            # Dodaj skrócony hash dla unikalności
            local hash=$(echo "$cmd" | md5sum | cut -d' ' -f1 | cut -c1-6)
            name="${name}-${hash}"
        fi

        echo "$name"
    }

    if [[ "$verbose" == "true" ]]; then
        log "debug" "Zawartość crontaba:"
        echo "$content"
        echo "----------------------"
    fi

    # Iteruj przez każdą linię - unikamy pipe | z while, bo to tworzy subshell
    while IFS= read -r line; do
        # Pomiń puste linie i komentarze, chyba że include_disabled=true
        if [[ "$line" =~ ^[[:space:]]*$ || ("$include_disabled" != "true" && "$line" =~ ^[[:space:]]*#) ]]; then
            continue
        fi

        # Usuń komentarz, jeśli include_disabled=true i linia zaczyna się od #
        if [[ "$include_disabled" == "true" && "$line" =~ ^[[:space:]]*# ]]; then
            line=$(echo "$line" | sed 's/^[[:space:]]*#[[:space:]]*//')
        fi

        if [[ "$verbose" == "true" ]]; then
            log "debug" "Analizuję linię: $line"
        fi

        # Uproszczona wersja parsera dla typowego formatu crontab
        # Szukamy 5 pól czasowych (minuty, godziny, dzień miesiąca, miesiąc, dzień tygodnia)
        # i komendy po nich
        if [[ "$line" =~ ^[[:space:]]*([*0-9,-/]+[[:space:]]+[*0-9,-/]+[[:space:]]+[*0-9,-/]+[[:space:]]+[*0-9,-/]+[[:space:]]+[*0-9,-/]+)[[:space:]]+(.+) ]]; then
            local schedule="${BASH_REMATCH[1]}"
            local command="${BASH_REMATCH[2]}"

            # Usuń komentarz z komendy, jeśli istnieje
            command=$(echo "$command" | sed 's/[[:space:]]*#.*$//')

            if [[ "$verbose" == "true" ]]; then
                log "debug" "Znaleziono dopasowanie: schedule='$schedule', command='$command'"
            fi

            # Generuj nazwę monitora
            local monitor_name=$(generate_monitor_name "$command")

            # Sugerowane tagi na podstawie polecenia
            local suggested_tags=""
            if [[ "$command" == *"backup"* ]]; then
                suggested_tags="backup"
            elif [[ "$command" == *"clean"* || "$command" == *"purge"* ]]; then
                suggested_tags="maintenance"
            elif [[ "$command" == *"update"* || "$command" == *"upgrade"* ]]; then
                suggested_tags="update"
            fi

            # Generuj wpis dla monitora
            local entry=""
            if [[ -n "$suggested_tags" ]]; then
                if [[ -n "$default_project" ]]; then
                    entry=$(printf "$template --tags %s --project %s" "$schedule" "$0" "$monitor_name" "$suggested_tags" "$default_project" "$command")
                else
                    entry=$(printf "$template --tags %s" "$schedule" "$0" "$monitor_name" "$suggested_tags" "$command")
                fi
            else
                if [[ -n "$default_project" ]]; then
                    entry=$(printf "$template --project %s" "$schedule" "$0" "$monitor_name" "$default_project" "$command")
                else
                    entry=$(printf "$template" "$schedule" "$0" "$monitor_name" "$command")
                fi
            fi

            echo "$entry" >> "$temp_file"
            ((discovered++))

            log "info" "Wykryto zadanie: $monitor_name ($schedule)"
        elif [[ "$line" =~ ^[[:space:]]*@(reboot|yearly|annually|monthly|weekly|daily|hourly)[[:space:]]+(.+) ]]; then
            local schedule="@${BASH_REMATCH[1]}"
            local command="${BASH_REMATCH[2]}"

            # Usuń komentarz z komendy, jeśli istnieje
            command=$(echo "$command" | sed 's/[[:space:]]*#.*$//')

            if [[ "$verbose" == "true" ]]; then
                log "debug" "Znaleziono zadanie specjalne: schedule='$schedule', command='$command'"
            fi

            # Generuj nazwę monitora
            local monitor_name=$(generate_monitor_name "$command")

            # Sugerowane tagi na podstawie polecenia i harmonogramu
            local suggested_tags=""
            if [[ "$command" == *"backup"* ]]; then
                suggested_tags="backup"
            elif [[ "$command" == *"clean"* || "$command" == *"purge"* ]]; then
                suggested_tags="maintenance"
            elif [[ "$command" == *"update"* || "$command" == *"upgrade"* ]]; then
                suggested_tags="update"
            fi

            # Dodaj tag na podstawie harmonogramu
            if [[ -n "$suggested_tags" ]]; then
                suggested_tags="${suggested_tags},${BASH_REMATCH[1]}"
            else
                suggested_tags="${BASH_REMATCH[1]}"
            fi

            # Generuj wpis dla monitora
            local entry=""
            if [[ -n "$default_project" ]]; then
                entry=$(printf "$template --tags %s --project %s" "$schedule" "$0" "$monitor_name" "$suggested_tags" "$default_project" "$command")
            else
                entry=$(printf "$template --tags %s" "$schedule" "$0" "$monitor_name" "$suggested_tags" "$command")
            fi

            echo "$entry" >> "$temp_file"
            ((discovered++))

            log "info" "Wykryto zadanie: $monitor_name ($schedule)"
        else
            if [[ "$verbose" == "true" ]]; then
                log "debug" "Nie rozpoznano formatu linii: $line"
            fi
        fi
    done <<< "$content"

    # Wyświetl wyniki
    log "info" "Wykryto $discovered zadań cron"

    if [[ "$discovered" -eq 0 ]]; then
        log "warn" "Nie wykryto żadnych zadań cron"
        rm -f "$temp_file"
        return 0
    fi

    # Zapisz wyniki do pliku lub wyświetl na stdout
    if [[ -n "$output_file" ]]; then
        mv "$temp_file" "$output_file"
        log "info" "Wygenerowano konfigurację monitoringu do pliku: $output_file"
    else
        echo -e "\n--- Konfiguracja monitoringu dla wykrytych zadań cron ---\n"
        cat "$temp_file"
        echo -e "\n--- Koniec konfiguracji ---\n"
        echo "Aby użyć powyższej konfiguracji, dodaj te linie do odpowiedniego skryptu lub pliku crontab."
        rm -f "$temp_file"
    fi

    return 0
}

# ========== GŁÓWNA FUNKCJA ==========

main() {
    # Sprawdź zależności
    check_dependencies

    # Wczytaj konfigurację
    load_config

    # Sprawdź, czy podano komendę
    if [[ $# -eq 0 ]]; then
        cmd_help
        exit 0
    fi

    # Pobierz komendę
    local command="$1"
    shift

    # Wykonaj odpowiednią komendę
    case "$command" in
        help)
            cmd_help
            ;;
        version)
            cmd_version
            ;;
        configure)
            cmd_configure
            ;;
        ping)
            cmd_ping "$@"
            ;;
        run)
            cmd_run "$@"
            ;;
        discover)
            cmd_discover "$@"
            ;;
        *)
            log "error" "Nieznana komenda: $command"
            cmd_help
            exit 1
            ;;
    esac

    exit $?
}

# Uruchom główną funkcję
main "$@"