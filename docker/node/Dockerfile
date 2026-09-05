# Local-development-only image for BoothPOS's Vite/Vue dev server.
# Not a production build — the app is built and served by Laravel
# (`npm run build` -> public/build) for real usage; this image only runs
# the live-reload dev server (research.md R1).
FROM node:22

WORKDIR /var/www/html

# node_modules/ is an anonymous volume in docker-compose.yml so a fresh
# `npm install` here isn't shadowed by the bind-mounted repo root
# (research.md R5). No migrations/key-gen equivalent needed on this side,
# so a plain inline shell CMD is enough — no separate entrypoint script.
CMD ["sh", "-c", "[ -d node_modules ] && [ -n \"$(ls -A node_modules 2>/dev/null)\" ] || npm install; npm run dev -- --host 0.0.0.0"]
