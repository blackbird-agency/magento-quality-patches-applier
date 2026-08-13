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

## Setup

### Get the package

Composer Package:

```
composer require blackbird/magento-quality-patches-applier
```

This will also install magento/quality-patches if it is not already present.

### Configure patches to apply

In your project composer.json, define the patches you want in the extra.magento-patches.apply section. You can also list patches to ignore.

Example:

```
{
  "extra": {
    "magento-patches": {
      "auto-install-required-patches": true,
      "apply": [
        "AC-1234",
        "MC-5678"
      ],
      "ignore": [
        "MDVA-99999"
      ]
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
- Set environment variable COMPOSER_EXIT_ON_MAGENTO_PATCH_FAILURE=1 (or add extra.composer-exit-on-magento-patch-failure: true) to make Composer fail if patch application fails.

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
  - Set COMPOSER_EXIT_ON_MAGENTO_PATCH_FAILURE=1 to let Composer stop on failures.
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
