#!/usr/bin/env bash
# Prepares the Docker test site for the Playwright role suite: installs WordPress,
# activates Kratt and creates one user per role. Test mode (dummy AI responses) is
# set in docker-compose.yml. Safe to re-run against an existing site.
#
# Nothing here may write into the WordPress files: the cli container's user can't.
# Permalinks stay plain, so tests reach the REST API through ?rest_route=.
set -euo pipefail

WP_URL="http://localhost:8080"

wp() {
  docker compose exec -T cli wp --allow-root --path=/var/www/html "$@"
}

# Probe from inside the container: a sandboxed host shell may have its own
# loopback and never see the published port.
echo "==> Waiting for WordPress..."
MAX_TRIES=60
TRIES=0
until docker compose exec -T wordpress curl -sf http://localhost/wp-login.php > /dev/null 2>&1; do
  TRIES=$((TRIES + 1))
  if [ "$TRIES" -ge "$MAX_TRIES" ]; then
    echo "ERROR: WordPress failed to start after ${MAX_TRIES} attempts"
    echo "Run 'docker compose logs wordpress' to see what went wrong"
    exit 1
  fi
  sleep 2
done

echo "==> Installing WordPress..."
wp core install \
  --url="${WP_URL}" \
  --title="Kratt Test Site" \
  --admin_user="admin" \
  --admin_password="password" \
  --admin_email="admin@example.com" \
  --skip-email || echo "Already installed."

echo "==> Activating plugin..."
wp plugin activate kratt || {
  echo "ERROR: Plugin activation failed."
  exit 1
}

echo "==> Creating role users..."
for ROLE in editor author contributor subscriber; do
  if ! wp user get "${ROLE}" --field=ID > /dev/null 2>&1; then
    wp user create "${ROLE}" "${ROLE}@example.com" --role="${ROLE}" --user_pass=password
  fi
done

echo "==> Creating the admin-owned post used by the edit_post checks..."
if [ -z "$(wp post list --post_type=post --name=kratt-admin-post --field=ID --format=ids 2>/dev/null || true)" ]; then
  wp post create \
    --post_type=post \
    --post_title="Kratt admin post" \
    --post_name="kratt-admin-post" \
    --post_status=publish \
    --post_author=1 \
    --porcelain
fi

echo ""
echo "==> Done. WordPress at ${WP_URL} (admin / password; editor, author, contributor, subscriber / password)"
