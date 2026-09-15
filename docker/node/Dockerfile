# ============================================================================
#  Image Node 20 pour le serveur de développement Vite (client Vue 3)
#  Le code source est monté par volume : aucun COPY ici.
# ============================================================================
FROM node:20-alpine

RUN apk add --no-cache git

WORKDIR /app

# Port du serveur de développement Vite (le websocket HMR l'emprunte aussi)
EXPOSE 5173

# La commande réelle est définie dans docker-compose.yml
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]
