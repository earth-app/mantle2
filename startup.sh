#!/usr/bin/env bash
set -euo pipefail

PROJECT_NAME="mantle2"
SITE_DIR="/tmp/drupal-$PROJECT_NAME"
SITE_NAME="Drupal $PROJECT_NAME Test"
SRC_PATH="$(realpath .)"

MANTLE2_ADMIN_KEY="${MANTLE2_ADMIN_KEY}"
MANTLE2_CLOUD_ENDPOINT="${MANTLE2_CLOUD_ENDPOINT:-http://127.0.0.1:9898}"
export MANTLE2_ADMIN_KEY MANTLE2_CLOUD_ENDPOINT

persist_mantle2_settings() {
  local settings_file="web/sites/default/settings.ddev.php"
  if [ ! -f "$settings_file" ]; then
    mkdir -p "$(dirname "$settings_file")"
    cat > "$settings_file" <<'PHP'
<?php
PHP
  fi

  tmpfile="$(mktemp)"
  if [ -s "$settings_file" ]; then
    grep -v "mantle2.cloud_endpoint" "$settings_file" > "$tmpfile" || true
    mv "$tmpfile" "$settings_file"
  fi

  cat >> "$settings_file" <<EOF
\$settings['mantle2.cloud_endpoint'] = '${MANTLE2_CLOUD_ENDPOINT}';
EOF
}

create_or_update_admin_key() {
  ddev drush php:eval "
    use Drupal\key\Entity\Key;
    \Drupal::service('entity_type.manager')->getStorage('key')->load('mantle2_admin_key')?->delete();
    Key::create([
      'id' => 'mantle2_admin_key',
      'label' => 'Mantle2 Admin Key',
      'description' => 'Admin key for Mantle2 module',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_input' => 'text',
      'key_value' => '${MANTLE2_ADMIN_KEY}',
    ])->save();
  "
}

if [ ! -d "$SITE_DIR" ]; then
  echo ">>> Creating new Drupal site in $SITE_DIR"
  echo ">>> Using Path: $SRC_PATH"

  composer create-project drupal/recommended-project "$SITE_DIR"
  cd "$SITE_DIR"

  composer require drush/drush
  composer require drupal/json_field drupal/key

  mkdir -p web/modules/custom/$PROJECT_NAME
  cp -R "$SRC_PATH"/* web/modules/custom/$PROJECT_NAME

  persist_mantle2_settings

  ddev config --project-type=drupal11 --docroot=web --project-name="$PROJECT_NAME" --host-webserver-port=8787
  ddev start

  ddev drush -y site:install standard \
    --account-name=admin \
    --account-pass=admin \
    --site-name="$SITE_NAME"

  ddev drush -y en json_field key
  ddev drush -y en "$PROJECT_NAME" || true

  create_or_update_admin_key
else
  echo ">>> Reusing existing site at $SITE_DIR"
  echo ">>> Using Path: $SRC_PATH"
  cd "$SITE_DIR"

  mkdir -p web/modules/custom/$PROJECT_NAME
  cp -R "$SRC_PATH"/* web/modules/custom/$PROJECT_NAME

  persist_mantle2_settings

  ddev restart
  create_or_update_admin_key
fi

echo
echo ">>> Drupal site with $PROJECT_NAME is ready!"
echo "Project directory: $SITE_DIR"
