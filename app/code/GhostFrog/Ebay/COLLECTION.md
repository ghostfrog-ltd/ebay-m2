# eBay listing collection

Magento cron checks enabled sources every minute. For each supported
sources domain, scrape_interval_seconds is the minimum gap after
last_scraped_at. A successful search and save updates that timestamp. The
scrape window in **GhostFrog → eBay Configuration → Scraping** applies across
sources. Its defaults are 07:00 to 23:00 Europe/London, with the end exclusive.

The collector uses Magento's configured MySQL connection and the module's
existing ghostfrog_ebay_* tables for sources, listings, price history, listing
statuses, and API usage. Source settings are in ghostfrog_ebay_sources.

Set the eBay Application ID and Certificate ID in Magento's eBay API settings.
Collection requires **Enabled**, **Enable Scraping**, and **Allow eBay API Calls**.

From the Magento root, preview the schedule in DDEV:

    ddev php bin/magento ghostfrog:ebay:collect --dry-run
    ddev php bin/magento ghostfrog:ebay:collect --source=ebay-apple --dry-run

Omit --dry-run to let the command run due sources. The command and cron share
Magento's lock and the same scheduling checks. Search settings are in
Model/Collection/SourceSearches.php. The collector captures Browse evidence for
downstream processing. Collection-specific relevance filters remain to be ported.

## Price precision

Run Magento's `setup:upgrade` to apply the declarative price changes before
collecting. Current prices, bids and price observations are stored as GBP with
two decimal places. Existing amounts keep their pound values. An old value of
420 becomes 420.00, and the next observed price can restore the actual pence.

## eBay API quota

Run Magento's `setup:upgrade` to apply the quota schema. Under **GhostFrog
→ eBay Configuration → eBay API**, the read-only Trading GetItem and Browse
Search fields show eBay's daily limit, the balance at lookup, Magento's
available balance, and the eBay reset time.

The first Trading or Browse job in each eBay quota window requests the current
limits and remaining calls from Developer Analytics. Magento saves each
observation in MySQL and reserves a credit before sending a request. If
Magento exhausts its balance or refresh reaches the discovery reserve while
the latest eBay observation indicated more capacity, a due job can trigger an
Analytics lookup. Pressure-triggered lookups are at least 15 minutes apart.
Once a lookup has recorded the balance at that threshold, affected jobs wait
until the provider reset. Discovery can continue using its reserved credits.

Magento deducts calls outstanding when the Analytics request starts and any
reservations made while it is running. A timeout with an uncertain outcome is
also held against the allowance. eBay documents aggregate application usage
without a freshness guarantee or per-request acknowledgement. Even an advancing
count can lag completed local calls. Within the same provider reset window, an
observation can lower the local balance. Only a verified new window can provide
fresh credits. Magento counts attempts by UTC date, while eBay counts against
its own reset window.

The issue #10 observation of 2,110 Browse calls remaining at eBay with zero
local credits cannot safely grant a top-up. Magento can show that discrepancy
and resume with the next verified window. A system that ran the earlier
reconciliation build should take this correction at a verified reset because
the stored balance cannot identify credits that build already restored.

If eBay throttles a client, Magento honours `Retry-After` when supplied and
otherwise uses the saved reset. A failed or partial lookup is retried after a
five-minute cooldown, subject to eBay's throttle. A partial response preserves
previously verified balances until their reset. An interrupted collection source
keeps its previous successful scrape time. Refresh authentication failures keep
their own one-hour cooldown.

Developer Analytics does not provide the OAuth token allowance here. Magento
logs token requests and applies the configured OAuth client-credentials budget.

The following command shows the provider balance and observation time,
Magento's available balance, the discovery reserve, the refresh-usable
balance, and the separate pause and retry reasons. Add `--live-ebay` to request
an eligible reconciliation when API calls are enabled.

    ddev php bin/magento ghostfrog:ebay:api-health

## Listing enrichment

New listings enqueue their MySQL listing ID in Magento's
`ghostfrog.ebay.enrichment` queue in the same transaction that saves the listing.
A refresh of an existing listing uses the same ID and enqueues no second job.

Magento cron checks the queue every five minutes. To let it process jobs,
enable **Enable Attribute Backfill** and **Allow eBay API Calls**, and configure
the **Trading API Token**. The **Attribute Backfill Limit** bounds each batch.
When Trading quota runs out, the worker leaves deliveries pending without
adding a retry attempt. The next scheduled run after the pause resumes the
queue. The local condition and model-key queues continue to process their own
pending work.

Enrichment fetches Trading GetItem evidence and stores the eBay item specifics,
brand, model, storage, colour and ePID when supplied unambiguously. Magento's message queue retention settings apply.

## Local LLM condition checks

Under **GhostFrog → eBay Configuration → Local LLM Condition Checks**,
enable text and image checking independently. Both start disabled. Set either
model to any supported local Ollama model. The default for both is
`qwen3.6:35b-a3b-coding-nvfp4`. The usual DDEV connection is
`http://host.docker.internal:11434`. Outside DDEV it is
`http://127.0.0.1:11434`. The base URL setting accepts those local hosts
and localhost.

