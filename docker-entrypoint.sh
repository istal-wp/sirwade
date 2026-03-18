#!/bin/bash
set -e

echo "======================================"
echo "  BRIGHTPATH Loogistics — Boot"
echo "======================================"

# ── Ensure uploads directory exists ─────────────────────────
mkdir -p /var/www/html/uploads/resumes
chown -R www-data:www-data /var/www/html/uploads
chmod -R 775 /var/www/html/uploads
echo "✅ Upload directories ready."

echo "🚀 Starting Apache..."
exec "$@"
