# Magento Quality Patches Applier

[![Latest Stable Version](https://img.shields.io/packagist/v/blackbird/magento-quality-patches-applier.svg?style=flat-square)](https://packagist.org/packages/blackbird/magento-quality-patches-applier)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](./LICENSE)

A Composer plugin that manages the application of patches from magento/quality-patches.

The free source is available at the GitHub repository of this project.

## What it does

This Composer plugin hooks into install/update commands to automatically revert previously applied Magento Quality Patches and apply the ones you request, using the magento/quality-patches CLI under the hood.

- Before install/update: it reverts all currently applied patches (safety to avoid conflicts).
- After install/update: it applies the patches you configured.

It relies on the magento/quality-patches package binary (vendor/bin/magento-patches) and your composer.json "extra" configuration.

Staying current with monthly patch releases:
- Magento publishes a new monthly release of Required patches roughly every month (e.g. a "July 2026" release followed by an "August 2026" release).
- With extra.magento-patches.auto-install-required-patches enabled, you don't need to track or list these new patch IDs yourself: even a simple `composer update magento/magento-cloud-patches` triggers the plugin's revert/apply cycle, which re-reads the current patch status and automatically applies every Required patch it finds "Not applied" — including any newly published ones.
- This also covers catching up after skipping a release: if your project was still on the July 2026 patch level and you update straight to August 2026, the plugin will apply the outstanding Required patches from both the July and August releases in that same run, not just the latest month, since it re-checks patch status after each apply pass until nothing new is left to apply.
- Note: this only works if your project is already on the latest Magento patch version (e.g. 2.4.8-p5). Quality/monthly patches target a specific patch level, so if your magento/product-community-edition (or enterprise-edition) constraint is behind the latest p-release, the status command won't report those patches as applicable and they won't be auto-installed until you first upgrade Magento itself to that patch level.

## Requirements

- PHP >= 7.3
- Composer (Plugin API ^1.0 or ^2.0)
- magento/quality-patches (this package requires it)
- A Magento project (the patches are intended for Magento)
- The `patch` command available on the system PATH (only needed for the m2-hotfixes auto-apply feature)

## Setup

### Get the package

Composer Package:

```
composer require blackbird/magento-quality-patches-applier
```

This will also install magento/quality-patches and magento/magento-cloud-patches if it is not already present.

### Configure patches to apply

In your project composer.json, define the patches you want in the extra.magento-patches.apply section. You can also list patches to ignore.

apply and ignore accept an object, where each key is a free-text comment (e.g. the reason for the patch) and each value is the patch id. A plain array of patch ids (without comments) is also supported, and both forms can be mixed.

Example:

```
{
  "extra": {
    "magento-patches": {
      "auto-install-required-patches": true,
      "apply": {
        "fix for checkout error": "AEDAZ-xxx"
      },
      "ignore": {
        "it crashes the installation": "ACP2E-xxx"
      }
    }
  }
}
```

Special values for apply:
- "all", "*", or "ALL" applies all patches that are currently "Not applied" according to the status command.

Auto-installing required patches:
- Set extra.magento-patches.auto-install-required-patches to true to automatically apply every currently "Not applied" patch whose Details field starts with "Patch type: Required" (as reported by the status command), in addition to whatever is listed in apply.
- This is independent from apply: patches explicitly listed in apply are always applied regardless of this setting, and patches listed in ignore are always excluded, even if auto-install-required-patches is enabled.
- Defaults to false (disabled) when not set.

Console output for required patches (dev mode):
- When Composer runs in dev mode (i.e. not with --no-dev), after a successful application the plugin prints the Title and Details of every applied patch that is of type "Required".

Environment/config flags:
- By default, Composer fails if patch application fails. Set extra.composer-exit-on-magento-patch-failure to false to make Composer continue instead (patch errors are then just printed as warnings).
- Environment variable COMPOSER_EXIT_ON_MAGENTO_PATCH_FAILURE overrides extra.composer-exit-on-magento-patch-failure (and the default) whenever it is set: use COMPOSER_EXIT_ON_MAGENTO_PATCH_FAILURE=1 to force Composer to fail on patch errors, or COMPOSER_EXIT_ON_MAGENTO_PATCH_FAILURE=0 to disable it.

### Auto-applying m2-hotfixes custom patches

Magento Cloud automatically applies custom patch files placed in the project's `/m2-hotfixes` directory during deployment. This plugin replicates that behavior locally: it applies every `*.patch` file in `/m2-hotfixes`, in alphabetical order by filename, using the system `patch` command directly (no dependency on magento/magento-cloud-patches internals). A patch already applied is detected and skipped instead of erroring.

- extra.magento-patches.auto-install-hotfixes is a tri-state setting:
  - unset (default): the m2-hotfixes patches are applied automatically, but only when no cloud environment is detected (checking for MAGENTO_CLOUD_* / PLATFORM_* environment variables). This avoids double-applying them, since Magento Cloud already does it during its own deployment.
  - false: never apply them automatically, even outside of a cloud environment.
  - true: always apply them, even if a cloud environment is detected.
- This step always runs after the patches from apply/auto-install-required-patches, matching Magento Cloud's own order (required, then optional, then m2-hotfixes custom patches), and always prints its outcome: what was applied, that no custom patches were found, or why the step was skipped (disabled or cloud detected).
- Before install/update, any currently-applied m2-hotfixes patch is reverted first (before the other patches are reverted/reapplied), so a leftover custom patch can never conflict with reapplying the quality/cloud patches.

Example:

```
{
  "extra": {
    "magento-patches": {
      "auto-install-hotfixes": false
    }
  }
}
```

### Install / Update

Run your usual Composer commands from your project root:

```
composer install
# or
composer update
```

The plugin will:
1) Revert applied patches before the command runs.
2) Apply requested patches after dependencies are resolved.

