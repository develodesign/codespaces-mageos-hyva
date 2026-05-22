# Codespaces + Mage-OS + Hyvä Development Environment

A complete GitHub Codespaces development setup for Mage-OS (Magento Open Source) with Hyvä theme integration. This configuration provides a fully-featured, pre-configured development environment that launches in minutes.

## Stack

- **PHP**: 8.5-FPM
- **Web Server**: Nginx
- **Database**: MariaDB 11.4
- **Search**: OpenSearch 2.19.2
- **Cache & Sessions**: Valkey 8 (Redis-compatible)
- **Mail Testing**: Mailpit
- **Node.js**: 20.x (upgraded to the latest release at startup via `n`)
- **Database Management**: phpMyAdmin
- **Platform Version**: Mage-OS 3.0 | Magento 2.4.9
- **Theme**: Hyvä

## Features

- **Flexible Platform Installation**: Choose between Mage-OS or Magento via `PLATFORM_NAME` Env value
- **Sample Data Installation**: Optional sample data installation via `INSTALL_SAMPLE_DATA` flag
- Pre-configured services (Nginx, MariaDB, Valkey, OpenSearch)
- Hyvä theme build automation
- Docker-in-Docker support for additional containers (Mailpit, OpenSearch, phpMyAdmin)
- n98-magerun2 CLI tool pre-installed
- AI CLI tools (Gemini CLI, Claude Code) pre-installed
- Magento Claude Agents collection auto-installed
- Persistent installation detection (skips reinstall on restart)
- Xdebug pre-installed for debugging

## Prerequisites

- GitHub account with Codespaces access
- Required secrets configured in your repository:
  - `HYVA_LICENCE_KEY`: Hyvä license key (token for authentication)
  - `HYVA_PROJECT_NAME`: Hyvä project name for Packagist repository access

- Optional secrets (only needed if `PLATFORM_NAME=magento`):
  - `MAGENTO_COMPOSER_AUTH_USER`: Adobe Commerce Marketplace username
  - `MAGENTO_COMPOSER_AUTH_PASS`: Adobe Commerce Marketplace password


## Getting Started

1. **Create a new Codespace** from this repository

2. **Automated Setup Process**:

   The setup runs in two phases:

   **Phase 1 - Container Creation** (`setup.sh` via `onCreateCommand` runs during Pre-build if enabled):
   - Installs AI CLI tools (Gemini CLI, Claude Code)
   - Starts Docker containers (Mailpit, OpenSearch, phpMyAdmin)

   **Phase 2 - Application Setup** (`start.sh` via `postAttachCommand`):
   - Configures and starts Supervisor services (Nginx, MariaDB, Valkey, PHP-FPM)
   - Installs Node.js using `n` package manager
   - Creates project using `composer create-project`:
     - **If `PLATFORM_NAME=mage-os`**: Installs Mage-OS from https://repo.mage-os.org/
     - **If `PLATFORM_NAME=magento`**: Installs Magento from https://repo.magento.com/
   - Installs sample data (if `INSTALL_SAMPLE_DATA=YES`)
   - Installs fresh instance or uses existing database
   - Installs Awesome Claude Agents from GitHub
   - Builds the Hyvä theme (if license key provided)
   - Creates `.devcontainer/db-installed.flag` to skip reinstall on subsequent starts

3. **Access your store**:
   - Frontend: `https://[your-codespace-name]-8080.app.github.dev/`
   - Admin Panel: `https://[your-codespace-name]-8080.app.github.dev/admin`

## Default Credentials

### Magento Admin
- **Username**: `admin`
- **Password**: `password1`
- **Email**: `admin@example.com`

### Database
- **Root Password**: `password`
- **Database Name**: `magento2`

## Available Services & Ports

| Service | Port | Description |
|---------|------|-------------|
| Nginx | 8080 | Magento web interface |
| MariaDB | 3306 | Database server |
| phpMyAdmin | 8081 | Database management UI |
| Valkey | 6379 | Cache and session storage (Redis-compatible) |
| OpenSearch | 9200 | Search engine API |
| OpenSearch Node | 9600 | OpenSearch node communication |
| Mailpit SMTP | 1025 | Mail SMTP server |
| Mailpit Web | 8025 | Mail testing UI |

## Common Commands

### Hyvä Theme
```bash
# Build Hyvä theme
n98-magerun2 dev:theme:build-hyva

# Build specific theme
n98-magerun2 dev:theme:build-hyva frontend/Hyva/default
```

