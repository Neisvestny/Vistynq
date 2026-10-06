FROM node:22-alpine

# git нужен root-скрипту `prepare` (lefthook install) при pnpm install в контейнере;
# safe.directory — репозиторий смонтирован с хоста и принадлежит другому UID
RUN apk add --no-cache git \
    && git config --global --add safe.directory /app

RUN npm install -g pnpm@12.3.4

COPY apps/frontend/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /app

EXPOSE 5173

ENTRYPOINT ["docker-entrypoint.sh"]