The listing proceeds from Trading GetItem to the enabled text and image stages,
then to model-key generation. Each handoff and its saved result share a MySQL
transaction. Condition cron runs every minute. Each enabled stage can claim up
to **Condition Batch Size** listings per run (50 by default). It sends up to
**Concurrent Ollama Text Requests** (2 by default) or **Concurrent Ollama Image
Requests** (1 by default) at once. Each stage starts new groups of requests for
up to 25 seconds, and lets the last group finish within the existing HTTP
timeouts. Ollama's parallel capacity determines the actual throughput. Photos
are fetched from eBay's image CDN and sent as bytes to local Ollama.

A disabled stage advances up to 500 queued listings per run without downloads
or inference. An unavailable Ollama endpoint leaves the affected deliveries
pending and pauses that stage for the remainder of the run. Each stage with
work logs a batch summary in `var/log/system.log` with completions per minute
of active processing, failures and deferrals as a percentage of deliveries
claimed, remaining queue depth, and seconds since the oldest waiting delivery's
last status change. A retry starts a new wait.

Text and image results are recorded separately in the module's condition
tables. The final condition grade uses the worse supported evidence. Sparse or
uncertain evidence stays in review. The condition stages make no Trading calls.

## Model keys

Successful enrichment records completion and queues the same listing ID for
model-key generation. Magento checks the model-key queue every five minutes.
Key generation uses the stored title and item specifics.

The PHP classifier covers all 20 collection sources. It ports the existing
Python identity rules and adds a Retro PC rule for identifiable graphics cards,
sound cards, and processors. Incomplete or conflicting evidence produces `unknown`.
Generic condition grades belong in `condition_grade`. MotoMine keys have their
own prefix so that parts and complete motorcycles use separate identities.
Existing assigned keys are preserved.

## Sold comparables

The GhostFrog eBay Comps indexer rebuilds the Magento comps table from the
last 365 days of finalized sales with a positive final price. It groups by
verified model key and effective condition grade, keeps one sale per eBay
item and stores the median, mean and comp count. It reads the listing data
available in Magento. PostgreSQL remains the live source of truth. Imported
listings need fresh sale outcomes in Magento for the index to include them.

With the extension enabled, Magento cron marks the index for a full refresh
daily so sales age out and missed changes are reconciled. The index keeps a
record of the selected sale for each eBay item. A listing change recalculates
only the cohorts whose selected sales changed. A sale can leave a cohort when
its identity, condition or eligibility changes, or when the listing is deleted.
Another stored representation of the same eBay item can then become the
selected sale. Magento updates the selected sales, comp statistics and
affected opportunities in one database transaction.

## Opportunities

**GhostFrog → Opportunities** shows live listings from the
`ghostfrog_ebay_opportunities` index, sorted by expected profit. Use the
filters or row actions to find and inspect a listing. The current price for
an auction is the observed bid when available. The final purchase price can
be higher.

The index uses the same eligibility and pricing services as the listing edit
screen. Listings must be live, within their end time when applicable, and seen
within the past 24 hours. Apple requires 10 same-grade sales, Samsung and
graphics cards require 5, and other sources require 15. Set the GBP target
profit, selling fee percentage and combined shipping in **GhostFrog →
eBay Configuration → Comps and Pricing**. The estimate also deducts £5 for
packaging and reserve. Saving pricing in Magento Admin or with the default
`config:set` command invalidates Opportunities. Rebuild Opportunities after
deploying pricing overrides in files or environment variables.

The upgrade patch enables **Update by Schedule** for both GhostFrog indexers
and builds the initial indexes from local listing data. Keep both indexers in
scheduled mode and run the Magento index cron. The materialized views capture
listing writes, condition assessments and review actions in the same
transaction as those changes. Comparable changes immediately refresh their
affected opportunities when the comps transaction commits. Every five
minutes the expiry job rechecks indexed opportunities whose end time or
24-hour freshness deadline passed. With the extension enabled, the daily
03:10 Magento cron job queues a full rebuild of both indexes.

Run the upgrade and check the native indexer status during deployment.

    ddev exec php bin/magento setup:upgrade
    ddev exec php bin/magento indexer:status ghostfrog_ebay_comps ghostfrog_ebay_opportunities

Run both indexers directly for an immediate reconciliation.

    ddev exec php bin/magento indexer:reindex ghostfrog_ebay_comps ghostfrog_ebay_opportunities

The unit tests run from the Magento root. Run the database tests from a
disposable Magento installation with the module enabled and upgraded, and
an empty listing table. The tests use Magento's materialized views and UI
data source, exercise sale and listing changes, compare full and incremental
results, and inject a database write failure to verify rollback and retry.

    ddev exec vendor/bin/phpunit --no-extensions -c dev/tests/unit/phpunit.xml.dist app/code/GhostFrog/Ebay/Test/Unit
    GHOSTFROG_EBAY_TEST_DB=disposable_database vendor/bin/phpunit --bootstrap app/bootstrap.php app/code/GhostFrog/Ebay/Test/Integration

