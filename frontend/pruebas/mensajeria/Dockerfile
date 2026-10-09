FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY src ./src
COPY pruebas/mensajeria ./pruebas/mensajeria
COPY vite.mensajeria.config.ts ./
ARG VITE_MENSAJERIA_LIVE=false
ENV VITE_MENSAJERIA_LIVE=$VITE_MENSAJERIA_LIVE
RUN npm run build:mensajeria
ARG PROTOTYPE_BUILD_ID=mensajeria-pruebas
RUN printf '%s\n' "$PROTOTYPE_BUILD_ID" > .preview-build/mensajeria/build-id.txt

FROM nginx:alpine
COPY pruebas/mensajeria/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/.preview-build/mensajeria /usr/share/nginx/html/mensajeria
EXPOSE 80
