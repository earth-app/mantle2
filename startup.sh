#!/usr/bin/env bash
set -euo pipefail

PROJECT_NAME="mantle2"
SITE_DIR="/tmp/drupal-$PROJECT_NAME"
SITE_NAME="Drupal $PROJECT_NAME Test"
SRC_PATH="$(realpath .)"

if [ ! -d "$SITE_DIR" ]; then
  echo ">>> Creating new Drupal site in $SITE_DIR"
  echo ">>> Using Path: $SRC_PATH"

  composer create-project --no-interaction drupal/recommended-project "$SITE_DIR"

  cd "$SITE_DIR"

  composer require --no-interaction drush/drush drupal/json_field drupal/key drupal/smtp drupal/redis drupal/smtp drupal/openid_connect:^3.0@alpha

  mkdir -p web/modules/custom/$PROJECT_NAME
  find "$SRC_PATH" -maxdepth 1 -name "*.php" \
    -o -name "*.yml" \
    -o -name "*.yaml" \
    -o -name "*.info" \
    -o -name "*.module" \
    -o -name "*.install" \
    -o -name "*.inc" \
    -o -name "*.json" \
    | xargs -I {} cp {} web/modules/custom/$PROJECT_NAME/
  [ -d "$SRC_PATH/src" ] && cp -r "$SRC_PATH/src" web/modules/custom/$PROJECT_NAME/
  [ -d "$SRC_PATH/config" ] && cp -r "$SRC_PATH/config" web/modules/custom/$PROJECT_NAME/
  [ -d "$SRC_PATH/templates" ] && cp -r "$SRC_PATH/templates" web/modules/custom/$PROJECT_NAME/

  echo ">>> Configuring ddev for Drupal 11"

  ddev config --project-type=drupal11 --docroot=web --project-name="$PROJECT_NAME" --host-webserver-port=8787 --database=mariadb:10.11

  ddev start

  ddev drush -y site:install minimal \
    --account-name=admin \
    --account-pass=admin \
    --site-name="$SITE_NAME"

  ddev drush -y en field datetime options json_field key smtp node user comment redis openid_connect
  ddev drush cr
  ddev drush -y en "$PROJECT_NAME" || true
else
  echo ">>> Reusing existing site at $SITE_DIR"
  echo ">>> Using Path: $SRC_PATH"
  cd "$SITE_DIR"

  ddev drush un "$PROJECT_NAME" -y

  mkdir -p web/modules/custom/$PROJECT_NAME
  find "$SRC_PATH" -maxdepth 1 -name "*.php" \
    -o -name "*.yml" \
    -o -name "*.yaml" \
    -o -name "*.info" \
    -o -name "*.module" \
    -o -name "*.install" \
    -o -name "*.inc" \
    -o -name "*.json" \
    | xargs -I {} cp {} web/modules/custom/$PROJECT_NAME/
  [ -d "$SRC_PATH/src" ] && cp -r "$SRC_PATH/src" web/modules/custom/$PROJECT_NAME/
  [ -d "$SRC_PATH/config" ] && cp -r "$SRC_PATH/config" web/modules/custom/$PROJECT_NAME/
  [ -d "$SRC_PATH/templates" ] && cp -r "$SRC_PATH/templates" web/modules/custom/$PROJECT_NAME/

  ddev drush cr
  ddev drush updb -y
  ddev drush en "$PROJECT_NAME" -y
fi

BASE_URLS=(
  "https://${PROJECT_NAME}.ddev.site"
  "http://127.0.0.1:8787"
)

verify_endpoint() {
  local base="$1"
  local path="$2"
  local expect="$3"
  local url="${base}${path}"
  local max_attempts=30
  local attempt=1
  local headers body http_code ctype

  echo ">>> Pinging ${url} (expecting ${expect})"

  while [ "$attempt" -le "$max_attempts" ]; do
    headers="$(mktemp)"
    body="$(mktemp)"
    http_code="$(curl -sS -k -D "$headers" -o "$body" -w '%{http_code}' "$url" 2>/dev/null || echo 000)"

    if [ "$http_code" = "200" ]; then
      ctype="$(grep -i '^content-type:' "$headers" | tail -n1 | tr -d '\r')"
      if [ "$expect" = "json" ] && ! echo "$ctype" | grep -qi 'application/json'; then
        echo "    FAIL: HTTP 200 but content-type is not JSON (${ctype})"
        head -c 500 "$body"; echo
        rm -f "$headers" "$body"
        return 1
      fi
      echo "    OK [${http_code}] ${ctype}"
      head -c 500 "$body"; echo
      rm -f "$headers" "$body"
      return 0
    fi

    rm -f "$headers" "$body"
    echo "    attempt ${attempt}/${max_attempts}: HTTP ${http_code}, retrying..."
    attempt=$((attempt + 1))
    sleep 2
  done

  echo ">>> ${url} did not respond after ${max_attempts} attempts"
  return 1
}

echo
echo ">>> Verifying mantle2 endpoints"
for base in "${BASE_URLS[@]}"; do
  verify_endpoint "$base" /v2/hello text
  verify_endpoint "$base" /v2/info json
done

echo
echo ">>> Drupal site with $PROJECT_NAME is ready!"
echo "Project directory: $SITE_DIR"
echo "Base URLs:"
for base in "${BASE_URLS[@]}"; do
  echo "  - ${base}"
done
