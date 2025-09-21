#!/usr/bin/env bash
set -euo pipefail

PROJECT_NAME="mantle2"
SITE_DIR="/tmp/drupal-$PROJECT_NAME"
SITE_NAME="Drupal $PROJECT_NAME Test"
SRC_PATH="$(realpath .)"

if [ ! -d "$SITE_DIR" ]; then
  echo ">>> Creating new Drupal site in $SITE_DIR"
  echo ">>> Using Path: $SRC_PATH"

  composer create-project drupal/recommended-project "$SITE_DIR"

  cd "$SITE_DIR"

  composer require drush/drush drupal/json_field drupal/key drupal/smtp

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

  ddev config --project-type=drupal11 --docroot=web --project-name="$PROJECT_NAME" --host-webserver-port=8787

  ddev start

  ddev drush -y site:install minimal \
    --account-name=admin \
    --account-pass=admin \
    --site-name="$SITE_NAME"

  ddev drush -y en field datetime options json_field key smtp node user comment
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

echo
echo ">>> Drupal site with $PROJECT_NAME is ready!"
echo "Project directory: $SITE_DIR"
