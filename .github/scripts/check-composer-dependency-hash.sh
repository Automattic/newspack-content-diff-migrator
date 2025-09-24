#!/bin/bash

###########
#
# Validate if composer.lock hashes are the latest for $REPO.
#
###########

REPO="automattic/newspack-migration-tools"

# Path to composer.lock, two down from ./.github/hooks/.
COMPOSER_LOCK="$(dirname "$(realpath "$0")")/../../composer.lock"

# GitHub repository URL.
GITHUB_REPO="https://github.com/$REPO/tree/trunk"

# Fetch the current hash from composer.lock.
LOCAL_HASH=$(jq -r --arg name "$REPO" '.packages[] | select(.name == $name) | .source.reference' "$COMPOSER_LOCK")
if [ -z "$LOCAL_HASH" ]; then
    echo "Error: Could not find the $REPO package hash in $COMPOSER_LOCK. Please fix the .github/hooks/pre-merge.sh script before proceeding."
    exit 1
fi

# Fetch the latest hash from the GitHub repo.
REMOTE_HASH=$(curl -s "https://api.github.com/repos/$REPO/commits?sha=trunk" | jq -r '.[0].sha')
if [ -z "$REMOTE_HASH" ]; then
    echo "Error: Could not fetch the remote hash from remote $REPO GitHub repo."
    exit 1
fi

# Compare the hashes.
if [ "$LOCAL_HASH" != "$REMOTE_HASH" ]; then
	echo "Error: NMT in your PR ($LOCAL_HASH) is behind the live NMT repo ($REMOTE_HASH)."
	echo "Please update the Composer hash and then try merging again. You can use the following from your PR branch:"
	echo "  rm -rf vendor/$REPO && \\"
	echo "  composer update $REPO && \\"
	echo "  git add composer.lock && \\"
	echo "  git commit -m 'Updating $REPO composer pointer' && \\"
	echo "  git push origin \$(git symbolic-ref --short HEAD)"
	exit 1
fi

echo "Hashes match for $REPO, proceeding with merge."
exit 0
