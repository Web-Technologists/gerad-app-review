#!/usr/bin/env bash

# Shopify Store Onboarding Automation Script
# Instantly registers a new store domain, configures webhooks, metafields, and starts product catalog replication.

set -e

# Output Styling Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${BLUE}=================================================================${NC}"
echo -e "${BLUE}        Shopify UPI Code Manager - Store Onboarding Script       ${NC}"
echo -e "${BLUE}=================================================================${NC}"

# Find project directory
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ ! -f "$DIR/artisan" ]; then
    echo -e "${RED}Error: Laravel Artisan executable not found in '$DIR'.${NC}"
    exit 1
fi

# Run Onboarding command passing all script arguments
echo -e "${YELLOW}Executing onboarding process...${NC}"
php "$DIR/artisan" shopify:onboard "$@"

echo -e "${GREEN}Process complete! Run 'php artisan queue:work' if your queue is not daemonized.${NC}"
echo -e "${BLUE}=================================================================${NC}"
exit 0
