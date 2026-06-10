#!/bin/sh
# Force working DNS inside the container. PVE rewrites /etc/resolv.conf at
# container start to an unreachable resolver (192.168.1.1) and the immutable
# bit can't be set in an unprivileged LXC, so we re-assert it on every boot.
set -e
cat > /etc/resolv.conf <<EOF
search girafi.keenetic.name
nameserver 192.168.2.1
nameserver 8.8.8.8
EOF
