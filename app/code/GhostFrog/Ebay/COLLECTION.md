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

## Listing enrichment

New listings enqueue their MySQL listing ID in Magento's
`ghostfrog.ebay.enrichment` queue in the same transaction that saves the listing.
A refresh of an existing listing uses the same ID and enqueues no second job.

Magento cron checks the queue every five minutes. To let it process jobs,
enable **Enable Attribute Backfill** and **Allow eBay API Calls**, and configure
the **Trading API Token**. The **Attribute Backfill Limit** bounds each batch.
The **Trading API Daily Call Budget** caps recorded Trading requests in Magento
per UTC day. Set it to the allowance available to Magento if another service
shares the eBay application. eBay quota and authentication responses pause the
worker and leave queued items eligible for retry.

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
