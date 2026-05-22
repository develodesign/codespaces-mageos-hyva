# Ensure supervisor runs in daemon mode
if sudo grep -q "^nodaemon=true" /etc/supervisor/supervisord.conf; then
    sudo sed -i '/^nodaemon=true/d' /etc/supervisor/supervisord.conf
fi

SUPERVISOR_PID_FILE="/var/run/supervisord.pid"
if [ -f "$SUPERVISOR_PID_FILE" ] && ps -p $(cat $SUPERVISOR_PID_FILE) > /dev/null 2>&1; then
    echo "Supervisor is running. Reloading configuration..."
    sudo supervisorctl reread
    sudo supervisorctl update
else
    echo "Supervisor not running or PID file is stale. Starting new daemon..."
    sudo rm -f /var/run/supervisor.sock "$SUPERVISOR_PID_FILE"
    sudo /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
fi

# ======================================================================================
# Wait for Services to become ready
# ======================================================================================

# Wait for MariaDB
echo "Waiting for MySQL to be ready..."
if ! timeout 60 bash -c 'until sudo mariadb-admin ping --silent; do echo "Waiting..." && sleep 2; done'; then
    echo "Error: MySQL did not become available within 60 seconds."
    exit 1
fi
echo "MySQL is ready!"

# Configure MySQL root user with password
echo "Configuring MySQL root user..."
sudo mariadb -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${MYSQL_ROOT_PASSWORD}'; FLUSH PRIVILEGES;" 2>/dev/null || true

# Grant root access from any host for PHPMyAdmin
echo "Granting MySQL root access from any host..."
sudo mariadb -e "CREATE USER IF NOT EXISTS 'root'@'%' IDENTIFIED BY '${MYSQL_ROOT_PASSWORD}'; GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION; FLUSH PRIVILEGES;" 2>/dev/null || true

# Wait for OpenSearch
echo "Waiting for OpenSearch to be ready..."
if ! timeout 120 bash -c 'until curl -s -f http://localhost:9200/_cluster/health?wait_for_status=yellow > /dev/null; do echo "Waiting..." && sleep 5; done'; then
    echo "Error: OpenSearch did not become available within 120 seconds."
    docker logs $OPENSEARCH_CONTAINER
    exit 1
fi
echo "OpenSearch is ready!"