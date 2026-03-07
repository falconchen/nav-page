FROM php:8.3-apache

# 启用 mod_alias
RUN a2enmod alias

# 添加 Alias 配置并设置目录权限
RUN echo "Alias /docs /docs" > /etc/apache2/conf-available/docs-alias.conf \
    && echo "<Directory /docs>" >> /etc/apache2/conf-available/docs-alias.conf \
    && echo "    Options Indexes" >> /etc/apache2/conf-available/docs-alias.conf \
    && echo "    Require all granted" >> /etc/apache2/conf-available/docs-alias.conf \
    && echo "</Directory>" >> /etc/apache2/conf-available/docs-alias.conf \
    && a2enconf docs-alias
