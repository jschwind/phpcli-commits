#!/bin/bash

OUTPUT_FILE=""
CONFIG_PATH="git.json"
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

if [ ${#POSITIONAL_ARGS[@]} -ge 1 ]; then
  OUTPUT_FILE="${POSITIONAL_ARGS[0]}"
else
  OUTPUT_FILE="commits.txt"
fi

SCRIPT_DIR=$(dirname "$(realpath "$0")")
PHP_SCRIPT="$SCRIPT_DIR/commits.php"

if [ ! -f "$PHP_SCRIPT" ]; then
  echo "❌ commits.php not found in $SCRIPT_DIR"
  exit 1
fi

if [ ! -f "$CONFIG_PATH" ]; then
  echo "❌ Config file not found: $CONFIG_PATH"
  exit 1
fi

OUTPUT_PATH="$PWD/$OUTPUT_FILE"

CMD="php \"$PHP_SCRIPT\" \"$CONFIG_PATH\" \"$OUTPUT_PATH\""

eval "$CMD"

status=$?
if [ $status -ne 0 ]; then
  echo "❌ commits.php returned exit code $status"
  exit $status
fi

echo "✅ Output saved"
