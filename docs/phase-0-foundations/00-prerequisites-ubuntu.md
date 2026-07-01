# Phase 0 · Step 00 — Prerequisites on Ubuntu

**Goal:** put exactly three things on the host — Docker Engine, the Docker Compose plugin, and Git.
Everything else (PHP, Node, MySQL, Redis) lives **inside containers**, so the host stays clean and
every developer (and every AI session) works against an identical setup.

> Do this once per machine. If Docker and Git are already installed, jump to **Verify** at the bottom.

---

## 1. Update the system and install prerequisites

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y ca-certificates curl gnupg git
```

## 2. Install Docker Engine + the Compose plugin

Use Docker's official convenience script (installs Engine and the `compose` plugin together):

```bash
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
```

> Prefer the repository method for production hosts (pinned, apt‑managed). The convenience script is
> the fastest path for a developer workstation and is what this guide assumes.

## 3. Run Docker without `sudo`

```bash
sudo usermod -aG docker $USER
```

**Log out and back in** (or run `newgrp docker`) for the group change to take effect.

## 4. (Recommended) Enable Docker on boot

```bash
sudo systemctl enable --now docker
```

---

## Verify

```bash
docker --version           # e.g. Docker version 27.x
docker compose version     # e.g. Docker Compose version v2.x   (note: "compose", not "docker-compose")
git --version              # e.g. git version 2.4x
docker run --rm hello-world   # prints a success message and exits
```

If `docker run hello-world` prints the welcome message **without `sudo`**, the host is ready.

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `permission denied … /var/run/docker.sock` | You didn't re‑login after `usermod -aG docker`. Run `newgrp docker` or start a new shell. |
| `docker: command not found` | The install script failed; re‑run step 2 and read its output. |
| `docker compose` says "not a docker command" | Old standalone binary only. Install the plugin: `sudo apt install docker-compose-plugin`. |
| Corporate proxy blocks `get.docker.com` | Use the apt‑repository install method from Docker's docs. |

---

## Checklist / gate

- [ ] `docker --version`, `docker compose version`, and `git --version` all succeed.
- [ ] `docker run --rm hello-world` works **without** `sudo`.
- [ ] Docker is enabled on boot.

**Next:** [`01-repo-and-layout.md`](01-repo-and-layout.md)
