#!/usr/bin/env bash

set -e

test_database="${MYSQL_TEST_DATABASE:-gateway_logs_test}"

case "${test_database}" in
    ''|*[!a-zA-Z0-9_]*)
        echo "Invalid MySQL test database name: ${test_database}" >&2
        exit 1
        ;;
esac

mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS ${test_database}
        CHARACTER SET utf8mb4
        COLLATE utf8mb4_unicode_ci;
    GRANT ALL PRIVILEGES ON ${test_database}.* TO '${MYSQL_USER}'@'%';
EOSQL
