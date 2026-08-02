FROM alpine:3.21
RUN apk add --no-cache php83-cli php83-curl && mkdir -p /app
COPY crontab /etc/crontabs/root
CMD ["crond", "-f"]
