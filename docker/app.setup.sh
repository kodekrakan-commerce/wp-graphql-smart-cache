#!/bin/bash
# This script is run by the wp-graphql entrypoint.sh script as app-setup.sh.
set -e

# Run the base wp-graphql image setup script then our setup.
. /usr/local/bin/original-app-setup.sh

PLUGINS_DIR=${PLUGINS_DIR-.}
WPGRAPHQL_VERSION=${WPGRAPHQL_VERSION:-2.23.1}
if [ "$WPGRAPHQL_VERSION" != "2.23.1" ]; then
    echo "This QA lock requires WPGraphQL 2.23.1." >&2
    exit 1
fi
installed_version=$(wp plugin get wp-graphql --field=version --allow-root 2>/dev/null || true)
if [ "$installed_version" != "$WPGRAPHQL_VERSION" ]; then
    wpgraphql_zip=$(mktemp /tmp/wpgraphql-XXXXXX.zip)
    trap 'rm -f "$wpgraphql_zip"' EXIT
    curl --fail --location --silent --show-error \
        'https://github.com/wp-graphql/wp-graphql/releases/download/wp-graphql/v2.23.1/wp-graphql.zip' \
        -o "$wpgraphql_zip"
    echo "75685a6fedc5e8cd9c3cca6749ea4680ead4d8f4ddd01d2a5bb04812aab9add4  $wpgraphql_zip" | sha256sum --check
    wp plugin install "$wpgraphql_zip" --force --allow-root
fi
wp plugin activate wp-graphql --allow-root
wp plugin activate wp-graphql-smart-cache --allow-root
