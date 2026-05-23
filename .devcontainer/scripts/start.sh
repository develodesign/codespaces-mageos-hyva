#!/bin/bash

set -eu

# ======================================================================================
# Environment and Service Configuration
# ======================================================================================
CODESPACES_REPO_ROOT="${CODESPACES_REPO_ROOT:=$(pwd)}"
COMPOSER_COMMAND="php -d memory_limit=-1 $(which composer)"
OPENSEARCH_CONTAINER="opensearch-node"

# ======================================================================================
# Environment Ready Message
# ======================================================================================
show_ready_message() {
  echo "============ Environment Ready =========="
  echo "All services started successfully!"
  echo "You can check service status with: .devcontainer/scripts/status.sh"
  echo "And Docker containers with: docker ps"
  echo "Have an awesome time! 💙 Develo.co.uk"
}

# ======================================================================================
# Build the Hyvä theme CSS
# ======================================================================================
# We invoke the Tailwind build via npm directly instead of
# "n98-magerun2 dev:theme:build-hyva". That command forces TTY mode on its npm
# subprocess (failing with "TTY mode requires /dev/tty" in non-interactive
# container startup) and, without -p, runs Tailwind in watch mode which never
# returns. Running "npm run build" produces the same minified styles.css.
HYVA_TAILWIND_DIR="vendor/hyva-themes/magento2-default-theme/web/tailwind"
build_hyva_assets() {
  echo "Building Hyvä theme CSS (Tailwind)..."
  npm --prefix "${HYVA_TAILWIND_DIR}" install --no-audit --no-fund
  # Tailwind v4's loader triggers Node's DEP0205 (module.register) deprecation
  # warning on recent Node releases. It is harmless noise during a one-off CSS
  # build, so silence deprecation warnings for this subprocess only.
  NODE_OPTIONS="--no-deprecation" npm --prefix "${HYVA_TAILWIND_DIR}" run build
}

# ======================================================================================
# Supervisor Services (Nginx, MariaDB, Redis)
# ======================================================================================
echo "Configuring Supervisor services..."

# Create runtime directory for Nginx before starting it
sudo mkdir -p /var/run/nginx

# Copy config files
sudo cp "${CODESPACES_REPO_ROOT}/.devcontainer/config/nginx.conf" /etc/nginx/nginx.conf
sudo sed -i "s|__CODESPACES_REPO_ROOT__|${CODESPACES_REPO_ROOT}|g" /etc/nginx/nginx.conf
sudo cp "${CODESPACES_REPO_ROOT}/.devcontainer/config/sp-php-fpm.conf" /etc/supervisor/conf.d/
sudo sed -i "s|\$CODESPACES_REPO_ROOT|${CODESPACES_REPO_ROOT}|g" /etc/supervisor/conf.d/sp-php-fpm.conf
sudo cp "${CODESPACES_REPO_ROOT}/.devcontainer/config/sp-valkey.conf" /etc/supervisor/conf.d/
sudo cp "${CODESPACES_REPO_ROOT}/.devcontainer/config/mysql.conf" /etc/supervisor/conf.d/
sudo cp "${CODESPACES_REPO_ROOT}/.devcontainer/config/sp-nginx.conf" /etc/supervisor/conf.d/
sudo cp "${CODESPACES_REPO_ROOT}/.devcontainer/config/mysql.cnf" /etc/mysql/conf.d/
sudo cp "${CODESPACES_REPO_ROOT}/.devcontainer/config/client.cnf" /etc/mysql/conf.d/
sudo cp "${CODESPACES_REPO_ROOT}/.devcontainer/config/.gitignore" ${CODESPACES_REPO_ROOT}/.gitignore

cd "${CODESPACES_REPO_ROOT}"

# Start services AFTER permissions are set
source "${CODESPACES_REPO_ROOT}/.devcontainer/scripts/start_services.sh"

if [ -f ".devcontainer/db-installed.flag" ]; then
  echo "${PLATFORM_NAME} already installed, skipping installation/import."
  if [ "${HYVA_LICENCE_KEY}" ]; then
    build_hyva_assets
    echo "Hyvä theme configured successfully"
  fi;
  show_ready_message
  exit 0
