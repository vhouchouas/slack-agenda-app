#!/bin/bash -e

# This script runs the unit tests using sqlite so it doesn't require particular setup beforehand

# Find out directory of current script
# to make it possible to run this script from any location
if [ -L "$0" ] && [ -x $(which readlink) ]; then
  THIS_FILE="$(readlink -mn "$0")"
else
  THIS_FILE="$0"
fi
SCRIPT_DIR="$(dirname "$THIS_FILE")"

# Run tests
pushd "$SCRIPT_DIR"
XDEBUG_MODE=coverage ../vendor/bin/phpunit --coverage-html coverage  ./unitTests/
popd
