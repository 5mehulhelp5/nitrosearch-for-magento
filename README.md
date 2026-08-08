# NitroSearch for Magento

Instant, typo-tolerant search for **Magento Open Source**, served by a dedicated hosted engine
instead of your own server.

> **Status: in development.** No release is published yet, and no Magento store can connect. The
> verify route, settings layer and module skeleton work against Magento 2.4.8; catalogue sync and
> the storefront widget are being built.

## What this is, and what it is not

Magento already ships a real search engine — OpenSearch has been a hard install requirement since
2.4.0, and there has been no MySQL catalogue search since. So this is **not** the "your search is a
slow `LIKE` scan" story that applies to some other platforms, and we will not tell it here.

What NitroSearch offers a Magento merchant is narrower and honest:

- **Relevance quality** — typo tolerance, synonyms and ranking tuned for retail, without running a
  relevance project yourself.
- **Flat pricing** — no per-search metering, and going over your limit never breaks search.
- **EU hosting** — the engine and the index live in the EU.
- **Zero ops** — no OpenSearch cluster of your own to size, patch, monitor or pay for.

If you are happily running Smile ElasticSuite, you are running something good. The difference here
is hosted-and-managed versus self-run, not a feature list.

**Magento Open Source only.** Adobe Commerce and Commerce Cloud are out of scope.

## Requirements

| | |
|---|---|
| Magento | Open Source 2.4.6 – 2.4.9 |
| PHP | 8.1 – 8.5, matching your Magento version's own supported range |
| Search backend | whatever your Magento already uses — this module does not change it |

## Installing

Distribution is **Composer and GitHub only** — deliberately, not the Adobe Commerce Marketplace.
Composer is the one distribution route for this platform with no reviewer queue, no listing fee and
no revenue share, and none of that is required to install a module this way.

```bash
composer require nitrosearch/magento2-search
bin/magento module:enable NitroSearch_Search
bin/magento setup:upgrade
bin/magento setup:di:compile        # required in production mode
bin/magento cache:flush
```

Then open **Stores → Configuration → Services → NitroSearch**, choose the store view to index,
and press Connect. That is the whole setup — connecting installs the change-detection triggers for
you, and every `setup:upgrade` afterwards checks they are still there.

### Things that catch people out

- **`setup:di:compile` is not optional in production mode.** Constructor-injected classes fatal
  without it. It is an install step, not a troubleshooting step.
- **Composer 2.2+ requires plugins to be allow-listed.** A stock Magento root `composer.json`
  already carries `"magento/*": true`, so this usually needs no action — but a pruned root file
  will need it added.
- **Uninstalling: disconnect first.** Pressing Disconnect removes the database triggers as well as
  your credentials. `composer remove` plus `setup:upgrade` drops this module's tables through
  declarative schema, but **triggers are not schema** — so a module removed while still connected
  leaves triggers writing into a table that no longer exists. If you have already removed it, or
  prefer the command line: `bin/magento nitrosearch:unsubscribe`.

## How it keeps your catalogue in step

Every other platform this project connects hangs its change detection off a "product was saved"
event. **On Magento that is quietly wrong**, and quietly wrong is worse than loudly broken — a
save-hook module does not fail, it drifts. None of these changes what a shopper sees by saving a
product:

- a catalog price rule applying from cron
- tier prices or customer-group prices written by mass tooling
- stock going out from pending orders (MSI reservations)
- `bin/magento import`, direct SQL, restores, mass actions
- category re-assignment, website re-assignment, store-view value edits

So this module declares **its own Mview view** and subscribes to the same tables Magento's own
catalogue search index subscribes to. Magento's `indexer_update_all_views` cron then drives it every
minute, and trims our changelog for us. It is Magento's own answer to "what changes what a search
index must show", inherited rather than re-derived.

Three things that mechanism still cannot see, stated plainly because you should know them, and
because the third was found by measuring rather than assuming:

