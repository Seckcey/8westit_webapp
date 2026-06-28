# 8 West IT — RustDesk Relay (VPS)

This is the only piece that can't live on HostGator: a tiny always-on server that relays
remote-support sessions between you and client PCs. A $5–7/mo VPS is plenty.

## 1. Get a VPS
DigitalOcean, Vultr, Hetzner, Linode — any "$5 / 1 GB RAM" Ubuntu 22.04 droplet works.
Give it a DNS name, e.g. `relay.8westit.com` (an A record pointing at the VPS IP).

## 2. Install Docker
```bash
curl -fsSL https://get.docker.com | sh
```

## 3. Start the relay
```bash
sudo mkdir -p /opt/rustdesk && cd /opt/rustdesk
# copy docker-compose.yml here, then:
echo "RELAY_HOST=relay.8westit.com" > .env
sudo docker compose up -d
```

## 4. Open the firewall
Allow inbound: **TCP 21115–21119** and **UDP 21116**.
```bash
sudo ufw allow 21115:21119/tcp
sudo ufw allow 21116/udp
```

## 5. Grab the public key
The server generates a keypair on first run:
```bash
sudo cat /opt/rustdesk/data/id_ed25519.pub
```
Copy that string. Put it (and the relay hostname) into the portal config:

```php
// portal/config/config.php
'rustdesk' => [
    'relay_host' => 'relay.8westit.com',
    'relay_key'  => 'PASTE_THE_PUBLIC_KEY_HERE',
],
```

From now on, every agent that enrolls is auto-configured to use this relay, and the portal's
**Remote In** button hands the ID/password to your RustDesk client.

---

## Getting the RustDesk client onto client PCs — automatic

You don't have to do anything. A few minutes after the agent installs, it **downloads and
silently installs RustDesk** (pinned to v1.4.8), points it at this relay, and sets an
unattended password. Then it reports the RustDesk ID to the portal and **Remote In** lights
up for that computer.

- The client just needs outbound internet (it already talks to your portal).
- To pin a different RustDesk version, set `HKLM\SOFTWARE\8WestIT\Agent\RustDeskUrl` on the
  client (or `RustDeskUrl` in the agent config) to another release URL.
- If a client network blocks GitHub, host the RustDesk installer on your own server and point
  `RustDeskUrl` at it.

---

## Your own remote client
On your support workstation, install RustDesk from https://rustdesk.com and point it at the
same relay: **Settings → Network → ID/Relay Server** →
- ID server: `relay.8westit.com`
- Relay server: `relay.8westit.com`
- Key: the public key from step 5

Then the **Launch RustDesk** button / the ID shown in the portal connect straight through.
