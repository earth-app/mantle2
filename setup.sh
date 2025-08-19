#!/bin/bash

# Mantle2 Development Setup Script
# This script sets up the development environment

set -e

echo "🚀 Setting up Mantle2 Development Environment"
echo "=============================================="

# Check if DDEV is available
if command -v ddev &> /dev/null; then
    echo "✓ DDEV found"
    USE_DDEV=true
else
    echo "⚠ DDEV not found - using manual setup"
    USE_DDEV=false
fi

# Function to run commands with or without DDEV
run_cmd() {
    if [ "$USE_DDEV" = true ]; then
        ddev exec "$1"
    else
        eval "$1"
    fi
}

# Start DDEV if available
if [ "$USE_DDEV" = true ]; then
    echo "📦 Starting DDEV..."
    ddev start
fi

# Install Composer dependencies
echo "📦 Installing Composer dependencies..."
if [ "$USE_DDEV" = true ]; then
    ddev composer install
else
    composer install
fi

# Set up Drupal if not already installed
echo "🏗️  Checking Drupal installation..."
if [ ! -f "web/sites/default/settings.php" ] || ! grep -q "database" web/sites/default/settings.php; then
    echo "🏗️  Installing Drupal..."
    
    if [ "$USE_DDEV" = true ]; then
        ddev drush site:install standard --yes --site-name="Mantle2" --account-name=admin --account-pass=admin
    else
        echo "⚠ Please configure your database in web/sites/default/settings.php"
        echo "⚠ Then run: vendor/bin/drush site:install standard --yes --site-name=\"Mantle2\""
    fi
else
    echo "✓ Drupal already installed"
fi

# Enable custom modules
echo "🔧 Enabling custom modules..."
if [ "$USE_DDEV" = true ]; then
    ddev drush en mantle_core -y || echo "⚠ Could not enable mantle_core module (this is normal if Drupal is not fully installed)"
else
    echo "⚠ Please enable modules manually: vendor/bin/drush en mantle_core -y"
fi

# Set file permissions
echo "🔐 Setting file permissions..."
if [ -d "web/sites/default/files" ]; then
    chmod -R 755 web/sites/default/files
fi

if [ -f "web/sites/default/settings.php" ]; then
    chmod 644 web/sites/default/settings.php
fi

# Create private files directory
echo "📁 Creating private files directory..."
mkdir -p private
chmod 755 private

echo ""
echo "✅ Setup completed!"
echo ""

if [ "$USE_DDEV" = true ]; then
    echo "🌐 Your site is available at: https://mantle2.ddev.site"
    echo ""
    echo "Useful commands:"
    echo "  ddev ssh          - Access the web container"
    echo "  ddev drush status - Check Drupal status"
    echo "  ddev logs         - View logs"
    echo "  ddev composer     - Run composer commands"
else
    echo "🌐 Configure your web server to point to the 'web' directory"
    echo ""
    echo "Next steps:"
    echo "  1. Configure your database in web/sites/default/settings.php"
    echo "  2. Run: vendor/bin/drush site:install"
    echo "  3. Run: vendor/bin/drush en mantle_core -y"
fi

echo ""
echo "Development commands:"
echo "  composer lint     - Check code style"
echo "  composer lint-fix - Fix code style issues"
echo "  composer analyze  - Run static analysis"
echo "  composer test     - Run unit tests"