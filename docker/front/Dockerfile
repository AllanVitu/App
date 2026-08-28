# ============================================================================
#  Image web de DÉPLOIEMENT
#
#  Étape 1 : compilation du client Vue (Vite).
#  Étape 2 : Nginx sert les fichiers statiques ET relaie /api vers PHP-FPM.
#
#  Front et API partagent ainsi la MÊME ORIGINE : plus aucune requête CORS,
#  et le cookie de session peut être posé en SameSite=Strict.
# ============================================================================

# --- Étape 1 : build ---------------------------------------------------------
FROM node:20-alpine AS builder

WORKDIR /app

# Les dépendances sont installées avant la copie du code : tant que
# package.json ne change pas, cette couche reste en cache.
COPY front/package*.json ./
RUN npm ci --no-audit --no-fund

COPY front/ ./

# En même origine, l'API est joignable en relatif : aucune URL absolue à
# reconfigurer selon le domaine de déploiement.
ARG VITE_API_BASE_URL=/api
ENV VITE_API_BASE_URL=$VITE_API_BASE_URL

RUN npm run build


# --- Étape 2 : service -------------------------------------------------------
FROM nginx:1.27-alpine

COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/security-headers.conf /etc/nginx/security-headers.conf
COPY --from=builder /app/dist /usr/share/nginx/html

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
    CMD wget -qO- http://localhost/api/health >/dev/null || exit 1
