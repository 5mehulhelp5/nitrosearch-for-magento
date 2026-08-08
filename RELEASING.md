# Releasing

This module is distributed by **Composer, from a git tag**. There is no marketplace listing
and no upload step: `composer require nitrosearch/magento2-search` resolves the tag and
downloads a dist archive GitHub builds from the tree, filtered by `.gitattributes`.

Versions follow [SemVer](https://semver.org/).

## The version lives in the tag, and nowhere else

`composer.json` deliberately declares **no** `version` field. Composer and Packagist take
the tag as authoritative, and a version written in two places drifts — `bin/check-composer.sh`
refuses a tree that declares one.

The practical consequence: **the tag IS the release.** Nothing else needs bumping.

## Cutting a release

1. **Move the `## [Unreleased]` entries** in `CHANGELOG.md` into a dated section for the new
   version, and update the compare links at the bottom.

2. **Validate:**

   ```bash
   php tests/run.php
   ./bin/build-module.sh
   ```

   The build lints every shipped file, validates the XML, runs each self-testing guard in
   `bin/`, and writes `dist/nitrosearch-magento-<version>.zip`. **That archive is for
   people, not for Composer** — it is attached to the GitHub release for anyone who wants
   to read or vendor the module without Composer. The validation is the real output.

3. **Install the PUBLISHED artifact into a Magento that has never seen the module.** Not
   the working copy, not a bind mount, not a hand-placed directory. This is where packaging
   fails and no test covers it: a working copy already sitting in place has put the files
   where they belong, so it cannot show you a mistake. `.gitattributes` decides what
   Composer actually ships, and an `export-ignore` that excludes one file too many produces
   a module that installs cleanly and fatals on first use.

   Verify against a real store, in this order:

   ```bash
   composer require nitrosearch/magento2-search
   bin/magento module:enable NitroSearch_Search
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento cache:flush
   bin/magento nitrosearch:status      # settings readable, triggers reported
   ```

   Then connect through **Stores → Configuration → Services → NitroSearch**, sync, and
   **open the storefront in a browser and search.** A green status command has never once
   been sufficient on this module: every defect that reached a release candidate here was
   invisible to it, and three of them were only visible in a browser.

4. **Tag and publish:**

   ```bash
   git tag -a 1.0.0 -m "1.0.0"
   git push origin 1.0.0
   gh release create 1.0.0 dist/nitrosearch-magento-1.0.0.zip \
       --title "1.0.0" --notes-file <(sed -n '/## \[1.0.0\]/,/^## /p' CHANGELOG.md)
   ```

   **The release title is the bare version, matching the tag exactly** — the backend's
   `bin/check-releases.sh` audits every NitroSearch repository for title-vs-tag drift and
   seven mis-titled releases had to be fixed once already.

   `gh release create` must run **inside the repository**; `--repo` with `--notes-from-tag`
   silently does the wrong thing.

5. **Confirm Packagist has the version.** Packagist is updated by a webhook on push; if the
   package page still shows the previous version a few minutes later, trigger an update
   from the package page rather than assuming.

## What a release claims

Tagging is what flips `hasConnector()` on the service, and that flag is a claim to
merchants that they can install this and it works. The bar is deliberately high and is
written down in the backend's `DECISIONS.md` under D-037: **proven from published assets
into a shop wiped to zero, all the way to live search.** Do not tag to "see if it works".
