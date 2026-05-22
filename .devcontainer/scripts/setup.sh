#!/bin/bash
set -eu

# Setup Runs during the Codespace Prebuild step add long running tasks here

# Define primary variables
CODESPACES_REPO_ROOT="${CODESPACES_REPO_ROOT:=$(pwd)}"
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:=password}"

# Change to the repository root directory
cd "${CODESPACES_REPO_ROOT}"

# Docker container names
MAILPIT_CONTAINER="mailpit"
OPENSEARCH_CONTAINER="opensearch-node"
PHPMYADMIN_CONTAINER="phpmyadmin"

# AI Packages
sudo npm install -g @google/gemini-cli
sudo npm install -g @anthropic-ai/claude-code

# Function to start a Docker container if not running
start_container() {
    local container_name=$1
    shift
    local docker_run_cmd=("$@")
    
    if [ ! "$(docker ps -q -f name=^/${container_name}$)" ]; then
        if [ "$(docker ps -aq -f status=exited -f name=^/${container_name}$)" ]; then
            echo "Removing stopped ${container_name} container..."
            docker rm $container_name
        fi
        echo "Starting ${container_name} container..."
        "${docker_run_cmd[@]}"
    else
        echo "${container_name} container is already running."
    fi
}

# Start Mailpit Container
start_container $MAILPIT_CONTAINER \
    docker run -d --restart unless-stopped --name $MAILPIT_CONTAINER \
    -p 8025:8025 -p 1025:1025 axllent/mailpit

# Start OpenSearch Container with security disabled
start_container $OPENSEARCH_CONTAINER \
    docker run -d --restart unless-stopped --name $OPENSEARCH_CONTAINER \
    -p 9200:9200 -p 9600:9600 \
    -e "discovery.type=single-node" \
    -e "OPENSEARCH_JAVA_OPTS=-Xms512m -Xmx512m" \
    -e "DISABLE_INSTALL_DEMO_CONFIG=true" \
    -e "plugins.security.disabled=true" \
    opensearchproject/opensearch:3.0.0

# Start phpMyAdmin Container - connects to the main container via Docker bridge gateway
start_container $PHPMYADMIN_CONTAINER \
    docker run -d --restart unless-stopped --name $PHPMYADMIN_CONTAINER \
    -p 8081:80 \
    -e PMA_HOST=172.17.0.1 \
    -e PMA_PORT=3306 \
    -e PMA_USER=root \
    -e PMA_PASSWORD=${MYSQL_ROOT_PASSWORD} \
    phpmyadmin/phpmyadmin


echo "============ 2. Setup Complete =========="
