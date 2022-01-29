#!/bin/bash -e

# This runs the tests using mysql for the database
# This requires that the expected database was created beforehand

# Find out directory of current script
# to make it possible to run this script from any location
if [ -L "$0" ] && [ -x $(which readlink) ]; then
  THIS_FILE="$(readlink -mn "$0")"
else
  THIS_FILE="$0"
fi
SCRIPT_DIR="$(dirname "$THIS_FILE")"

# Setup the environment variables to use mysql
export DB_TYPE=mysql
export MYSQL_HOST=127.0.0.1 # giving ip instead of string "localhost" to use tcp so it work even when mysql is in a docker container (where socket is not available)
export MYSQL_DATABASE=test_slack_db
export MYSQL_USER=slack-app-test-user
export MYSQL_PASSWORD=slack-app-test-pass

echo "Going to run the tests using mysql. If tests fail ensure you:"
echo "- have a mysql server on host $MYSQL_HOST"
echo "- on which a database called $MYSQL_DATABASE is created"
echo "- on which user $MYSQL_USER has permission to CREATE and DROP tables"
echo "- and that this user has password $MYSQL_PASSWORD"
echo "It can be setup with those mysql commands:"
echo ""
echo "    CREATE DATABASE $MYSQL_DATABASE;"
echo "    CREATE USER '$MYSQL_USER'@'$MYSQL_HOST' IDENTIFIED BY '$MYSQL_PASSWORD';"
echo "    GRANT ALL PRIVILEGES ON * . * TO '$MYSQL_USER'@'$MYSQL_HOST';"
echo ""

# Run tests
pushd "$SCRIPT_DIR"
../vendor/bin/phpunit --coverage-html coverage  ./unitTests/
popd
