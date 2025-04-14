#!/bin/bash

# CronitorClone - klon klienta Cronitor CLI wysyłający dane do własnego API
# Autor: Claude
# Data: 2025-04-13

export TZ="Europe/Warsaw"

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

# Funkcja do sprawdzenia, czy skrypt jest uruchamiany przez crona
is_running_from_cron() {
    # Sprawdzamy, czy proces nadrzędny to cron
    local ppid=$(ps -o ppid= -p $$)
    local pname=$(ps -o comm= -p "$ppid")

    if [[ "$pname" == *"cron"* ]]; then
        return 0  # True, uruchomiony przez crona
    fi

    # Alternatywna metoda - sprawdzenie, czy TERM jest ustawiony na "dumb" (typowe dla crona)
    if [[ "$TERM" == "dumb" ]]; then
        return 0  # True, prawdopodobnie uruchomiony przez crona
    fi

    # Sprawdź, czy jest to proces, który nie ma żadnego TTY i jego PPID to 1 (systemd)
    # co może wskazywać na crona sterowanego przez systemd
    if ! tty -s && [[ "$(ps -o ppid= -p "$ppid" | tr -d ' ')" == "1" ]]; then
        return 0  # True, prawdopodobnie uruchomiony przez crona
    fi

    return 1  # False, nie uruchomiony przez crona
}

# Funkcja do określenia zaplanowanego czasu uruchomienia dla zadania cron
get_scheduled_time() {
    # Domyślnie zwracamy bieżący czas
    local current_time=$(date +%s)

    # Jeśli nie jest uruchamiany przez crona, zwracamy bieżący czas
    if ! is_running_from_cron; then
        echo "$current_time"
        return
    fi

    # Cron uruchamia zadania zazwyczaj o pełnej minucie
    # Zaokrąglamy czas w dół do początku bieżącej minuty
    local scheduled_time=$(( current_time - (current_time % 60) ))

    echo "$scheduled_time"
}

# Funkcja do pobierania aktualnej strefy czasowej
get_timezone() {
    # Najpierw próbujemy pobrać strefę z pliku /etc/timezone (Debian/Ubuntu)
    if [[ -f "/etc/timezone" ]]; then
        timezone=$(cat /etc/timezone 2>/dev/null)
        if [[ -n "$timezone" ]]; then
            echo "$timezone"
            return 0
        fi
    fi

    # Alternatywnie, próbujemy pobrać z pliku /etc/localtime (symboliczne dowiązanie)
    if [[ -L "/etc/localtime" ]]; then
        timezone=$(readlink /etc/localtime | sed 's/^.*zoneinfo\///')
        if [[ -n "$timezone" ]]; then
            echo "$timezone"
            return 0
        fi
    fi

    # Jeśli powyższe metody zawiodły, używamy polecenia date
    timezone=$(date +%Z 2>/dev/null)
    if [[ -n "$timezone" ]]; then
        # date +%Z zwraca skrót strefy (np. CET)
        # Spróbujmy uzyskać pełną nazwę strefy czasowej
        full_tz=$(date +%:z 2>/dev/null)
        if [[ -n "$full_tz" ]]; then
            # Format: UTC+HH:MM lub UTC-HH:MM
            echo "UTC${full_tz}"
            return 0
        fi
        echo "$timezone"
        return 0
    fi

    # Ostatecznie, jeśli wszystko zawiedzie, zwracamy "UTC"
    echo "UTC"
    return 0
}

send_ping() {
    local monitor="$1"
    local state="$2"
    local unique_id="$3"
    local duration="${4:-0}"
    local exit_code="${5:-0}"
    local error="${6:-}"
    local tags_arg="${7:-}"
    local project="${8:-$DEFAULT_PROJECT}"
    local run_source="${9:-shell}"
    local cron_schedule="${10:-}"
    local timezone="${11:-$(get_timezone)}"

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
        "timezone": "'$timezone'",
        "run_source": "'$run_source'",
        "tags": '$tags_json

    # Dodaj definicję crona tylko jeśli źródłem jest cron i definicja istnieje
    if [[ "$run_source" == "cron" && -n "$cron_schedule" ]]; then
        ping_data="${ping_data}"',
        "cron_schedule": "'"$cron_schedule"'"'
    fi

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

    log "debug" "Wysyłanie pingu '$state' dla monitora '$monitor' (źródło: $run_source, strefa: $timezone)"
    if [[ "$run_source" == "cron" && -n "$cron_schedule" ]]; then
        log "debug" "Definicja crona: $cron_schedule"
    fi

    # Wysłanie pingu do API
    if ! api_request "POST" "$API_PING_ENDPOINT" "$ping_data" &>/dev/null; then
        log "warn" "Nie udało się wysłać pingu do API"
        return 1
    fi

    log "debug" "Ping '$state' dla '$monitor' wysłany pomyślnie"
    return 0
}