- **MSI reservations.** Magento core does not track them through Mview either; a product going out
  of stock purely because of pending orders surfaces on the next stock-status recompute.
- **Writes that bypass triggers** — `TRUNCATE`, `LOAD DATA`, and replication-applied statements in
  some configurations.
- **A *full* catalog-price-rule reindex.** Incremental rule activity is caught — deleting rule
  prices for three products put all thirty-six affected rows in our changelog. But a full
  `indexer:reindex catalogrule_rule` restored those same rows and our changelog saw **nothing**,
  because that path builds through a temporary table and a trigger cannot see a write that never
  touches the live one. We catch most rule activity, not all of it, and we would rather say so than
  let you find out from a stale price.

None of the three is the correctness argument. **The periodic full walk is**, exactly as on the other
connectors: it re-sends the whole catalogue on a schedule and does not depend on any signal firing.

## Two clocks, and why they cannot be one

The module runs two jobs on your cron, both every five minutes, and they decide between
themselves which is actually due:

| | Interval | What it does |
|---|---|---|
| Poll | 5 minutes | asks whether we should re-send your catalogue, and picks up plan changes |
| Key refresh | 24 hours | fetches a new storefront search key before the current one expires |

**They are separate because the status call carries no key.** A store that only polled would
hold the key it was issued at setup until that key expired — at which point storefront search
would quietly return nothing while this screen still said "connected". That is the failure this
design exists to prevent, and it is invisible from every screen you would think to check.

**The heartbeat is not gated on having anything to sync.** An empty queue is what a healthy,
settled catalogue looks like — and that is exactly the store whose key would otherwise expire
unnoticed.

To see both clocks at any time:

```bash
bin/magento nitrosearch:clocks
```

## Search-attributed revenue

When a shopper adds something to their basket from NitroSearch results, the module notes it
against their session. When they place the order, the value of just those items is reported so
your dashboard can show what search actually earned.

**What leaves your store:** a value, a currency, an opaque reference, the ids of the items that
came from a search, and the term that led to them. **What never leaves:** the real order id (it is
hashed with your install id first), the customer, the address, the payment, and anything the
shopper did not search for.

**Reporting never happens during checkout.** The report is queued and sent by cron minutes later.
A checkout must never be slowed — and must certainly never fail — because a search API was briefly
unreachable.

Turning off *Share anonymous search data* stops this entirely, along with everything else.

**What is counted, precisely.** Only the lines a shopper reached through search, at what they
actually paid for them — discounts subtracted, shipping and tax excluded. A configurable product
counts once, at the parent line that carries the price, never twice. Anything else in the same
basket is not counted, however it got there.

## What is indexed, and one thing that is not

Each product sends its name, SKU, price, stock status, image, description, categories, brand
(where you use `manufacturer`), sale status, and — for a configurable — every variation's SKU,
price, stock and option values, so a shopper can search a variation code and find the parent.

**Popularity is not sent.** Magento Open Source has no ordering signal available without the
reports aggregation cron, and a store that has never run it would report every product as equally
unpopular — a silent zero that looks like data. Relevance is name, SKU, description, categories
and brand.

**Stock comes from the stock your website is assigned to.** With Multi-Source Inventory that is
the stock resolved from your website's sales channel; without it, Magento's own stock index.

## A note on caching

Magento always has a full page cache, and your search key is renewed periodically. Pages carrying
NitroSearch's configuration are tagged, so renewing the key re-renders exactly those pages and
nothing else — we never flush your whole cache.

If search ever stops working on a store the admin says is connected, the usual cause is a cached
page holding a key that has since been renewed:

```bash
bin/magento nitrosearch:cache-invalidate
```

That clears only the affected pages. Reach for it before `cache:flush`, which on a busy store sends
every visitor to your PHP workers at once.

## Support

Issues and questions: <https://github.com/NitroSearch/nitrosearch-for-magento/issues>

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).
