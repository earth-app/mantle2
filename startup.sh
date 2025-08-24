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

  composer require drush/drush
  composer require drupal/json_field

  mkdir -p web/modules/custom/$PROJECT_NAME
  cp -R "$SRC_PATH"/* web/modules/custom/$PROJECT_NAME

  ddev config --project-type=drupal11 --docroot=web --project-name="$PROJECT_NAME"

  ddev drush install json_field
  ddev drush en json_field -y

  ddev start

  ddev drush site:install standard \
    --account-name=admin \
    --account-pass=admin \
    --site-name="$SITE_NAME" -y
else
  echo ">>> Reusing existing site at $SITE_DIR"
  echo ">>> Using Path: $SRC_PATH"
  cd "$SITE_DIR"

  mkdir -p web/modules/custom/$PROJECT_NAME
  cp -R "$SRC_PATH"/* web/modules/custom/$PROJECT_NAME

  ddev restart
fi

ddev drush en "$PROJECT_NAME" -y || true

echo
echo ">>> Drupal site with $PROJECT_NAME is ready!"
echo "Project directory: $SITE_DIR"