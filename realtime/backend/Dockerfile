# Milepost real-time backend.
# better-sqlite3 is an OPTIONAL dependency (native build). The build stage
# includes the toolchain so it compiles if present; the runtime image stays slim.
FROM node:20-alpine AS build
WORKDIR /app

# Build toolchain for any native optional deps (better-sqlite3).
RUN apk add --no-cache python3 make g++

COPY package.json ./
# Install prod deps (incl. optional better-sqlite3 when buildable).
RUN npm install --omit=dev --no-audit --no-fund

# ── Runtime ──────────────────────────────────────────────────────────────────
FROM node:20-alpine AS runtime
WORKDIR /app
ENV NODE_ENV=production

# Run as the built-in non-root user.
COPY --chown=node:node --from=build /app/node_modules ./node_modules
COPY --chown=node:node package.json ./
COPY --chown=node:node src ./src

# data/ is mounted as a writable volume (SQLite cache); created at runtime.
USER node
EXPOSE 8090

# Lightweight container healthcheck against /healthz (localhost inside the net ns).
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD node -e "require('http').get('http://127.0.0.1:'+(process.env.PORT||8090)+'/healthz',r=>process.exit(r.statusCode===200?0:1)).on('error',()=>process.exit(1))"

CMD ["node", "src/server.js"]
