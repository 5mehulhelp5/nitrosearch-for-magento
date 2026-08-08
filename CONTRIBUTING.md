# Contributing

Thanks for looking. Bug reports and pull requests are welcome.

## The one structural rule

**`lib/` is shared with the other NitroSearch connectors and is vendored byte-identically.**

```
lib/                  framework-free PHP shared with every NitroSearch connector
vendor-contract/      the wire contract kit — ItemBuilder, Money, Batch
Model/ Block/ Cron/   Magento's half: everything that touches a Magento class
Observer/ Console/
etc/                  module configuration, layout, schema
bin/build-module.sh   validates the package and produces the release archive
```

Nothing in `lib/` may reference a Magento class, constant or base class — anything it
needs is passed in. **A change made in `lib/` is a change to four connectors**, so it is
either made in the shared core and re-vendored everywhere, or it does not belong there.

There is one scar behind that rule. `lib/Api/Client.php` once carried
`'platform' => 'opencart'` as a literal, inherited when this module was started from the
OpenCart one. Every request signed correctly and succeeded; the module connected,
verified and synced — and the service registered a Magento store as an OpenCart one,
which would have served it the wrong storefront bundle. The only record of the mistake
was on the service's side. `bin/check-platform-slug.sh` now refuses a foreign slug, and
the general lesson is in the file: **a per-platform constant inside byte-identical shared
code is a contradiction that only holds while there is one consumer.**

## Before you open a pull request

```bash
php tests/run.php          # the framework-free unit cases
./bin/build-module.sh      # lints everything shipped, runs every guard, builds the archive
```

The build **derives** its guard list from `bin/check-*.sh` rather than naming them, so a
new guard is one file rather than one file and a line somebody forgets. Every guard
self-tests: it is made to fail on the exact defect it exists for, before the build
believes it about this tree. A guard that has quietly stopped discriminating is worse
than no guard, because it reads as coverage.

## What a change is expected to come with

- **A guard, if the change fixes a defect.** Made to fail on the exact thing it catches,
  with the failure written down in the guard's own header. Every guard in `bin/` names
  the defect it exists for.
- **A CHANGELOG entry** under `## [Unreleased]`, written for a merchant rather than for a
  reviewer.
- **A measurement rather than an argument**, where one is possible. Most of what this
  module has got wrong was invisible in code and obvious on a running store: a wire field
  that was never sent, an observer registered in an area Magento's checkout does not use,
  a cache purge that could not reach the edge. If a claim can be checked against a real
  Magento, check it.

## Testing against a real store

The maintainers develop against a scripted Magento sandbox. If you are working from
outside, a stock Magento Open Source 2.4.x with sample data is enough for everything
except the multi-store and Varnish paths, and `bin/magento nitrosearch:status` is the
first thing to read when something looks wrong — it reports what the module can actually
see, which `bin/magento config:show` cannot.

## Licence

By contributing you agree that your work is licensed under GPL-3.0-or-later, the same as
the rest of this repository.
