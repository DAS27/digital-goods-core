#!/bin/sh
set -eu
find src public supplier bin -type f \( -name '*.php' -o -name console \) -exec php -l {} \;
php -l bootstrap.php
python3 -m py_compile tests/test_acceptance.py