# Zmodyfikuj funkcję measure_execution, aby przekazywała strefę czasową
measure_execution() {
    local start_time=$SECONDS
    local unique_id=$(generate_uuid)
    local monitor_name="$1"
    local tags="$2"
    local project="$3"
    local cmd="${@:4}"
    local exit_code
    local output_file=$(mktemp)

    # Wykryj źródło uruchomienia
    local run_source=$(detect_run_source)
    log "debug" "Wykryte źródło uruchomienia: $run_source"

    # Jeśli źródłem jest cron, spróbuj pobrać definicję
    local cron_schedule=""
    if [[ "$run_source" == "cron" ]]; then
        cron_schedule=$(get_cron_schedule "$monitor_name")
        if [[ $? -eq 0 && -n "$cron_schedule" ]]; then
            log "info" "Wykryto definicję crona: $cron_schedule"
        else
            log "debug" "Nie wykryto definicji crona dla monitora: $monitor_name"
        fi
    fi

    # Pobierz strefę czasową
    local timezone=$(get_timezone)
    log "debug" "Strefa czasowa: $timezone"

    log "info" "Uruchamianie zadania '$monitor_name' (ID: $unique_id, źródło: $run_source)"

    # Wyślij ping "run" z informacją o źródle uruchomienia i strefie czasowej
    send_ping "$monitor_name" "run" "$unique_id" "0" "0" "" "$tags" "$project" "$run_source" "$cron_schedule" "$timezone" || true

    # Wykonaj polecenie i zapisz kod wyjścia oraz wyjście
    { $cmd > >(tee -a "$output_file") 2> >(tee -a "$output_file" >&2); }
    exit_code=$?

    # Oblicz czas trwania
    local duration=$((SECONDS - start_time))

    log "debug" "Zadanie '$monitor_name' zakończone z kodem: $exit_code (czas: ${duration}s)"

    # Wyślij ping "complete" lub "fail" w zależności od kodu wyjścia
    if [[ $exit_code -eq 0 ]]; then
        send_ping "$monitor_name" "complete" "$unique_id" "$duration" "0" "" "$tags" "$project" "$run_source" "$cron_schedule" "$timezone" || true
        log "info" "Zadanie '$monitor_name' zakończone pomyślnie (czas: ${duration}s)"
    else
        # Pobierz pierwsze 1000 znaków z wyjścia jako informację o błędzie
        local error_snippet=$(head -c 1000 "$output_file")
        send_ping "$monitor_name" "fail" "$unique_id" "$duration" "$exit_code" "$error_snippet" "$tags" "$project" "$run_source" "$cron_schedule" "$timezone" || true
        log "error" "Zadanie '$monitor_name' zakończone z błędem (kod: $exit_code, czas: ${duration}s)"
    fi

    # Wyczyść plik tymczasowy
    rm -f "$output_file"

    return $exit_code
}

