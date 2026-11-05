# Home Assistant multi-arch base image (amd64, aarch64). The Supervisor may
# still pass BUILD_FROM; standalone builds (docker build, compose, Dockhand...)
# no longer need any build argument.
ARG BUILD_FROM=ghcr.io/home-assistant/base:3.24
FROM $BUILD_FROM

ARG BUILD_ARCH
ARG BUILD_VERSION=dev

LABEL \
    io.hass.type="addon" \
    io.hass.name="AirSend" \
    io.hass.description="AirSend Web Service (local connection, no internet, no cloud)" \
    io.hass.url="https://github.com/devmel/hass_airsend-addon" \
    io.hass.version="${BUILD_VERSION}" \
    org.opencontainers.image.source="https://github.com/devmel/hass_airsend-addon"

ENV HASS_HOST=
ENV HASS_TOKEN=
ENV HASS_AUTOINCLUDE=0
ENV HTTPS=1

# Install Services
RUN apk add --no-cache php php-curl nginx openssl

# Install AirSendWebService
RUN mkdir -p /home && cd /home && wget http://devmel.com/dl/AirSendWebService.tgz && tar -zxvf AirSendWebService.tgz && rm AirSendWebService.tgz && chmod -R 777 bin

# Generate self-signed certificate
RUN mkdir -p /etc/nginx/ssl && openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
    -subj "/C=FR/ST=France/L=Paris/O=AirSendWebService/CN=localhost" \
    -keyout /etc/nginx/ssl/selfsigned.key \
    -out /etc/nginx/ssl/selfsigned.crt

# Copy data for add-on
COPY nginx.conf /etc/nginx/nginx.conf
COPY hassapi.class.php hass_cb.php state_post.sh state_get.sh states_get.sh /home/
COPY run.sh /
RUN chmod a+x /home/state_post.sh /home/state_get.sh /home/states_get.sh /run.sh

# Start AirSendWebService
CMD [ "/run.sh" ]
EXPOSE 33863
