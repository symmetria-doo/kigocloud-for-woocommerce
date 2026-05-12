# Contributing

## Local setup

```bash
git clone git@github.com:symmetria-doo/kigocloud-for-woocommerce.git
```

The plugin runs as-is. `vendor/plugin-update-checker/` is committed so a fresh checkout has everything needed in WordPress.

If you remove `vendor/` and want to restore it via Composer:

```bash
composer install --no-dev
```

The `composer.json` already lists `yahnis-elsts/plugin-update-checker` in `require-dev` so it ends up under `vendor/`.

## Release workflow

This plugin uses GitHub Releases as the update source. Yahnis Elsts' [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) is bundled and configured in `kigocloud-for-woocommerce.php` to consume release assets.

For each release:

1. Bump version in three places (must match):
   - `kigocloud-for-woocommerce.php` plugin header `Version:`
   - `kigocloud-for-woocommerce.php` `WOO_KIGOCLOUD_PLUGIN_NAME_VERSION` define
   - `includes/class-woo-kigocloud.php` `Woo_KigoCloud::PLUGIN_VERSION`
   - `README.txt` `Stable tag:`
2. Add a changelog entry to `README.txt` and `README.md`.
3. Commit and push to `main`.
4. Tag:
   ```bash
   git tag v2.0.1
   git push origin v2.0.1
   ```
5. Build the release zip. The zip name must match the plugin slug:
   ```bash
   # from the repo root, on a clean checkout of the tag:
   zip -r kigocloud-for-woocommerce.zip . \
       -x ".git/*" -x ".github/*" -x ".idea/*" \
       -x "CONTRIBUTING.md" -x "composer.lock" \
       -x "*.zip" -x ".gitignore"
   ```
6. Create the GitHub Release and attach the zip:
   ```bash
   gh release create v2.0.1 kigocloud-for-woocommerce.zip \
       --title "v2.0.1" --generate-notes
   ```
   Without `gh`, do this through the GitHub UI: Releases -> Draft a new release.
7. WordPress sites with this plugin installed will detect the new release at the next update check (within 12 hours by default, or instantly when an admin clicks Check for updates on the Plugins page).

## Versioning

[SemVer](https://semver.org). The `v` prefix is required on tags so plugin-update-checker recognises them.

## Coding standards

- PHP 7.2+ syntax only (no typed properties, no arrow functions, no `??=`).
- WordPress coding standards otherwise.
- All user-facing strings must be wrapped in `__()` / `_e()` / `esc_html__()` with the `kigocloud-for-woocommerce` text domain.
- No emojis in code or commit messages.
