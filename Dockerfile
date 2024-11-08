# Usar uma imagem base do PHP 8.0
FROM php:8.0-cli

# Instalar a extensão MySQLi necessária para o PHP
RUN docker-php-ext-install mysqli

# Definir o diretório de trabalho
WORKDIR /var/www/html

# Copiar todos os arquivos do projeto para o diretório de trabalho
COPY . /var/www/html

# Expor a porta 8000 para a aplicação
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/var/www/html"]
