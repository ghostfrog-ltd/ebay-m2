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
Model/Collection/SourceSearches.php. The collector captures Browse evidence
for downstream processing. Market-specific relevance filters, identity and
condition classification remain to be ported.
