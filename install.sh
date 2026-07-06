#!/usr/bin/env bash
# ============================================================
# Instalação do Emissor NFS-e Goiânia em Ubuntu (22.04/24.04)
# Uso: bash install.sh
# ============================================================
set -euo pipefail

echo "==> Instalando PHP e extensões necessárias..."
sudo apt-get update
sudo apt-get install -y \
    php-cli php-curl php-xml php-mbstring php-sqlite3 \
    unzip curl ca-certificates

echo "==> Instalando Composer (se necessário)..."
if ! command -v composer >/dev/null 2>&1; then
    EXPECTED_CHECKSUM="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    if [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]; then
        echo "ERRO: checksum do instalador do Composer não confere." >&2
        exit 1
    fi
    sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm /tmp/composer-setup.php
fi

echo "==> Instalando dependências do projeto..."
composer install --no-dev --optimize-autoloader

echo "==> Preparando diretórios e configuração..."
mkdir -p storage/xml
chmod +x bin/nfse
if [ ! -f .env ]; then
    cp .env.example .env
    echo ""
    echo "*** Edite o arquivo .env com o caminho/senha do certificado A1 ***"
    echo "*** e o CNPJ + Inscrição Municipal da sua empresa.             ***"
fi

echo ""
echo "Instalação concluída. Teste com:"
echo "  bin/nfse dados-cadastrais        (valida certificado + conexão com o SGISS)"
echo "  bin/nfse emitir --arquivo examples/nota.json --dry-run"
