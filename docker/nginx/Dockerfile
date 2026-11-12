# syntax=docker/dockerfile:1
# nginx image carrying the built public/ directory. Build context: repository root.
FROM node:22-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY vite.config.js vitest.config.js ./
COPY resources ./resources
COPY lang ./lang
COPY public ./public
RUN npm run build

FROM nginx:1.29-alpine
COPY --from=assets /build/public /var/www/html/public
COPY docker/nginx/snippets/ /etc/nginx/snippets/
COPY docker/nginx/templates/ /etc/nginx/templates/