# Zmodyfikuj również cmd_ping, aby obsługiwało parametr timezone
cmd_ping() {
    local monitor_name=""
    local state=""
    local message=""
    local tags=""
    local project="$DEFAULT_PROJECT"
    local run_source=""
    local cron_schedule=""
    local timezone=""

    # Sprawdź minimum wymaganych argumentów
    if [[ $# -lt 2 ]]; then
        log "error" "Brak wymaganych parametrów"
        echo "Użycie: $0 ping <nazwa_monitora> <stan> [--tags <tagi>] [--project <projekt>] [--run-source <źródło>] [--cron-schedule <definicja>] [--timezone <strefa>] [message]"
        echo "Stan: run, complete, fail"
        echo "Źródło: cron, systemd, shell, interactive_shell, ssh, jenkins, docker, ..."
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
            --run-source)
                run_source="$2"
                shift 2
                ;;
            --cron-schedule)
                cron_schedule="$2"
                shift 2
                ;;
            --timezone)
                timezone="$2"
                shift 2
                ;;
            *)
                # Zakładamy, że wszystko po opcjach to wiadomość
                message="$*"
                break
                ;;
        esac
    done

    if [[ ! "$state" =~ ^(run|complete|fail)$ ]]; then
        log "error" "Nieprawidłowy stan. Dozwolone: run, complete, fail"
        return 1
    fi

    # Jeśli nie podano źródła uruchomienia, wykryj je
    if [[ -z "$run_source" ]]; then
        run_source=$(detect_run_source)
        log "debug" "Wykryte źródło uruchomienia: $run_source"
    fi

    # Jeśli źródłem jest cron i nie podano definicji, spróbuj ją wykryć
    if [[ "$run_source" == "cron" && -z "$cron_schedule" ]]; then
        cron_schedule=$(get_cron_schedule "$monitor_name")
        if [[ $? -eq 0 && -n "$cron_schedule" ]]; then
            log "debug" "Wykryto definicję crona: $cron_schedule"
        fi
    fi

    # Jeśli nie podano strefy czasowej, pobierz aktualną
    if [[ -z "$timezone" ]]; then
        timezone=$(get_timezone)
        log "debug" "Wykryta strefa czasowa: $timezone"
    fi

    local unique_id=$(generate_uuid)

    if send_ping "$monitor_name" "$state" "$unique_id" "0" "0" "$message" "$tags" "$project" "$run_source" "$cron_schedule" "$timezone"; then
        log "info" "Ping '$state' dla monitora '$monitor_name' wysłany pomyślnie (źródło: $run_source, strefa: $timezone)"
        if [[ "$run_source" == "cron" && -n "$cron_schedule" ]]; then
            log "info" "Definicja crona: $cron_schedule"
        fi
        return 0
    else
        log "error" "Nie udało się wysłać pingu dla monitora '$monitor_name'"
        return 1
    fi
}


