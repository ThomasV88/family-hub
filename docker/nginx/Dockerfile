# syntax=docker/dockerfile:1
# nginx image carrying the public/ directory of the app image, so the static assets are exactly the ones the
# app's HTML references (one Vite build, in docker/app/Dockerfile). Build context: repository root.
#   docker build -f docker/nginx/Dockerfile --build-arg APP_IMAGE=<app image, prod target> .
ARG APP_IMAGE
FROM ${APP_IMAGE} AS app

FROM nginx:1.29-alpine
COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/nginx/snippets/ /etc/nginx/snippets/
COPY docker/nginx/templates/ /etc/nginx/templates/
