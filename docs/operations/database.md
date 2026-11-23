# Kelpie's database

Kelpie's data lives in a MariaDB database, `ubixkelpie`. The schema is changed by migrations
that the CI pipeline applies before each deploy; the database is not edited by hand.

Credentials never go in this repo, a merge request or a chat. They are kept in the project's
secret store and handed to the running service there.