If you enable verbose Composer output (-vvv), the plugin will display the exact magento-patches commands it executes.

### Security/patch status warnings

If `vendor/bin/patch-status` (Adobe's Monthly Security Release Versioning Tool) is present, the plugin runs it at the end of install/update and prints a warning listing any missing patches or CVEs it reports as not protected, or a confirmation message when there is nothing to report. This check is purely informational: it never fails the build, and is silently skipped if the binary isn't present or the tool itself fails to run (e.g. no network access).

### Updating to the latest patch packages

The plugin registers a `magento-patches:update` Composer command that fetches the latest available version of the patch packages (like `composer show --latest` would) and requires that exact version (using `composer require ... --fixed`), instead of a computed version range.

```
composer magento-patches:update
```

By default it only updates `magento/magento-cloud-patches`. Pass a target argument to control which package(s) to update:

```
composer magento-patches:update cloud    # default, magento/magento-cloud-patches only
composer magento-patches:update quality  # magento/quality-patches only
composer magento-patches:update all      # both packages
```

Note: pinning an exact version this way relies on Composer's `--fixed` require option, which Composer only allows for a root package of "type": "project" (the standard type for a Magento project) or for require-dev packages.

## How it works

Internally, the plugin subscribes to Composer script events:
- pre-install-cmd, pre-update-cmd: revertPatches
- post-install-cmd, post-update-cmd: applyPatches

It uses the magento-patches binary to retrieve status (JSON), revert --all, and apply the requested patch IDs. If the magento-patches binary is not found under Composer’s bin-dir, the plugin will fail with a clear message.

## Troubleshooting

- magento-patches binary not found
  - Ensure magento/quality-patches is installed and composer’s bin-dir (typically vendor/bin) contains magento-patches. Re-run composer install.

- Patch application fails
  - Re-run with -vvv for details.
  - Composer stops on failures by default; set extra.composer-exit-on-magento-patch-failure to false to let it continue instead.
  - Check for local changes conflicting with patches. Consider reverting changes or adjusting ignore/apply lists.

- No patches applied
  - Verify extra.magento-patches.apply is set and not empty. Use "all" to apply all not-yet-applied patches.

## Support

- If you have any issue with this code, feel free to open an issue on your project tracker or contact Blackbird.
- Contributions are welcome. Please open a pull request.

## Contact

For further information, contact Blackbird:
- by email: hello@bird.eu
- by form: https://black.bird.eu/contacts/

## Authors

From Blackbird Team (https://github.com/blackbird-agency)

## License

This project is licensed under the MIT License - see the LICENSE file for details.

That's all folks!
