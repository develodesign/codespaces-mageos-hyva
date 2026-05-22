# Use the "bookworm" based PHP image
# PHP 8.5 is supported by Mage-OS 3.0.0 / Magento 2.4.9 (framework requires ~8.3 || ~8.4 || ~8.5)
FROM php:8.5-fpm-bookworm

# Environment variables to allow non-interactive installation
ENV MYSQL_ROOT_PASSWORD=password
ENV DEBIAN_FRONTEND=noninteractive

# Install system dependencies
RUN apt-get update && apt-get install -y \
    apt-utils \
    lsb-release \
    ca-certificates \
    curl \
    wget \
    gnupg \
    python3 \
    python-is-python3 \
    libmariadb-dev \
    rsync \
    libnss3-dev \
    openssh-client \
    jq \
    mc \
    gcc \
    make \
    autoconf \
    libc-dev \
    pkg-config \
    libmcrypt-dev \
    supervisor \
    nginx \
    locales \
    zip \
    unzip \
    git \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libxpm-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    libxslt1-dev \
    libicu-dev \
    libmagickwand-dev \
    vim \
    sudo

# Generate the en_US.UTF-8 locale (the Codespaces env sets LANG=en_US.UTF-8,
# and Valkey 8 fatally exits if that locale is not available)
RUN sed -i 's/^# *\(en_US.UTF-8\)/\1/' /etc/locale.gen && \
    locale-gen en_US.UTF-8
ENV LANG=en_US.UTF-8 \
    LANGUAGE=en_US:en \
    LC_ALL=en_US.UTF-8

# Install PHP extensions required for Magento
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-xpm && \
    docker-php-ext-install -j$(nproc) \
        gd \
        bcmath \
        intl \
        mbstring \
        soap \
        xml \
        zip \
        pdo_mysql \
        mysqli \
        sockets \
        ftp \
        xsl

# Install imagick from source (builds cleanly against current PHP; the released
# PECL tarball has historically lagged new PHP versions)
RUN git clone https://github.com/Imagick/imagick --depth 1 /tmp/imagick && \
    cd /tmp/imagick && phpize && ./configure && make && make install && \
    rm -rf /tmp/imagick && docker-php-ext-enable imagick
RUN pecl install redis xdebug && docker-php-ext-enable redis xdebug

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install MariaDB Server 11.4 (for Magento 2.4.9 compatibility)
RUN apt-get update && apt-get install -y software-properties-common dirmngr \
    && curl -LsSO https://downloads.mariadb.com/MariaDB/mariadb_repo_setup \
    && chmod +x mariadb_repo_setup \
    && ./mariadb_repo_setup --mariadb-server-version="11.4" \
    && apt-get update \
    && echo "mariadb-server-11.4 mysql-server/root_password password ${MYSQL_ROOT_PASSWORD}" | debconf-set-selections \
    && echo "mariadb-server-11.4 mysql-server/root_password_again password ${MYSQL_ROOT_PASSWORD}" | debconf-set-selections \
    && apt-get install -y mariadb-server mariadb-client \
    && mkdir -p /var/run/mysqld \
    && chown mysql:mysql /var/run/mysqld \
    && rm mariadb_repo_setup

# Install Valkey 8 (Redis-compatible cache/session store for Magento 2.4.9)
RUN echo "deb http://deb.debian.org/debian bookworm-backports main" > /etc/apt/sources.list.d/backports.list && \
    apt-get update && apt-get install -y -t bookworm-backports valkey-server

# Install Node.js and npm
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - && \
    apt-get install -y nodejs

# Install Java, a dependency for OpenSearch
RUN apt-get update && apt-get install -y openjdk-17-jdk-headless
ENV JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64

# Install n98-magerun2
RUN wget https://files.magerun.net/n98-magerun2.phar && \
    chmod +x ./n98-magerun2.phar && \
    mv ./n98-magerun2.phar /usr/local/bin/n98-magerun2

# Create vscode user
RUN groupadd --gid 1000 vscode && \
    useradd --uid 1000 --gid vscode --shell /bin/bash --create-home vscode && \
    echo 'vscode ALL=(ALL) NOPASSWD:ALL' >> /etc/sudoers

# Install Docker client to manage Mailpit (not the daemon)
RUN curl -fsSL https://get.docker.com -o get-docker.sh && \
    sh get-docker.sh && \
    rm get-docker.sh && \
    usermod -aG docker vscode

# Clean up
RUN apt-get autoremove -y && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Switch to vscode user
USER vscode
