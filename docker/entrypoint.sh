#!/bin/bash
set -e

echo "=== Iniciando Contratos App ==="
echo "Nota: Si la base de datos no está lista, la app mostrará error temporal hasta que arranque."

exec "$@"
