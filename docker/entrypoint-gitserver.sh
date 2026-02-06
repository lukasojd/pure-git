#!/bin/bash
set -e

# Allow pubkey auth in sshd
sed -i 's/#PubkeyAuthentication.*/PubkeyAuthentication yes/' /etc/ssh/sshd_config
sed -i 's/#PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config

# Generate SSH keypair in shared volume for the puregit client
if [ ! -f /tmp/ssh-keys/id_ed25519 ]; then
    mkdir -p /tmp/ssh-keys
    ssh-keygen -t ed25519 -f /tmp/ssh-keys/id_ed25519 -N "" -q
    chmod 644 /tmp/ssh-keys/id_ed25519.pub
    chmod 644 /tmp/ssh-keys/id_ed25519
fi

# Install public key for git user
mkdir -p /home/git/.ssh
cp /tmp/ssh-keys/id_ed25519.pub /home/git/.ssh/authorized_keys
chmod 600 /home/git/.ssh/authorized_keys
chown -R git:git /home/git/.ssh

# Initialize test repository
/usr/local/bin/init-repo.sh

# Start sshd
/usr/sbin/sshd

# Start fcgiwrap for git-http-backend (run as git user to access repos)
spawn-fcgi -s /var/run/fcgiwrap.sock -u git -g git -- /usr/bin/fcgiwrap
chmod 666 /var/run/fcgiwrap.sock

# Start git-daemon
/usr/libexec/git-core/git-daemon \
    --reuseaddr \
    --base-path=/srv/git \
    --export-all \
    --enable=receive-pack \
    --detach

# Start nginx in foreground
exec nginx -g "daemon off;"
