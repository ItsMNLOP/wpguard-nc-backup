#!/usr/bin/env bash
# Generates a disposable local CA + per-service server certs (nc-proxy,
# oc-proxy, opencloud). Project-scoped only: output lives under
# docker/certs/, gitignored, never touches the Windows trust store (see
# docs/adr/0007-tls-ca-bundle-handling.md).
set -euo pipefail
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL="*"
mkdir -p "$(dirname "${BASH_SOURCE[0]}")/../certs"
cd "$(dirname "${BASH_SOURCE[0]}")/../certs"

if [[ ! -f wpguard-test-ca.crt || ! -f ca.key ]]; then
  openssl genrsa -out ca.key 4096
  openssl req -x509 -new -nodes -key ca.key -sha256 -days 3650 \
    -subj "/CN=wpguard-test-local-CA" -out wpguard-test-ca.crt
fi

# $1 = leaf name (output file basename), $2 = comma-separated SAN list
gen_leaf() {
  local name="$1" sans="$2"
  if [[ -f "${name}.pem" && -f "${name}.key" ]]; then
    echo "${name}.pem/.key already present, skipping (delete docker/certs/${name}.* to regenerate)"
    return 0
  fi
  openssl genrsa -out "${name}.key" 2048
  openssl req -new -key "${name}.key" -subj "/CN=${name}" -out "${name}.csr"
  cat > "${name}-san.ext" <<EOF
subjectAltName = ${sans}
EOF
  openssl x509 -req -in "${name}.csr" -CA wpguard-test-ca.crt -CAkey ca.key \
    -CAcreateserial -out "${name}.pem" -days 825 -sha256 -extfile "${name}-san.ext"
  rm -f "${name}.csr" "${name}-san.ext"
  chmod 600 "${name}.key"
  echo "generated: ${name}.pem, ${name}.key"
}

gen_leaf nc-proxy "DNS:nc-proxy,DNS:nc-proxy.wpguard-test.local,DNS:localhost,IP:127.0.0.1"
gen_leaf oc-proxy "DNS:oc-proxy,DNS:oc-proxy.wpguard-test.local,DNS:localhost,IP:127.0.0.1"
gen_leaf opencloud "DNS:opencloud,DNS:opencloud.wpguard-test.local,DNS:localhost,IP:127.0.0.1"

rm -f ca.srl
chmod 600 ca.key
echo "done."