## Final sale prices

After applying the declarative queue schema with `setup:upgrade`, turn on
**GhostFrog → eBay Configuration → Heartbeat → Enable Ended Listing Processing**.
The extension must also be enabled. The worker needs **Allow eBay API Calls**
and a Trading API token.

The enqueue cron checks every five minutes for unpriced listings between
30 minutes and 90 days past their stored end time. A separate worker handles up
to 20 due jobs every five minutes with the existing Trading API quota. It
restores active listings only when eBay returns a future end time. Temporary
errors retry with backoff. An inaccessible item keeps its local evidence and
has an unconfirmed outcome unless it was already marked ended.

Only sales with a usable GBP price can contribute to comps. Auctions require
a reported sale, bids and confirmation that the reserve was met or absent.
Fixed-price checks exclude ambiguous offers, revised prices, multi-unit and
variation sales. A confirmed ending without a verifiable price stays ended.
After recording a sale, the worker rebuilds its same-model, same-grade comp
group and any group affected by another stored ID for the same eBay item.
The queue retains the comp job until the comp and opportunity writes succeed,
so the worker can retry indexing from the saved sale.

## Opportunity price monitoring and alerts

Run `setup:upgrade` after deploying. Magento cron runs the
`ghostfrog_ebay_monitoring` group every minute. It adds due indexed listing
IDs to a dedicated MySQL queue, then checks those listings through Browse
GetItem. The module and **Allow eBay API Calls** must be enabled. In
**GhostFrog → eBay Configuration → Opportunity Price Refresh and Alerts**,
set the intervals and batch limits. Auctions with more than 24 hours left
follow normal collection. Auctions within 24 hours, 60 minutes and 10 minutes
default to 30, 5 and 1 minute respectively. Buy It Now defaults to 5 minutes.
Combined listings keep both prices, use the shorter interval and are valued
in the grid using the lower available price. The grid provides optional
separate Auction Price and Buy It Now Price columns.

The worker prioritises auctions ending within ten minutes and reserves a slot
for other due listings. It shares the Browse quota with collection and leaves
20% of the verified daily limit for searches. It waits through quota and
authentication pauses and retries transient errors with backoff. The
observation, price history, individual opportunity index update and alert
handoff share a database transaction. An unavailable item leaves the
Opportunities grid with its sale outcome unconfirmed.

**Last Price Checked** records the most recent successful individual check.
**Price Freshness** shows whether the displayed price still matches that check
and is within its interval. A later collection observation can supersede the
checked price. Both fields are available in the grid without making API calls.

For alerts, enter an incoming **Webhook URL** under **Slack**, then enable
**Send Opportunity Alerts to Slack** under **Opportunity Price Refresh and
Alerts**. The webhook selects the Slack channel. Alerts start disabled and
require a destination. Buy It Now alerts queue on successful checks. Auction
alerts require a successful check within the final five minutes and a price
less than 60 seconds old by default. Delivery rechecks eligibility and prices
with current comps. Each purchase option can alert once while it remains
actionable. Explicit temporary Slack failures retry with backoff. Interrupted
requests with an unknown delivery outcome are recorded without automatic
replay. The cron log reports refresh queue depth, oldest due time, stale
opportunities, quota pauses and alert outcomes in `var/log/system.log`.

Run the monitoring cron group in DDEV to check registration and execution.

    ddev exec php bin/magento cron:run --group=ghostfrog_ebay_monitoring

### Stale Apple auction checks

The optional **Check Stale Near-Close Apple Auctions** setting defaults to
off. After `setup:upgrade`, the same monitoring cron can check stale Apple
auctions missing from the active Opportunities index. It uses the current
eligibility rules, effective grade, sold comps and configured profit target.
Combined listings need a stored auction bid. Candidates are ranked within a
bounded, rotating scan by time remaining and indicative profit at that bid.
Only the usual indexer and alert rules act on the fresh observation.

The pilot defaults to a 24-hour window, five candidates per run and 100
Browse attempts per verified eBay quota window. Due opportunities go first.
Candidate checks preserve the discovery reserve plus one regular refresh
batch of headroom. A listing gets one candidate dispatch. Quota and
authentication deferrals leave it eligible for a later check, while any HTTP
request sent still counts against the Browse budget. A staged observation can
retry a failed index transaction without another request. Once indexed, the
listing follows regular opportunity monitoring.

Preview the next selection and see the exclusion counts and current quota
without enqueuing work or contacting eBay. The preview works with the feature
disabled. Batch outcomes and Browse attempt counts appear in `var/log/system.log`.

    ddev exec php bin/magento ghostfrog:ebay:opportunities:preview-auctions