### n98-magerun2
```bash
# List all commands
n98-magerun2 list

# Check system info
n98-magerun2 sys:info

# Check module status
n98-magerun2 module:list
```

### Service Management
```bash
# Check all service status (custom script)
.devcontainer/scripts/status.sh

# Check Supervisor services
sudo supervisorctl status

# Restart a service
sudo supervisorctl restart nginx
sudo supervisorctl restart php-fpm

# Reload Supervisor configuration (after config changes)
sudo supervisorctl reread
sudo supervisorctl update

# Check Docker containers
docker ps

# View container logs
docker logs mailpit
docker logs opensearch-node
docker logs phpmyadmin
```

### Database Access
```bash
# Database CLI access (use the mariadb client; the legacy `mysql` name is deprecated)
mariadb -u root -ppassword magento2

# Or use n98-magerun2
n98-magerun2 db:console

#PHP MyAdmin Port 8081
https://{{Codespaces-URL}}-8081.app.github.dev/
```

## Configuration Files

Key configuration files are located in `.devcontainer/`:

**Config Directory** (`.devcontainer/config/`):
- `nginx.conf` - Nginx web server configuration
- `sp-php-fpm.conf` - PHP-FPM supervisor configuration
- `mysql.cnf` - MariaDB server configuration
- `mysql.conf` - MariaDB supervisor configuration
- `client.cnf` - MySQL client configuration
- `sp-valkey.conf` - Valkey supervisor configuration
- `sp-nginx.conf` - Nginx supervisor configuration
- `sp-opensearch.conf` - OpenSearch supervisor configuration (if used)
- `env.php` - Pre-configured Magento environment file (for existing installations)

**Scripts Directory** (`.devcontainer/scripts/`):
- `setup.sh` - Initial setup (runs during container creation)
- `start.sh` - Application startup (runs on container attach)
- `start_services.sh` - Modular service management (sourced by start.sh)
- `status.sh` - Service status checker

## Troubleshooting

### Services Not Starting
Check supervisor status:
```bash
sudo supervisorctl status
```

Restart all services:
```bash
sudo supervisorctl restart all
```

Re-run start script
```bash
.devcontainer/scripts/start.sh
```

### Database Connection Issues
Verify MariaDB is running:
```bash
sudo mariadb-admin ping
```

Check MariaDB logs:
```bash
sudo tail -f /var/log/mysql/error.log
```

### OpenSearch Issues
Check OpenSearch status:
```bash
curl http://localhost:9200/_cluster/health?pretty
```

View OpenSearch logs:
```bash
docker logs opensearch-node
```

### Clear Magento Cache
```bash
bin/magento cache:flush
bin/magento cache:clean
rm -rf var/cache/* var/page_cache/* generated/*
```

### Reinstallation
To trigger a fresh installation, delete the flag file:
```bash
rm .devcontainer/db-installed.flag
```

Then restart the Codespace. The `start.sh` script will detect the missing flag and run the full installation process again, including:
- Fresh Magento installation (if `INSTALL_MAGENTO=YES`)
- Database recreation
- Composer dependencies installation
- Hyvä theme configuration
- All setup steps from scratch

**Note**: The flag file is created near the end of `start.sh` to prevent reinstallation on subsequent container restarts.

## Development Workflow

1. **Make code changes** in your IDE
2. **Clear Magento cache** if needed: `bin/magento cache:flush`
3. **Rebuild Hyvä theme** if template changes: `n98-magerun2 dev:theme:build-hyva`
4. **Test changes** in your browser
5. **Commit and push** to your repository

## Notes

- The first startup may take 10-15 minutes as it installs Magento and all dependencies (Enable Pre-builds to cut new installs to 5mins)
- Subsequent instance starts are much faster (2-3 minutes) as the `.devcontainer/db-installed.flag` prevents reinstallation
- The environment uses Valkey (a Redis-compatible store) for sessions, cache, and full page cache
- OpenSearch runs in a Docker container with security disabled for development ease
- Xdebug is installed but not enabled by default
- Awesome Claude Agents are automatically cloned and installed to `~/.claude/agents`
- X-frame-options are patched to allow Magento's quick view functionality
- Services are managed through Supervisor with automatic restart policies
- Docker containers (Mailpit, OpenSearch, phpMyAdmin) have `--restart unless-stopped` policies

## Advanced Configuration

### Choosing Between Mage-OS and Magento

