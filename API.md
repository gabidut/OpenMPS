# Administration API v1 (CPM)

Optional API for editor publication, separate from the existing game-facing MPS 1.3.0 protocol and dashboard login. Existing local exports in CPM do not require this API.

## Install and connect

Apply the companion changes to OpenMPS, enable PHP OpenSSL and ZipArchive, and configure `SERVER_PUBLIC_URL` in `config.php` to the externally reachable site root, including its installation subdirectory and trailing slash. Use HTTPS for administration; CPM permits plain HTTP only on loopback for development. The web/PHP user needs write access to `packs/`, `var/openmps-api/` and its temporary upload directory.

Create a token **on the server**:

```sh
php tools/api_token.php create CPM
php tools/api_token.php list
php tools/api_token.php revoke TOKEN_ID
```

The token is displayed once. The store contains a SHA-256 digest, label, scopes and revocation state. Default scopes are `read,publish`; `recover` must be granted explicitly for editable originals. Create an editor token with `php tools/api_token.php create CPM read,publish,recover`. Existing tokens are not expanded automatically. a download-only token can use `create Players read`. `OPENMPS_API_TOKEN_FILE` optionally places the PHP token store outside the web root. Ensure CLI and web PHP use the same location, and that the web user can read it. Never commit the token store or put tokens into URLs. CLI token rotation and revocation do not change the game-facing access key.

In CPM choose **Outils → OpenMPS…**, enter the site URL and token, and test the connection. Profiles persist the name and URL only; paste the token again after restarting CPM. Publish requires an explicit button press after the compatibility report. A new version is required for different content or publication options.

Apache: keep the supplied `.htaccess` with rewrite and authorization-header forwarding enabled. Nginx or another web server must explicitly deny requests to `var/openmps-api/` and `api-tokens.php`, forward the `Authorization` header to PHP, serve `api/v1.php`, and retain the existing `/get/` rewrite. For example, for an installation at the site root:

```nginx
location ^~ /var/openmps-api/ { deny all; }
location = /api-tokens.php { deny all; }
# In the existing PHP/FastCGI location:
# fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

Adapt prefixes for a subdirectory installation. Set the reverse proxy body limit and request timeouts for the intended pack sizes. `OPENMPS_API_MAX_UPLOAD_BYTES` can lower the API's default 512 MiB upload limit. No remote deployment is performed by this patch.

## HTTP contract

Endpoint: `<site-root>/api/v1.php`. All requests require `Authorization: Bearer omps_…`; dashboard sessions and MPS game access keys cannot authenticate this API. Responses are JSON with `success`, except a successful ZIP download.

| Method / action query | Scope | Result |
| --- | --- | --- |
| GET `?action=capabilities` | read | API/protocol version, public URL, upload limit, immutable-version flag |
| GET `?action=packs` | read | `packs` array of pack keys, versions, ZIP sizes, file counts and available SHA-256 hashes |
| POST `?action=publish` | publish | Raw ZIP body; publication receipt under `publication` |
| GET `?action=client&pack_key=demo&pack_version=1.0` | read | Players ZIP, `X-OpenMPS-SHA256` and Content-Length |
| GET `?action=source&pack_key=demo&pack_version=1.0` | recover | Editable configurations and original resources, SHA-256 and pack identity headers |

Publish headers:

- `Content-Type: application/zip` and a valid `Content-Length`.
- `X-OpenMPS-Pack-Key`: 1–64 lowercase letters/digits/underscore/hyphen, starting with a letter or digit.
- `X-OpenMPS-Pack-Version`: 1–64 portable letters/digits/underscore/hyphen/dot, starting with a letter/digit and not ending with a dot.
- `X-OpenMPS-SHA256`: lowercase hexadecimal SHA-256 of the ZIP.
- `X-OpenMPS-Access-Key`: game-facing key accepted by the existing OpenMPS configuration; default `legacy`. This is intentionally included in the players' MpsRepositories.json and is **not** an admin credential.

`pack_info.dynx` must be at the root, with matching PackName/PackVersion and DcFileVersion. Configurations remain outside `assets/`; models, textures, materials and sounds belong under `assets/<domain>/`. ZIP extraction rejects traversal, links, scripts, duplicate paths ignoring case, unsupported extensions, over 10,000 entries, individual files over 128 MiB and over 1 GiB uncompressed. Players ZIP generation excludes root models/textures, including dashboard extra-file inputs.

A per-version lock and hidden staging directory prepare the description, repository ZIP, players ZIP and receipt before making the version visible. Existing versions are never replaced by the API. Retrying the same source ZIP, game key and public URL returns the existing receipt (`idempotent: true`). Different content/options returns HTTP 409. This guarantee covers API writes; the existing administrator dashboard remains a separate management interface.

Common failures: 400 invalid identifier/length; 401 missing/revoked token; 403 insufficient scope; 409 existing version or concurrent publication; 413 upload limit; 415 wrong content type; 422 invalid archive/metadata/checksum/game key. JSON errors provide `code` and `error`. A network timeout after upload may leave a successfully published version: refresh versions before retrying.

## Tests

```sh
python3 tests/test_api.py
```

Uses a temporary copy, temporary CLI-issued tokens and a real loopback PHP server, with no real account. Set `OPENMPS_TEST_PHP` and the JSON array `OPENMPS_TEST_PHP_ARGS` for an alternate PHP binary/extensions. Validated with PHP 8.4 plus OpenSSL/ZipArchive. Game loading itself requires a separate Minecraft/DynamX test.

## Recovering editable originals

Capabilities advertise `editable_download` and `recovery_allowed` for the authenticated token. The new `recover` scope is independent of game access keys and read-only players-ZIP downloads. The source endpoint prepares a private temporary ZIP from available original configurations/models/materials/textures/sounds, excluding generated MPS archives, descriptions and repository connection files. It validates portable paths, rejects links/special files and enforces 10,000 files, 128 MiB per file, 1 GiB uncompressed and 512 MiB ZIP limits. Temporary archives are removed after the request, including shutdown cleanup. It is never cached in a public pack directory.

Only administrators explicitly granted recovery should receive a recover token: this endpoint returns complete original OBJ and other resources. If only encrypted `.part` models remain, it returns 422 `encrypted_originals`; missing original files cannot be reconstructed. The endpoint does not decrypt protected models. Minecraft/DynamX compatibility still requires an in-game check.

### Affichage des droits dans les clients

La réponse `capabilities` inclut `scopes`, la liste des droits du jeton courant (`read`, `publish`, `recover`). Un client peut afficher ces droits et désactiver les actions indisponibles. Le serveur continue de contrôler chaque requête ; cet affichage ne remplace pas son autorisation.
