# Deploy NexaPatch AI on an Ubuntu Server VM

This setup uses Docker Compose, MySQL, Nginx, PHP-FPM and Laravel queue workers.

## 1. Create the Ubuntu VM

Recommended:

- Ubuntu 24.04 LTS
- 4 GB RAM minimum
- 8 GB RAM recommended
- 40 GB disk minimum
- Static/private IP
- Ports 22 and 8080 open internally

For HTTPS, place Cloudflare Tunnel or an Nginx reverse proxy in front of port `8080`.

## 2. Install Docker

```bash
sudo apt update
sudo apt install -y ca-certificates curl git
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo \"$VERSION_CODENAME\") stable" | sudo tee /etc/apt/sources.list.d/docker.list >/dev/null

sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo usermod -aG docker $USER
```

Log out and back in after the final command.

## 3. Clone the project

```bash
git clone https://github.com/tayyabp0g/NexaPatchAI.git
cd NexaPatchAI
cp .env.production.example .env.production
nano .env.production
```

Set at minimum:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://nexapatch.example.com
APP_KEY=
DB_PASSWORD=a-long-random-database-password
DB_ROOT_PASSWORD=a-long-random-root-password
GEMINI_API_KEY=
SEED_DEMO_USERS=false
SCAN_MODE=queue
```

Generate an application key locally or inside a running container:

```bash
docker run --rm -it php:8.3-cli php -r 'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;' 
```

Set the result as:

```env
APP_KEY=base64:YOUR_GENERATED_VALUE
```

Do not reuse demo passwords or commit `.env.production`.

## 4. Start the services

The Compose file reads database passwords from shell variables or `.env`.
Use a separate `.env` file for Compose passwords:

```bash
cat > .env <<'EOF'
APP_PORT=8080
DB_PASSWORD=a-long-random-database-password
DB_ROOT_PASSWORD=a-long-random-root-password
EOF

docker compose up -d --build
docker compose ps
docker compose logs -f app
```

The app container runs migrations automatically and starts Nginx, PHP-FPM and Laravel queue workers.

Open `http://SERVER_IP:8080`. If a firewall is enabled:

```bash
sudo ufw allow 22/tcp
sudo ufw allow 8080/tcp
sudo ufw enable
```

## 5. Add HTTPS

For a public domain, set up Caddy or Nginx Proxy Manager. For a private site, use a VPN/Tailscale or Cloudflare Tunnel.

The app should remain behind HTTPS and no uploaded code should be served from the public directory.
