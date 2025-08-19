#!/bin/bash

# Script to fetch OpenAPI specification from earth-app.com
# This will be used during CI or when network access is available

OPENAPI_URL="https://api.earth-app.com/openapi"
OUTPUT_FILE="tests/contract/openapi.json"

echo "Fetching OpenAPI specification from $OPENAPI_URL..."

if curl -f -s -o "$OUTPUT_FILE" "$OPENAPI_URL"; then
    echo "OpenAPI specification downloaded successfully to $OUTPUT_FILE"
    
    # Validate JSON
    if python3 -m json.tool "$OUTPUT_FILE" > /dev/null 2>&1; then
        echo "OpenAPI specification is valid JSON"
    else
        echo "Error: Downloaded OpenAPI specification is not valid JSON"
        exit 1
    fi
else
    echo "Error: Failed to download OpenAPI specification from $OPENAPI_URL"
    echo "This may be due to network restrictions or the API being unavailable"
    
    # Create a placeholder for development
    cat > "$OUTPUT_FILE" << 'EOF'
{
  "openapi": "3.0.0",
  "info": {
    "title": "Earth API",
    "version": "1.0.0",
    "description": "Earth application API - placeholder until real spec is fetched"
  },
  "paths": {
    "/api/health": {
      "get": {
        "summary": "Health check endpoint",
        "responses": {
          "200": {
            "description": "Service is healthy",
            "content": {
              "application/json": {
                "schema": {
                  "type": "object",
                  "properties": {
                    "status": { "type": "string" },
                    "timestamp": { "type": "string" }
                  }
                }
              }
            }
          }
        }
      }
    }
  }
}
EOF
    echo "Created placeholder OpenAPI specification"
fi