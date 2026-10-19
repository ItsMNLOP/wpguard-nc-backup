#!/usr/bin/env bash
# Generates a disposable local CA + server cert for the nc-proxy container.
# Project-scoped only: output lives under docker/certs/, gitignored, never
# touches the Windows trust store (see docs/adr/0007-tls-ca-bundle-handling.md).
set -euo pipefail
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL="*"
cd "$(dirname "${BASH_SOURCE[0]}")/../certs"

if [[ -f wpguard-test-ca.crt && -f nc-proxy.pem && -f nc-proxy.key ]]; then
  echo "certs already present, skipping (delete docker/certs/* to regenerate)"
  exit 0
fi

openssl genrsa -out ca.key 4096
openssl req -x509 -new -nodes -key ca.key -sha256 -days 3650 \
  -subj "/CN=wpguard-test-local-CA" -out wpguard-test-ca.crt

openssl genrsa -out nc-proxy.key 2048
openssl req -new -key nc-proxy.key -subj "/CN=nc-proxy" -out nc-proxy.csr

cat > san.ext <<EOF
subjectAltName = DNS:nc-proxy,DNS:nc-proxy.wpguard-test.local,DNS:localhost,IP:127.0.0.1
EOF

openssl x509 -req -in nc-proxy.csr -CA wpguard-test-ca.crt -CAkey ca.key \
  -CAcreateserial -out nc-proxy.pem -days 825 -sha256 -extfile san.ext

rm -f nc-proxy.csr san.ext ca.srl
chmod 600 ca.key nc-proxy.key
echo "generated: wpguard-test-ca.crt, nc-proxy.pem, nc-proxy.key"
