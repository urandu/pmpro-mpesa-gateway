#!/bin/bash

mkdir wordpress-site && cd wordpress-site

touch docker-compose.yml

cat > docker-compose.yml <<EOL
version: "2"
services:
  my-wpdb:
    image: mariadb
    ports:
      - "8081:3306"
    environment:
      MYSQL_ROOT_PASSWORD: ChangeMeIfYouWant
  my-wp:
    image: wordpress
    volumes:
      - ./:/var/www/html
    ports:
      - "8080:80"
    links:
      - my-wpdb:mysql
    environment:
      WORDPRESS_DB_PASSWORD: ChangeMeIfYouWant
EOL

docker-compose up -d