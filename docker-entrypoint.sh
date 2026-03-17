#!/bin/bash
set -e

echo "======================================"
echo "  BRIGHTPATH Loogistics — Boot"
echo "======================================"

# ── Wait for MySQL to be ready ──────────────────────────────
echo "⏳ Waiting for MySQL at ${DB_HOST:-localhost}:${DB_PORT:-3306}..."
MAX_TRIES=60
COUNT=0
until mysql -h "${MYSQLHOST:-localhost}" -P "${MYSQLPORT:-3306}" \
      -u "${MYSQLUSER:-root}" -p"${MYSQLPASSWORD:-}" \
      -e "SELECT 1" > /dev/null 2>&1; do
  COUNT=$((COUNT+1))
  if [ $COUNT -ge $MAX_TRIES ]; then
    echo "❌ MySQL did not become available in time. Exiting."
    exit 1
  fi
  echo "   Attempt $COUNT/$MAX_TRIES — retrying in 2s..."
  sleep 2
done
echo "✅ MySQL is ready."

# ── Create database if it doesn't exist ────────────────────
DB_NAME="${DB_NAME:-loogistics}"
echo "🗄  Ensuring database '$DB_NAME' exists..."
mysql -h "${DB_HOST:-localhost}" -P "${DB_PORT:-3306}" \
      -u "${DB_USER:-root}" -p"${DB_PASS:-}" \
      -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# ── Import schema if tables don't exist ────────────────────
TABLE_COUNT=$(mysql -h "${DB_HOST:-localhost}" -P "${DB_PORT:-3306}" \
      -u "${DB_USER:-root}" -p"${DB_PASS:-}" \
      -se "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';" 2>/dev/null || echo "0")

if [ "$TABLE_COUNT" -lt "5" ]; then
  echo "📦 Importing database schema..."
  mysql -h "${DB_HOST:-localhost}" -P "${DB_PORT:-3306}" \
        -u "${DB_USER:-root}" -p"${DB_PASS:-}" \
        "${DB_NAME}" < /var/www/html/loogistics.sql
  echo "✅ Schema imported successfully."
else
  echo "✅ Database already has $TABLE_COUNT tables — skipping import."
fi

# ── Ensure uploads directory exists ────────────────────────
mkdir -p /var/www/html/uploads/resumes
chown -R www-data:www-data /var/www/html/uploads
chmod -R 775 /var/www/html/uploads
echo "✅ Upload directories ready."

echo "🚀 Starting Apache..."
exec "$@"