# Funkcja do wykrywania źródła uruchomienia
detect_run_source() {
    # Sprawdź różne źródła uruchomienia i zwróć odpowiedni identyfikator

    # 1. Sprawdź, czy uruchomiono przez crona
    if is_running_from_cron; then
        echo "cron"
        return 0
    fi

    # 2. Sprawdź, czy uruchomiono przez systemd
    if ps -p $PPID -o comm= | grep -q "systemd"; then
        echo "systemd"
        return 0
    fi

    # 3. Sprawdź, czy uruchomiono przez skrypt init.d
    if ps -p $PPID -o comm= | grep -q "init"; then
        echo "init"
        return 0
    fi

    # 4. Sprawdź, czy uruchomiono przez SSH (np. zdalnie)
    if ps -p $PPID -o comm= | grep -q "sshd"; then
        echo "ssh"
        return 0
    fi

    # 5. Sprawdź zmienne środowiskowe typowe dla konkretnych środowisk
    if [[ -n "$JENKINS_HOME" ]]; then
        echo "jenkins"
        return 0
    fi

    if [[ -n "$GITLAB_CI" ]]; then
        echo "gitlab_ci"
        return 0
    fi

    if [[ -n "$GITHUB_ACTIONS" ]]; then
        echo "github_actions"
        return 0
    fi

    if [[ -n "$TRAVIS" ]]; then
        echo "travis_ci"
        return 0
    fi

    if [[ -n "$CIRCLECI" ]]; then
        echo "circle_ci"
        return 0
    fi

    if [[ -n "$DOCKER_CONTAINER" || -f "/.dockerenv" ]]; then
        echo "docker"
        return 0
    fi

    # 6. Sprawdź, czy uruchomiono interaktywnie w terminalu
    if [[ -t 0 && -t 1 && -t 2 ]]; then
        echo "interactive_shell"
        return 0
    fi

    # 7. Domyślnie zwróć "shell" dla skryptów uruchomionych z powłoki
    echo "shell"
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

get_cron_schedule() {
    local monitor_name="$1"
    local cron_schedule=""
    local timezone=$(cat /etc/timezone 2>/dev/null || date +%Z)

    # Sprawdź, czy proces jest uruchomiony przez crona
    if ! is_running_from_cron; then
        log "debug" "Zadanie nie jest uruchamiane przez crona"
        return 1
    fi

    log "debug" "Wykrywanie definicji zadania cron dla monitora: $monitor_name"

    # Pobierz PID procesu, który uruchomił ten skrypt
    local ppid=$(ps -o ppid= -p $$ | tr -d ' ')

    # Ścieżka do tego skryptu
    local script_path=$(realpath "$0" 2>/dev/null || echo "$0")
    local script_name=$(basename "$script_path")

    # Spróbuj odczytać crontab bieżącego użytkownika
    if command -v crontab >/dev/null 2>&1; then
        local user_crontab=$(crontab -l 2>/dev/null)

        # Szukamy zadania odpowiadającego naszemu skryptowi i monitorowi
        while IFS= read -r line; do
            # Pomijamy komentarze i puste linie
            if [[ "$line" =~ ^[[:space:]]*# || "$line" =~ ^[[:space:]]*$ ]]; then
                continue
            fi

            # Sprawdź czy linia zawiera nazwę naszego skryptu i monitora
            if [[ "$line" == *"$script_name"* && "$line" == *"$monitor_name"* ]]; then
                # Wyciągnij definicję crona (pierwsze 5 pól lub zapis @coś)
                if [[ "$line" =~ ^[[:space:]]*([*0-9,-/]+[[:space:]]+[*0-9,-/]+[[:space:]]+[*0-9,-/]+[[:space:]]+[*0-9,-/]+[[:space:]]+[*0-9,-/]+)[[:space:]]+ ]]; then
                    cron_schedule="${BASH_REMATCH[1]}"
                elif [[ "$line" =~ ^[[:space:]]*(@[a-z]+)[[:space:]]+ ]]; then
                    cron_schedule="${BASH_REMATCH[1]}"
                fi

                # Znaleźliśmy dopasowanie, przerywamy pętlę
                break
            fi
        done <<< "$user_crontab"
    fi

    # Jeśli nie znaleźliśmy w crontab użytkownika, spróbuj w systemowych
    if [[ -z "$cron_schedule" ]]; then
        local system_crontab_files=("/etc/crontab" "/etc/cron.d/"*)

        for crontab_file in "${system_crontab_files[@]}"; do
            if [[ -f "$crontab_file" ]]; then
                while IFS= read -r line; do
                    # Pomijamy komentarze i puste linie
                    if [[ "$line" =~ ^[[:space:]]*# || "$line" =~ ^[[:space:]]*$ ]]; then
                        continue
                    fi

                    # Sprawdź czy linia zawiera nazwę naszego skryptu i monitora
                    if [[ "$line" == *"$script_name"* && "$line" == *"$monitor_name"* ]]; then
                        # Wyciągnij definicję crona (pierwsze 5 pól lub zapis @coś)
                        if [[ "$line" =~ ^[[:space:]]*([*0-9,-/]+[[:space:]]+[*0-9,-/]+[[:space:]]+[*0-9,-/]+[[:space:]]+[*0-9,-/]+[[:space:]]+[*0-9,-/]+)[[:space:]]+ ]]; then
                            cron_schedule="${BASH_REMATCH[1]}"
                        elif [[ "$line" =~ ^[[:space:]]*(@[a-z]+)[[:space:]]+ ]]; then
                            cron_schedule="${BASH_REMATCH[1]}"
                        fi

                        # Znaleźliśmy dopasowanie, przerywamy pętlę
                        break
                    fi
                done < "$crontab_file"

                # Jeśli znaleźliśmy definicję, przerywamy sprawdzanie kolejnych plików
                if [[ -n "$cron_schedule" ]]; then
                    break
                fi
            fi
        done
    fi

    # Jeśli wciąż nie znaleźliśmy definicji, to może być zadanie w /etc/cron.{hourly,daily,weekly,monthly}
    if [[ -z "$cron_schedule" ]]; then
        # Sprawdź czy skrypt znajduje się w jednym z katalogów cron.*
        if [[ "$script_path" == "/etc/cron.hourly/"* ]]; then
            cron_schedule="@hourly"
        elif [[ "$script_path" == "/etc/cron.daily/"* ]]; then
            cron_schedule="@daily"
        elif [[ "$script_path" == "/etc/cron.weekly/"* ]]; then
            cron_schedule="@weekly"
        elif [[ "$script_path" == "/etc/cron.monthly/"* ]]; then
            cron_schedule="@monthly"
        fi
    fi

    # Jeśli znaleźliśmy definicję crona, zwróć ją
    if [[ -n "$cron_schedule" ]]; then
        echo "$cron_schedule"
        return 0
    fi

    # Nie udało się wykryć definicji
    return 1
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
    local template="%s %s run %s %s"
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