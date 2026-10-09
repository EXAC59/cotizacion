FROM node:22-alpine AS base
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

FROM base AS dev
COPY . .
EXPOSE 5173
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "5173"]

FROM base AS build
COPY . .
ARG VITE_API_URL=/api
ENV VITE_API_URL=$VITE_API_URL
ARG VITE_DASHBOARD_PREVIEW=false
ENV VITE_DASHBOARD_PREVIEW=$VITE_DASHBOARD_PREVIEW
ARG VITE_MENSAJERIA_LIVE=false
ENV VITE_MENSAJERIA_LIVE=$VITE_MENSAJERIA_LIVE
ARG VITE_MENSAJERIA_ENVIRONMENT=testing
ENV VITE_MENSAJERIA_ENVIRONMENT=$VITE_MENSAJERIA_ENVIRONMENT
# outDir dist en Docker (vite.config usa dist cuando DOCKER_BUILD=1)
ENV DOCKER_BUILD=1
RUN npm run build
RUN if [ "$VITE_MENSAJERIA_LIVE" = "true" ]; then npm run build:mensajeria; else mkdir -p .preview-build/mensajeria; fi

FROM nginx:alpine AS production
COPY docker/nginx/frontend.conf /etc/nginx/conf.d/default.conf
# Vite base=/spa/ → assets en /spa/*; nginx location /spa/ exige archivos bajo html/spa/
COPY --from=build /app/dist /usr/share/nginx/html/spa
COPY --from=build /app/.preview-build/mensajeria /usr/share/nginx/html/mensajeria
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
