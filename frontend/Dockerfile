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
# outDir dist en Docker (vite.config usa dist cuando DOCKER_BUILD=1)
ENV DOCKER_BUILD=1
RUN npm run build

FROM nginx:alpine AS production
COPY docker/nginx/frontend.conf /etc/nginx/conf.d/default.conf
# Vite base=/spa/ → assets en /spa/*; nginx location /spa/ exige archivos bajo html/spa/
COPY --from=build /app/dist /usr/share/nginx/html/spa
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
