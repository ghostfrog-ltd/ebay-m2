<?php

declare(strict_types=1);

namespace GhostFrog\EbayLedger\Model\Privacy;

/**
 * Removes other people's personal details from eBay payloads before Ledger stores them.
 *
 * The eBay keyset is declared as not persisting eBay user data, so names, usernames, addresses, emails and phone
 * numbers of buyers and sellers never reach Ledger's tables. Order, line and item IDs and amounts are kept.
 */
final class Redactor
{
    private const KEYS = [
        'name', 'street', 'street1', 'street2', 'cityname', 'postalcode', 'stateorprovince', 'country',
        'countryname', 'companyname', 'staticalias', 'buyercheckoutmessage', 'username', 'fullname',
        'firstname', 'lastname', 'contactaddress', 'recipient', 'taxidentifier', 'buyerregistrationaddress',
        'payee', 'payer', 'location', 'sellerinfo', 'buyerinfo',
    ];
    private const PATTERN = '/address|email|userid|phone|eiastoken|username|firstname|lastname|buyerinfo|shipto|recipient/i';

    public static function clean(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && (in_array(strtolower(ltrim($key, '@')), self::KEYS, true)
                || preg_match(self::PATTERN, $key))) {
                continue;
            }
            $clean[$key] = self::clean($value);
        }
        return $clean;
    }
}
