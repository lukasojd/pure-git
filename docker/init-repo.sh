#!/bin/bash
set -e

git config --global --add safe.directory '*'

REPO_PATH="/srv/git/test-repo.git"

if [ ! -d "$REPO_PATH" ]; then
    git init --bare "$REPO_PATH"

    # Configure the bare repo
    git -C "$REPO_PATH" config receive.denyCurrentBranch ignore
    git -C "$REPO_PATH" config http.receivepack true

    # Create an initial commit using a temporary working repo
    WORK=$(mktemp -d)
    git init "$WORK"
    cd "$WORK"
    git config user.email "test@puregit.local"
    git config user.name "Test User"
    echo "# Test Repository" > README.md
    git add README.md
    git commit -m "Initial commit"

    # Push to bare repo using local file transport
    git remote add origin "$REPO_PATH"
    git push origin HEAD:refs/heads/main
    cd /
    rm -rf "$WORK"

    # Update bare repo HEAD to point to main
    git -C "$REPO_PATH" symbolic-ref HEAD refs/heads/main

    # Ensure git user can write
    chown -R git:git "$REPO_PATH"
fi

echo "Repository initialized at $REPO_PATH"
