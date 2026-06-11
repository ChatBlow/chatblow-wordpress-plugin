#!/bin/bash

# Name of the zip file
ZIP_NAME="chatblow.zip"

# Remove existing zip if it exists
if [ -f "$ZIP_NAME" ]; then
    rm "$ZIP_NAME"
fi

# Create a zip of the plugin, including only .php and readme.txt files
# We exclude the build.sh itself implicitly by not including *.sh in the -i list
echo "Creating $ZIP_NAME..."
zip -r "$ZIP_NAME" . -i "*.php" "readme.txt"

echo "Done!"
