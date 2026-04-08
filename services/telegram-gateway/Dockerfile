FROM python:3.12-slim

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        libstdc++6 \
        libssl3 \
        zlib1g; \
    tdlib_package="$(apt-cache search '^libtdjson' | awk 'NR==1 { print $1 }')"; \
    if [ -z "${tdlib_package}" ]; then \
        echo "TDLib package not found in apt repositories." >&2; \
        exit 1; \
    fi; \
    apt-get install -y --no-install-recommends "${tdlib_package}"; \
    tdjson_path="$(find /usr/lib -path '*/libtdjson.so*' -type f | grep -v '/TDLib' | sort | head -n 1)"; \
    if [ -z "${tdjson_path}" ]; then \
        tdjson_path="$(find /usr/lib -path '*/libtdjson.so*' -type f | sort | head -n 1)"; \
    fi; \
    if [ -z "${tdjson_path}" ]; then \
        echo "Installed TDLib package does not expose libtdjson." >&2; \
        exit 1; \
    fi; \
    ln -s "${tdjson_path}" /usr/local/lib/libtdjson.so; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /opt/telegram-gateway

COPY . /opt/telegram-gateway

ENV PYTHONUNBUFFERED=1

EXPOSE 8090

ENTRYPOINT ["python", "-m", "app.server"]
