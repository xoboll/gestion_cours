FROM php:8.2-apache

# Extension PostgreSQL pour PDO (nécessaire pour se connecter à Supabase)
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

# Copier tout le projet dans le dossier servi par Apache
COPY . /var/www/html/

# Render fournit le port réel à utiliser via la variable d'environnement PORT
# UNIQUEMENT au démarrage du conteneur (pas à la construction de l'image) —
# la substitution doit donc se faire dans CMD, pas dans une étape RUN.
EXPOSE 10000
CMD sed -i "s/80/${PORT:-10000}/g" /etc/apache2/ports.conf /etc/apache2/sites-enabled/000-default.conf && apache2-foreground
