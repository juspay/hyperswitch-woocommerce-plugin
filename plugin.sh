#!/bin/bash

#Enter your directory below where plugin is located or place the script in the plugin directory
PLUGIN_DIR="."

ZIP_FILE="$PLUGIN_DIR/woocommerce-plugin.zip"

EXCLUDE_LIST=(
    "$PLUGIN_DIR/.git/*"
    "$PLUGIN_DIR/.plugin.sh"
)


if [ -f "$ZIP_FILE" ]; then
  echo "Regular ZIP file exists."
else
  echo "ZIP file does not exist."
fi

EXCLUDE_PARAMS=()
for item in "${EXCLUDE_LIST[@]}"; do
    EXCLUDE_PARAMS+=("-x" "$item")
done

zip -r "$ZIP_FILE" "$PLUGIN_DIR" "${EXCLUDE_PARAMS[@]}"

echo "zip file created: $ZIP_FILE"