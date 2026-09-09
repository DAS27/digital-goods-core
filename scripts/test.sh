#!/bin/sh
set -eu

if [ "${APP_ENV:-}" != "test" ]; then
    echo "Refusing to run acceptance controls: APP_ENV=test is required." >&2
    exit 2
fi

cd "$(dirname "$0")/.."
exec "${PYTHON_BIN:-python3}" -m unittest discover -s tests -p 'test_*.py' -v "$@"
