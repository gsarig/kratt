#!/bin/bash

# Exit if any command fails
set -e

# Change to the project root directory
cd "$(dirname "$0")/.."

# Name of the plugin
PLUGIN_SLUG="kratt"

# 1. Determine Version
# ------------------------------------------------------------------------------

# Use the first argument as the version if provided
if [ ! -z "$1" ]; then
	VERSION="$1"
	echo "Using provided version: $VERSION"
else
	# Otherwise, extract it from the main PHP file
	if [ -f "kratt.php" ]; then
		VERSION=$(grep "Version:" kratt.php | awk '{print $NF}' | tr -d '\r')
		echo "Detected version from file: $VERSION"
	else
		echo "Error: kratt.php not found!"
		exit 1
	fi
fi

if [ -z "$VERSION" ]; then
	echo "Error: Could not determine version."
	exit 1
fi

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
BUILD_DIR="${PLUGIN_SLUG}"

echo "Building $ZIP_NAME..."

# 2. Build Assets
# ------------------------------------------------------------------------------
echo "Installing JS dependencies and building assets..."
npm ci && npm run build

echo "Installing production Composer dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress

# 3. Cleanup Previous Builds
# ------------------------------------------------------------------------------
rm -rf "$BUILD_DIR"
rm -f "$ZIP_NAME"

# 4. Copy Files
# ------------------------------------------------------------------------------
mkdir -p "$BUILD_DIR"

RSYNC_EXCLUDE=""
if [ -f ".distignore" ]; then
	RSYNC_EXCLUDE="--exclude-from=.distignore"
fi

rsync -av $RSYNC_EXCLUDE \
	--exclude="${BUILD_DIR}" \
	--exclude="*.zip" \
	./ "$BUILD_DIR/"

# 5. Create ZIP
# ------------------------------------------------------------------------------
zip -q -r "$ZIP_NAME" "$BUILD_DIR"

# 6. Cleanup
# ------------------------------------------------------------------------------
rm -rf "$BUILD_DIR"

echo "-----------------------------------------------------------------"
echo "Success! Plugin zip created: $ZIP_NAME"
echo "-----------------------------------------------------------------"
