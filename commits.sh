#!/bin/bash
# commits.sh — Wrapper für commits.php mit JSON-Config
# Usage:
#   ./commits.sh [OUTPUT_FILE] [--config=git.json]

OUTPUT_FILE=""
CONFIG_PATH="git.json"   # Default
POSITIONAL_ARGS=()

for arg in "$@"; do
  case $arg in
    --config=*)
      CONFIG_PATH="${arg#*=}"
      ;;
    *)
      POSITIONAL_ARGS+=("$arg")
      ;;
  esac
done

# Ausgabedatei (positional, default)
if [ ${#POSITIONAL_ARGS[@]} -ge 1 ]; then
  OUTPUT_FILE="${POSITIONAL_ARGS[0]}"
else
  OUTPUT_FILE="commits.txt"
fi

SCRIPT_DIR=$(dirname "$(realpath "$0")")
PHP_SCRIPT="$SCRIPT_DIR/commits.php"

if [ ! -f "$PHP_SCRIPT" ]; then
  echo "❌ commits.php nicht gefunden in $SCRIPT_DIR"
  exit 1
fi

if [ ! -f "$CONFIG_PATH" ]; then
  echo "❌ Config-Datei nicht gefunden: $CONFIG_PATH"
  exit 1
fi

OUTPUT_PATH="$PWD/$OUTPUT_FILE"

# PHP ausführen: erster Parameter = Config-Path, zweiter = Output-Path
CMD="php \"$PHP_SCRIPT\" \"$CONFIG_PATH\" \"$OUTPUT_PATH\""

eval "$CMD"

status=$?
if [ $status -ne 0 ]; then
  echo "❌ commits.php meldete Exit-Code $status"
  exit $status
fi

echo "✅ Ausgabe gespeichert: $OUTPUT_PATH"
