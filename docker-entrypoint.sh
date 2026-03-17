#!/bin/bash
set -e

echo "======================================"
echo "  BRIGHTPATH Loogistics — Boot"
echo "======================================"

# ── Resolve env vars (Railway uses MYSQL*, fallback to DB_*) ──
HOST="${MYSQLHOST:-${DB_HOST:-localhost}}"
PORT="${MYSQLPORT:-${DB_PORT:-3306}}"
USER="${MYSQLUSER:-${DB_USER:-root}}"
PASS="${MYSQLPASSWORD:-${DB_PASS:-}}"
DB_NAME="${MYSQLDATABASE:-${DB_NAME:-loogistics}}"

echo "🔗 Connecting to MySQL at ${HOST}:${PORT} as ${USER}..."

# ── Wait for MySQL to be ready ───────────────────────────────
echo "⏳ Waiting for MySQL..."
MAX_TRIES=60
COUNT=0
until mysql -h "$HOST" -P "$PORT" -u "$USER" -p"$PASS" \
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

# ── Create database if it doesn't exist ─────────────────────
echo "🗄  Ensuring database '$DB_NAME' exists..."
mysql -h "$HOST" -P "$PORT" -u "$USER" -p"$PASS" \
      -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# ── Import schema if tables don't exist ─────────────────────
TABLE_COUNT=$(mysql -h "$HOST" -P "$PORT" -u "$USER" -p"$PASS" \
      -se "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';" 2>/dev/null || echo "0")

if [ "$TABLE_COUNT" -lt "5" ]; then
  echo "📦 Importing database schema..."
  mysql -h "$HOST" -P "$PORT" -u "$USER" -p"$PASS" \
        "$DB_NAME" < /var/www/html/loogistics.sql
  echo "✅ Schema imported successfully."
else
  echo "✅ Database already has $TABLE_COUNT tables — skipping import."
fi

# ── Ensure uploads directory exists ─────────────────────────
mkdir -p /var/www/html/uploads/resumes
chown -R www-data:www-data /var/www/html/uploads
chmod -R 775 /var/www/html/uploads
echo "✅ Upload directories ready."

echo "🚀 Starting Apache..."
exec "$@"