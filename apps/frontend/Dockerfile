FROM node:22-alpine

RUN npm install -g pnpm@12.3.4

COPY apps/frontend/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /app

EXPOSE 5173

ENTRYPOINT ["docker-entrypoint.sh"]