Installer templates for the Agent Deployment console.

Upload the two generic MSI templates here (this folder, public_html/8westit/installers/):

  agent-template-full.msi   (~24 MB, RustDesk bundled)
  agent-template-lite.msi   (~1 MB, agent downloads RustDesk on first run)

Build them on a Windows machine with:   agent\build-templates.ps1
Then upload both files here. download.php patches each client's enrollment key
into the right template on the fly and serves a single, ready-to-run .msi.

These .msi files are NOT in git (too large / rebuilt per agent version) — they're
deployment artifacts you upload alongside the portal.
