# firebase/php-jwt

- Package: `firebase/php-jwt` 7.2.1
- Official repository: https://github.com/googleapis/php-jwt
- Pinned commit: `0501f5729b9e2448516674aff07c7d1727869b8e`
- Source: https://github.com/googleapis/php-jwt/tree/0501f5729b9e2448516674aff07c7d1727869b8e
- License: BSD-3-Clause; see `LICENSE`.

The unmodified `src/`, `LICENSE`, and `composer.json` are vendored so this small PHP deployment does not require Composer on the hosting server. Only the JWT/JWK/key classes needed for RS256 verification are autoloaded. Optional cache/HTTP integrations and their suggested dependencies are not used. Review upstream releases before upgrading, update this pin, and rerun `tests/auth-oauth.php`.
