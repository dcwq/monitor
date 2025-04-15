#!/bin/bash

# Test skryptu z losowym błędem
# Generuje losowe powodzenie lub niepowodzenie
# Zwraca szczegółowy output w przypadku błędu

# Losowa wartość 0 lub 1
EXIT_CODE=$((RANDOM % 2))

# Generuj trochę losowego outputu
echo "Running test with possible random failures..."
echo "Current time: $(date)"
echo "Current directory: $(pwd)"
echo "Environment variables:"
env | sort | head -n 10

# Zapisz ID procesu do wykorzystania w logu błędu
PID=$$
echo "Process ID: $PID"

# Symuluj jakoby wykonanie zadania
for i in {1..5}; do
    echo "Processing step $i..."
    sleep 1
done

# Jeśli kod wyjścia ma być błędny, generuj szczegółowy output błędu
if [ $EXIT_CODE -ne 0 ]; then
    echo "ERROR: Something went wrong during execution!"
    echo "Debug information:"
    echo "-------------------"
    echo "System information:"
    uname -a
    echo
    echo "Memory usage:"
    free -h 2>/dev/null || echo "Memory info not available"
    echo
    echo "Disk usage:"
    df -h . 2>/dev/null || echo "Disk info not available"
    echo
    echo "Running processes:"
    ps aux | grep -E "bash|$PID" | head -n 5
    echo
    echo "Stack trace (simulated):"
    echo "  at function TestRandomFail() line 42"
    echo "  at function ExecuteJob() line 25"
    echo "  at function Main() line 10"

    # Losowy typ błędu
    ERROR_TYPES=("Connection timeout" "Resource not available" "Permission denied" "Invalid input" "Runtime exception")
    RANDOM_ERROR=${ERROR_TYPES[$((RANDOM % ${#ERROR_TYPES[@]}))]}

    echo
    echo "Error type: $RANDOM_ERROR"
    echo "Error happened at $(date +%T)"

    # Generuj jeszcze więcej linii do logu, żeby testować scrollowanie
    for i in {1..10}; do
        echo "Additional debug line $i: Random data $(head /dev/urandom | tr -dc 'a-zA-Z0-9' | head -c 10)"
    done
fi

echo "Test completed with exit code: $EXIT_CODE"

# Wyjdź z odpowiednim kodem
exit $EXIT_CODE