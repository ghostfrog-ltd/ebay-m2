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

## API budgets

Run Magento's `setup:upgrade` to install the API quota table. Under
**GhostFrog → eBay Configuration → eBay API**, set daily budgets for Trading,
Browse and OAuth client credentials. The defaults are 5,000, 5,000 and 1,000
attempts per UTC day. A budget of zero pauses that API. Assign Magento its own
share of the allowance when Python uses the same eBay application. Magento's
counters cover the calls made by this extension.

Magento checks and records an attempt before each HTTP request. For a local
budget stop, work becomes eligible again at 00:00 UTC. For a throttle reported
by eBay, Magento honors the `Retry-After` header when available and falls back to
00:00 UTC. Browse resumes on the next eligible collection run. An interrupted
source keeps its previous successful scrape time.

The following command shows local counts, budgets and pause times without
calling eBay:

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
transaction. A disabled stage is skipped. An unavailable Ollama endpoint leaves
the affected job pending for retry. Photos are fetched from eBay's image CDN
and sent as bytes to local Ollama.

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
daily so sales age out and later identity and condition changes are picked up.
The Magento index cron processes the refresh. To rebuild immediately:

    ddev php bin/magento indexer:reindex ghostfrog_ebay_comps

Eligible live listings show comps and pricing on the listing edit screen.
Apple requires 10 same-grade sales, Samsung and graphics cards require 5,
and other sources require 15. Change the GBP target profit, selling fee
percentage and combined shipping in **GhostFrog → eBay Configuration →
Comps and Pricing**. Pricing changes apply on the next listing view. The
formula also deducts £5 for packaging and reserve.


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
The queue retains the comp job until that write succeeds.
