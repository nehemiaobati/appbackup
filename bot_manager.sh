#!/bin/bash

##
# This script manages the lifecycle of bot.php processes.
# It uses the bot configuration ID as the unique identifier.

# --- CONFIGURATION ---
# The script determines its own base directory.
BASE_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
# You can get this from your .env file or hardcode it.
PHP_EXECUTABLE_PATH="/usr/bin/php"
BOT_SCRIPT_PATH="${BASE_DIR}/bot.php"
PID_DIR="${BASE_DIR}/pids"     # A dedicated directory for PID files
LOG_DIR="${BASE_DIR}/logs"     # A dedicated directory for bot logs

# Ensure PID and Log directories exist
mkdir -p "$PID_DIR"
mkdir -p "$LOG_DIR"

# --- FUNCTIONS ---

start() {
    local config_id=$1
    local pid_file="${PID_DIR}/${config_id}.pid"
    local log_file="${LOG_DIR}/${config_id}.log"

    if [ -f "$pid_file" ]; then
        local pid=$(cat "$pid_file")
        if ps -p "$pid" > /dev/null; then
            echo "ERROR: Bot for config ID ${config_id} is already running with PID ${pid}."
            exit 1
        else
            echo "WARN: Stale PID file found. Removing it."
            rm "$pid_file"
        fi
    fi

    echo "Starting bot for config ID ${config_id}..."
    # Start the bot, redirect its output, run in background, and get its PID
    nohup "$PHP_EXECUTABLE_PATH" "$BOT_SCRIPT_PATH" "$config_id" > "$log_file" 2>&1 &
    local new_pid=$!

    # Check if the process started successfully (it might crash immediately)
    sleep 2 # Give it a couple of seconds to initialize or fail
    if ! ps -p "$new_pid" > /dev/null; then
        echo "ERROR: Bot for config ID ${config_id} failed to start. Check log: ${log_file}"
        exit 1
    fi

    # Save the new PID to its file
    echo "$new_pid" > "$pid_file"
    echo "SUCCESS: Bot started with PID ${new_pid}. Log: ${log_file}"
}

stop() {
    local config_id=$1
    local pid_file="${PID_DIR}/${config_id}.pid"

    if [ ! -f "$pid_file" ]; then
        echo "ERROR: Bot for config ID ${config_id} is not running (no PID file)."
        # As a fallback, search for the process and kill it if found
        pkill -f "${BOT_SCRIPT_PATH}[[:space:]]+${config_id}$"
        echo "INFO: Fallback kill command issued for any orphaned processes."
        exit 1
    fi

    local pid=$(cat "$pid_file")
    echo "Stopping bot for config ID ${config_id} (PID: ${pid})..."

    # Try to kill gracefully first (SIGTERM)
    kill "$pid"
    sleep 3 # Give ReactPHP time to handle the signal and shut down

    # Verify if it stopped
    if ps -p "$pid" > /dev/null; then
        echo "WARN: Bot did not stop gracefully. Forcing kill (SIGKILL)..."
        kill -9 "$pid"
        sleep 1
    fi

    # Final check
    if ps -p "$pid" > /dev/null; then
        echo "ERROR: Failed to stop process ${pid}."
        exit 1
    else
        echo "SUCCESS: Bot stopped."
        rm "$pid_file" # Clean up
    fi
}

# --- MAIN LOGIC ---
ACTION=$1
CONFIG_ID=$2

if [[ -z "$ACTION" || -z "$CONFIG_ID" ]]; then
    echo "Usage: $0 {start|stop} {config_id}"
    exit 1
fi

case "$ACTION" in
    start)
        start "$CONFIG_ID"
        ;;
    stop)
        stop "$CONFIG_ID"
        ;;
    *)
        echo "Invalid action. Usage: $0 {start|stop} {config_id}"
        exit 1
esac

exit 0