By default, this environment installs **Mage-OS** (set via `PLATFORM_NAME=mage-os`). To install Magento instead:

1. Edit `.devcontainer/devcontainer.json`:
   ```json
   "PLATFORM_NAME": "magento"
   ```

2. Ensure you have configured the required Magento Composer credentials:
   - `MAGENTO_COMPOSER_AUTH_USER`
   - `MAGENTO_COMPOSER_AUTH_PASS`

**Key Differences**:
- **Mage-OS**: Community-driven fork, no Adobe Marketplace access by default
- **Magento**: Official Adobe version, requires Marketplace credentials, access to Marketplace extensions

**Note**: If using Mage-OS and you need Marketplace extensions, you'll need to configure `repo.magento.com` separately with appropriate credentials.

### Changing Magento Version
Edit the `MAGENTO_VERSION` value under `containerEnv` in `.devcontainer/devcontainer.json`:
```json
"MAGENTO_VERSION": "${localEnv:MAGENTO_VERSION:2.4.9}"
```
This only applies when `PLATFORM_NAME=magento`; Mage-OS is installed from `repo.mage-os.org` and tracks its own release.

### Using an Existing Magento Database
To skip fresh installation and use an existing database:
1. Set `INSTALL_MAGENTO: "NO"` in `.devcontainer/devcontainer.json`
2. Place your pre-configured `env.php` in `.devcontainer/config/env.php`
3. The `start.sh` script will copy this file to `app/etc/env.php` and update the base URL

### Adding Custom Composer Repositories
Edit your `composer.json` or use:
```bash
composer config repositories.custom-repo vcs https://github.com/your/repo
```

### Installing Sample Data

Sample data provides products, categories, and content for testing and development. To control sample data installation:

1. Edit `.devcontainer/devcontainer.json`:
   ```json
   "INSTALL_SAMPLE_DATA": "YES"
   ```

2. Or set to `"NO"` to skip sample data installation for a clean, minimal installation.

**What gets installed**:
- Sample products (bundle, configurable, downloadable, grouped)
- Sample categories and catalog structure
- Sample CMS pages and blocks
- Sample customers and reviews
- Sample sales data and tax rules
- Product and CMS media images (via the `${PLATFORM_NAME}/sample-data-media` package)

**Note**: Sample data installation adds approximately 5-10 minutes to the initial setup time and requires additional disk space (~500MB).

**Important**: The sample-data media package (`sample-data-media`) is required and staged into `pub/media` **before** `setup:upgrade` runs. The catalog import reads product images from `pub/media/catalog/product`; if the media is missing at import time the import aborts early and most categories end up empty. The Media Gallery is also temporarily disabled during the import to avoid noisy "no such media asset" exceptions while CMS sample blocks are saved.

### Environment Variables
All environment variables can be customized in `.devcontainer/devcontainer.json` under `containerEnv`:

**Key Environment Variables**:
- `PLATFORM_NAME` - Set to "mage-os" for Mage-OS, "magento" for Magento (default: "mage-os")
- `INSTALL_MAGENTO` - Set to "YES" for fresh install, "NO" to use existing database (default: "YES")
- `INSTALL_SAMPLE_DATA` - Set to "YES" to install sample data, "NO" to skip (default: "YES")
- `MAGENTO_VERSION` - Magento version to install when `PLATFORM_NAME=magento` (default: "2.4.9")
- `MAGENTO_ADMIN_USERNAME` - Admin username (default: "admin")
- `MAGENTO_ADMIN_PASSWORD` - Admin password (default: "password1")
- `MAGENTO_ADMIN_EMAIL` - Admin email (default: "admin@example.com")
- `MYSQL_ROOT_PASSWORD` - MySQL root password (default: "password")
- `HYVA_LICENCE_KEY` - Your Hyvä license token (required for Hyvä installation)
- `HYVA_PROJECT_NAME` - Your Hyvä project name for Packagist access (required for Hyvä installation)

## License

This development environment configuration is provided as-is. Individual components (Magento, Hyvä, etc.) have their own licenses.

## Support

For issues with:
- **Magento**: Refer to [Mage-OS Documentation](https://mage-os.org/)
- **Hyvä Theme**: Refer to [Hyvä Documentation](https://docs.hyva.io/)
- **This Setup**: Open an issue in this repository
- **Learning Course with optional Slack channel support [Free Course](https://develo.teachable.com/p/codespaces-magento-mageos-hyva)