else
    sudo npm install -g n
    sudo n latest

    echo "============ 1. Setup ${PLATFORM_NAME} Environment =========="

    # Check if composer.json exists, if not create the project
    if [ ! -f "composer.json" ]; then
        echo "**** Creating ${PLATFORM_NAME} project ****"
        echo "Updating PHP Memory Limit"
        echo "memory_limit=2G" | sudo tee -a /usr/local/etc/php/conf.d/docker-fpm.ini

        # Configure Composer to allow insecure packages
        echo "Configuring Composer to bypass security advisories..."
        ${COMPOSER_COMMAND} config --global audit.block-insecure false

        # Create project in temp directory then move files
        TEMP_DIR=$(mktemp -d)
        echo "Using temporary directory: ${TEMP_DIR}"

        if [ "${PLATFORM_NAME}" = "mage-os" ]; then
            echo "Installing Mage-OS from https://repo.mage-os.org/"
            ${COMPOSER_COMMAND} create-project --repository-url=https://repo.mage-os.org/ ${PLATFORM_NAME}/project-community-edition ${TEMP_DIR} --no-interaction
        else
            echo "Installing Magento from https://repo.magento.com/"
            if [ -n "${MAGENTO_COMPOSER_AUTH_USER}" ] && [ -n "${MAGENTO_COMPOSER_AUTH_PASS}" ]; then
                ${COMPOSER_COMMAND} config --global http-basic.repo.magento.com ${MAGENTO_COMPOSER_AUTH_USER} ${MAGENTO_COMPOSER_AUTH_PASS}
            fi
            ${COMPOSER_COMMAND} create-project --repository-url=https://repo.magento.com/ magento/project-community-edition:${MAGENTO_VERSION} ${TEMP_DIR} --no-interaction
        fi

        echo "Moving files from temporary directory to project root..."
        # Move all files except .git and .devcontainer
        shopt -s dotglob
        for file in ${TEMP_DIR}/*; do
            filename=$(basename "$file")
            if [ "$filename" != ".git" ] && [ "$filename" != ".devcontainer" ]; then
                mv "$file" ./ 2>/dev/null || echo "Skipping $filename"
            fi
        done
        shopt -u dotglob
        rm -rf ${TEMP_DIR}
        echo "Project files moved successfully"
        
    else
        echo "**** composer.json exists, running composer install ****"
        echo "Updating PHP Memory Limit"
        echo "memory_limit=2G" | sudo tee -a /usr/local/etc/php/conf.d/docker-fpm.ini

        # Configure Composer to allow insecure packages
        echo "Configuring Composer to bypass security advisories..."
        ${COMPOSER_COMMAND} config --global audit.block-insecure false

        ${COMPOSER_COMMAND} install --no-dev --optimize-autoloader 
    fi

    # Install Sample Data if enabled
    if [ "${INSTALL_SAMPLE_DATA}" = "YES" ]; then
        echo "============ Installing Sample Data =========="
        echo "**** Deploying ${PLATFORM_NAME} sample data ****"
        # Require the sample data modules and install them via composer. We do
        # NOT run "bin/magento sampledata:deploy" here: it bootstraps Magento
        # before the app is installed (no env.php / empty generated/) and exits
        # non-zero on PHP 8.4, aborting the script under "set -e". The composer
        # require + update below pulls the same packages, and the sample data is
        # loaded into the DB by setup:upgrade after setup:install.
        # NOTE: ${PLATFORM_NAME}/sample-data-media is REQUIRED here. The sample-data
        # modules ship only CSV fixtures + code; the actual product/CMS images live in
        # the sample-data-media package (it is merely "suggest"ed by module-sample-data,
        # so it is never pulled in automatically). Without it the catalog import cannot
        # read pub/media/catalog/product and aborts, leaving most categories empty.
        ${COMPOSER_COMMAND} require ${PLATFORM_NAME}/module-bundle-sample-data ${PLATFORM_NAME}/module-widget-sample-data ${PLATFORM_NAME}/module-theme-sample-data ${PLATFORM_NAME}/module-catalog-sample-data ${PLATFORM_NAME}/module-customer-sample-data ${PLATFORM_NAME}/module-cms-sample-data ${PLATFORM_NAME}/module-catalog-rule-sample-data ${PLATFORM_NAME}/module-sales-rule-sample-data ${PLATFORM_NAME}/module-review-sample-data ${PLATFORM_NAME}/module-tax-sample-data ${PLATFORM_NAME}/module-sales-sample-data ${PLATFORM_NAME}/module-grouped-product-sample-data ${PLATFORM_NAME}/module-downloadable-sample-data ${PLATFORM_NAME}/module-msrp-sample-data ${PLATFORM_NAME}/module-configurable-sample-data ${PLATFORM_NAME}/module-product-links-sample-data ${PLATFORM_NAME}/module-wishlist-sample-data ${PLATFORM_NAME}/module-swatches-sample-data ${PLATFORM_NAME}/sample-data-media --no-update
        ${COMPOSER_COMMAND} update
        echo "**** Sample data deployed successfully ****"
    fi

   # Decide whether to run a fresh install or import a database
   if [ "${INSTALL_MAGENTO}" = "YES" ]; then
    echo "============ Installing New ${PLATFORM_NAME} Instance ============"
    mariadb -u root -p${MYSQL_ROOT_PASSWORD} -e "CREATE DATABASE IF NOT EXISTS magento2;"

    url="https://${CODESPACE_NAME}-8080.app.github.dev/"
    echo "Installing ${PLATFORM_NAME} with URL: $url"
    
    php -d memory_limit=-1 bin/magento setup:install \
      --db-name='magento2' \
      --db-user='root' \
      --db-host='127.0.0.1' \
      --db-password="${MYSQL_ROOT_PASSWORD}" \
      --base-url="$url" \
      --backend-frontname='admin' \
      --admin-user="${MAGENTO_ADMIN_USERNAME}" \
      --admin-password="${MAGENTO_ADMIN_PASSWORD}" \
      --admin-email="${MAGENTO_ADMIN_EMAIL}" \
      --admin-firstname='Admin' \
      --admin-lastname='User' \
      --language='en_GB' \
      --currency='GBP' \
      --timezone='Europe/London' \
      --use-rewrites='1' \
      --use-secure='1' \
      --base-url-secure="$url" \
      --use-secure-admin='1' \
      --session-save='valkey' \
      --session-save-valkey-host='127.0.0.1' \
      --session-save-valkey-port='6379' \
      --cache-backend='valkey' \
      --cache-backend-valkey-server='127.0.0.1' \
      --cache-backend-valkey-db='1' \
      --page-cache='valkey' \
      --page-cache-valkey-server='127.0.0.1' \
      --page-cache-valkey-db='2' \
      --search-engine='opensearch' \
      --opensearch-host='localhost' \
      --opensearch-port='9200'

    # Run setup:upgrade if sample data was installed
    if [ "${INSTALL_SAMPLE_DATA}" = "YES" ]; then
      # The catalog product import reads images from pub/media/catalog/product, so the
      # sample-data media MUST be staged (and readable) BEFORE setup:upgrade runs the
      # import. Otherwise it fails with "File directory 'pub/media/catalog/product' is
      # not readable" and only a handful of products are created.
      SAMPLE_MEDIA_SOURCE="vendor/${PLATFORM_NAME}/sample-data-media"
      mkdir -p pub/media/catalog/product
      if [ -d "$SAMPLE_MEDIA_SOURCE" ]; then
        echo "Staging sample data media into pub/media before import..."
        rsync -a "${SAMPLE_MEDIA_SOURCE}/" pub/media/
      else
        echo "WARNING: ${SAMPLE_MEDIA_SOURCE} not found - product/CMS images will be missing."
      fi
      chmod -R a+rX pub/media

      # The Media Gallery cms_*_save_after observers try to link every <img> in the
      # sample-data CMS blocks/pages to a media_gallery_asset row. That table is only
      # populated asynchronously by media-gallery:sync, so during import every image
      # logs a critical "There is no such media asset" exception. Disable the media
      # gallery for the import to silence them; it is re-enabled immediately after.
      php -d memory_limit=-1 bin/magento config:set system/media_gallery/enabled 0

      echo "============ Running setup:upgrade to install sample data =========="
      php -d memory_limit=-1 bin/magento setup:upgrade

      php -d memory_limit=-1 bin/magento config:set system/media_gallery/enabled 1
    fi

    if [ "${HYVA_LICENCE_KEY}" ] && [ "${HYVA_PROJECT_NAME}" ]; then
        echo "**** Configuring Hyvä Theme ****"
        ${COMPOSER_COMMAND} config --auth http-basic.hyva-themes.repo.packagist.com token ${HYVA_LICENCE_KEY}
        ${COMPOSER_COMMAND} config repositories.private-packagist composer https://hyva-themes.repo.packagist.com/${HYVA_PROJECT_NAME}/
        ${COMPOSER_COMMAND} require hyva-themes/magento2-default-theme

        echo "**** Activating Hyvä Theme ****"
        # Run setup:upgrade to register the new theme
        php -d memory_limit=-1 bin/magento setup:upgrade

        # Set Hyva as the active theme
        php -d memory_limit=-1 bin/magento config:set design/theme/theme_id 5 --scope=default --scope-code=0

        echo "Hyvä theme installed and activated"
    fi

else
  echo "============ ${PLATFORM_NAME} is installed, copying CS env.php ============"
  cp ${CODESPACES_REPO_ROOT}/.devcontainer/config/env.php ${CODESPACES_REPO_ROOT}/app/etc/env.php
  sed -i "s|codespaces.domain|https://${CODESPACE_NAME}-8080.app.github.dev|g" ${CODESPACES_REPO_ROOT}/app/etc/env.php
fi
  php -d memory_limit=-1 bin/magento deploy:mode:set developer
  php -d memory_limit=-1 bin/magento setup:di:compile
  php -d memory_limit=-1 bin/magento module:disable Magento_TwoFactorAuth
  php -d memory_limit=-1 bin/magento config:set catalog/search/engine opensearch
  php -d memory_limit=-1 bin/magento config:set catalog/search/opensearch_server_hostname localhost
  php -d memory_limit=-1 bin/magento config:set catalog/search/opensearch_server_port 9200
  php -d memory_limit=-1 bin/magento indexer:reindex
  php -d memory_limit=-1 bin/magento cache:flush

  # Install Claude agents
  git clone https://github.com/rubenzantingh/claude-code-magento-agents
  mkdir -p ~/.claude/agents
  cp -r "$(pwd)/claude-code-magento-agents/" ~/.claude/agents
  rm -rf ./claude-code-magento-agents

  # Add mage alias for bin/magento
  echo "alias mage='bin/magento'" >> ~/.bashrc

  ## MISC
  echo "Patch the X-frame-options to allow quick view"
  url="https://${CODESPACE_NAME}-8080.app.github.dev/"
  target="${CODESPACES_REPO_ROOT}/vendor/${PLATFORM_NAME}/framework/App/Response/HeaderProvider/XFrameOptions.php"
  sed -i "s|\$this->headerValue = \$xFrameOpt;|\$this->headerValue = '${url}';|" "$target"
  # echo "Fetching Media Files"        
  # ./mc cp wasabi/clients.bamford/bam_media.zip ${CODESPACES_REPO_ROOT}/bam_media.zip
  # unzip -o ${CODESPACES_REPO_ROOT}/bam_media.zip -d ${CODESPACES_REPO_ROOT}/pub/ && rm ./bam_media.zip
fi

show_ready_message

touch "${CODESPACES_REPO_ROOT}/.devcontainer/db-installed.flag"

if [ "${HYVA_LICENCE_KEY}" ]; then
  echo "Final Hyvä theme configuration..."

  # Build Hyva theme assets
  build_hyva_assets

  # Deploy static content for Hyva theme
  echo "Deploying static content for Hyvä theme..."
  php -d memory_limit=-1 bin/magento setup:static-content:deploy -f -t Hyva/default

  # Clear cache to ensure theme changes are visible
  php -d memory_limit=-1 bin/magento cache:flush

  echo "Hyvä theme fully configured and ready"
fi;

  # Fix permissions after Magento installation/configuration
  echo "Setting proper file permissions after Magento setup..."

  # Read permissions for nginx (nobody user) - using + for efficiency
  echo "Setting read permissions for nginx worker..."
  sudo find "${CODESPACES_REPO_ROOT}" -type d -exec chmod o+rx {} + 2>/dev/null || true
  sudo find "${CODESPACES_REPO_ROOT}" -type f -exec chmod o+r {} + 2>/dev/null || true

  # Write permissions for PHP-FPM (vscode user) on writable directories
  echo "Setting ownership and permissions for writable directories..."

  # Ensure critical directories exist
  mkdir -p "${CODESPACES_REPO_ROOT}/var/view_preprocessed" 2>/dev/null || true
  mkdir -p "${CODESPACES_REPO_ROOT}/var/page_cache" 2>/dev/null || true
  mkdir -p "${CODESPACES_REPO_ROOT}/generated" 2>/dev/null || true

  # Set ownership on writable directories
  for dir in var generated pub/static pub/media app/etc; do
      if [ -d "${CODESPACES_REPO_ROOT}/${dir}" ]; then
          sudo chown -R vscode:vscode "${CODESPACES_REPO_ROOT}/${dir}" 2>/dev/null || true
          sudo find "${CODESPACES_REPO_ROOT}/${dir}" -type f -exec chmod 664 {} + 2>/dev/null || true
          sudo find "${CODESPACES_REPO_ROOT}/${dir}" -type d -exec chmod 775 {} + 2>/dev/null || true
      fi
  done

  # Ensure bin/magento is executable
  if [ -f "${CODESPACES_REPO_ROOT}/bin/magento" ]; then
      sudo chmod +x "${CODESPACES_REPO_ROOT}/bin/magento"
  fi

  echo "File permissions updated successfully"


# ======================================================================================
# Post-import media processing
# ======================================================================================
# Sample data media is now staged into pub/media BEFORE setup:upgrade (see above), so
# here we only need to generate the resized product image cache and populate the media
# gallery index, then flush caches.
if [ "${INSTALL_SAMPLE_DATA}" = "YES" ] && [ -f "bin/magento" ]; then
    echo "Resizing product images and syncing the media gallery..."
    php -d memory_limit=-1 bin/magento media-gallery:sync || true
    php -d memory_limit=-1 bin/magento cache:flush
    echo "Sample data media processing complete."
fi
