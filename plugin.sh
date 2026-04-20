#!/bin/bash

#Enter your directory below where plugin is located or place the script in the plugin directory
PLUGIN_DIR="."

ZIP_FILE="$PLUGIN_DIR/woocommerce-plugin.zip"

EXCLUDE_LIST=(
    "$PLUGIN_DIR/.git/*"
    "$PLUGIN_DIR/.plugin.sh"
)


if [ -f "$ZIP_FILE" ]; then
  echo "The ZIP file '$ZIP_FILE' already exists."
  read -p "Do you want to overwrite it? (y/n): " choice
  if [[ "$choice" != "y" && "$choice" != "Y" ]]; then
    echo "Operation canceled. The ZIP file was not overwritten."
    exit 1
  fi
fi

EXCLUDE_PARAMS=()
for item in "${EXCLUDE_LIST[@]}"; do
    EXCLUDE_PARAMS+=("-x" "$item")
done

if zip -r "$ZIP_FILE" "$PLUGIN_DIR" "${EXCLUDE_PARAMS[@]}"; then
    echo "ZIP file created successfully: $ZIP_FILE"
else
    echo "Failed to create ZIP file. Please check file permissions, disk space, and try again."
    exit 1
fi