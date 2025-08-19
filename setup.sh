#!/bin/bash

# Mantle2 Development Setup Script
# This script sets up the development environment using Docker Compose

set -e

echo "🚀 Setting up Mantle2 Development Environment"
echo "=============================================="

# Check if Docker and Docker Compose are available
if ! command -v docker &> /dev/null; then
    echo "❌ Docker not found. Please install Docker first."
    exit 1
fi

if ! command -v docker-compose &> /dev/null; then
    echo "❌ Docker Compose not found. Please install Docker Compose first."
    exit 1
fi

echo "✓ Docker and Docker Compose found"

# Copy environment file if it doesn't exist
if [ ! -f ".env" ]; then
    echo "📋 Creating .env file from template..."
    cp .env.example .env
    echo "✓ .env file created. Please review and update as needed."
fi

# Build and start services
echo "🏗️  Building Docker containers..."
docker-compose build

echo "🚀 Starting services..."
docker-compose up -d

# Wait for services to be ready
echo "⏳ Waiting for services to start..."
sleep 10

# Check if services are running
echo "🔍 Checking service status..."
docker-compose ps

# Install Composer dependencies
echo "📦 Installing Composer dependencies..."
docker-compose exec php composer install

# Fetch OpenAPI specification
echo "📥 Fetching OpenAPI specification..."
./scripts/fetch-openapi.sh

# Set up Drupal if not already installed
echo "🏗️  Checking Drupal installation..."
if ! docker-compose exec php drush status --field=bootstrap 2>/dev/null | grep -q "Successful"; then
    echo "🏗️  Installing Drupal..."
    docker-compose exec php drush site:install standard --yes \
        --site-name="Mantle2" \
        --account-name=admin \
        --account-pass=admin \
        --db-url=pgsql://earth:earth@postgres/earth
else
    echo "✓ Drupal already installed"
fi

# Enable custom modules
echo "🔧 Enabling custom modules..."
docker-compose exec php drush en earth_api redis simple_oauth tfa advancedqueue -y || echo "⚠ Some modules may not be available yet"

# Clear cache
echo "🧹 Clearing cache..."
docker-compose exec php drush cache:rebuild

# Set file permissions
echo "🔐 Setting file permissions..."
docker-compose exec php chmod -R 755 /var/www/html/web/sites/default/files 2>/dev/null || true
docker-compose exec php chmod 644 /var/www/html/web/sites/default/settings.php 2>/dev/null || true

# Create private files directory
echo "📁 Creating private files directory..."
mkdir -p private
chmod 755 private

echo ""
echo "✅ Setup completed!"
echo ""
echo "🌐 Your site is available at: http://localhost:8080"
echo ""
echo "Useful commands:"
echo "  make ssh          - Access the PHP container"
echo "  make drush CMD    - Run drush commands"
echo "  make logs         - View logs"
echo "  make test         - Run tests"
echo "  make qa           - Run quality assurance checks"
echo ""
echo "Services:"
echo "  Web:      http://localhost:8080"
echo "  MinIO:    http://localhost:9001 (admin: minio/miniosecret)"
echo "  Postgres: localhost:5432 (earth/earth)"
echo "  Redis:    localhost:6379"
echo ""
echo "Development commands:"
echo "  make lint         - Check code style"
echo "  make lint-fix     - Fix code style issues"
echo "  make analyze      - Run static analysis"
echo "  make test         - Run unit tests"
echo "  make test-contract - Run contract tests"