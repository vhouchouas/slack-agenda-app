#!/bin/bash -e

# Find out directory of current script
# to make it possible to run this script from any location
if [ -L "$0" ] && [ -x $(which readlink) ]; then
  THIS_FILE="$(readlink -mn "$0")"
else
  THIS_FILE="$0"
fi
SCRIPT_DIR="$(dirname "$THIS_FILE")"

# Run tests
"$SCRIPT_DIR"/../vendor/bin/phpunit  "$SCRIPT_DIR/integrationTests/"
